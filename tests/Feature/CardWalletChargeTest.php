<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Topping up the wallet by card-to-card: nothing is credited until an admin has
 * confirmed the receipt.
 */
class CardWalletChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'services.card_to_card.number' => '6037-0000-0000-0000',
            'services.card_to_card.holder' => 'گیم ورلد',
            'services.card_to_card.bank' => 'ملی',
            'services.card_to_card.payment_hours' => 24,
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create();
    }

    private function openCharge(int $amount = 1000000): Payment
    {
        $id = $this->actingAs($this->customer)
            ->postJson(route('api.wallet.charge'), ['amount' => $amount, 'method' => 'card_to_card'])
            ->assertCreated()
            ->json('data.id');

        return Payment::findOrFail($id);
    }

    private function uploadReceipt(Payment $charge, ?UploadedFile $file = null, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->customer)->post(
            route('api.wallet.charges.receipt', $charge->id),
            ['receipt' => $file ?? UploadedFile::fake()->image('receipt.jpg', 400, 600)],
            ['Accept' => 'application/json']
        );
    }

    private function approve(Payment $charge): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(route('api.admin.wallet-charges.approve', $charge->id), ['deposit_confirmed' => true]);
    }

    private function reject(Payment $charge, string $reason = 'رسید خوانا نیست.'): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(route('api.admin.wallet-charges.reject', $charge->id), ['reason' => $reason]);
    }

    private function walletBalance(): float
    {
        return (float) $this->customer->wallet()->value('balance');
    }

    public function test_opening_a_charge_shows_the_card_and_credits_nothing(): void
    {
        $response = $this->actingAs($this->customer)
            ->postJson(route('api.wallet.charge'), ['amount' => 1000000, 'method' => 'card_to_card'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'card_to_card')
            ->assertJsonPath('data.status', 'awaiting_receipt')
            ->assertJsonPath('data.amount', 1000000)
            ->assertJsonPath('data.card.number', '6037-0000-0000-0000')
            ->assertJsonPath('data.can_upload_receipt', true)
            ->assertJsonPath('data.receipt_url', null);

        $this->assertNotNull($response->json('data.due_at'));
        $this->assertSame(PaymentMethod::CardToCard, Payment::sole()->method);
        $this->assertEquals(0, $this->walletBalance());
    }

    public function test_the_amount_limits_still_apply(): void
    {
        foreach ([49999, 50000001, 0, -5] as $amount) {
            $this->actingAs($this->customer)
                ->postJson(route('api.wallet.charge'), ['amount' => $amount, 'method' => 'card_to_card'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_opening_a_charge_is_refused_when_no_card_is_set(): void
    {
        config(['services.card_to_card.number' => null]);

        $this->actingAs($this->customer)
            ->postJson(route('api.wallet.charge'), ['amount' => 1000000, 'method' => 'card_to_card'])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'پرداخت کارت به کارت در حال حاضر فعال نیست.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_gateway_stays_the_default_and_unknown_methods_are_refused(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('api.wallet.charge'), ['amount' => 1000000])
            ->assertOk()
            ->assertJsonPath('data.method', 'gateway');

        $this->actingAs($this->customer)
            ->postJson(route('api.wallet.charge'), ['amount' => 1000000, 'payment_method' => 'card_to_card'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'card_to_card');

        $this->actingAs($this->customer)
            ->postJson(route('api.wallet.charge'), ['amount' => 1000000, 'method' => 'wallet'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');
    }

    public function test_receipt_goes_on_the_private_disk_and_alerts_admins(): void
    {
        $charge = $this->openCharge();

        $this->uploadReceipt($charge)
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.can_upload_receipt', false);

        $this->assertCount(1, Storage::disk('local')->allFiles('receipts'));
        $this->assertSame([], Storage::disk('public')->allFiles());

        $alert = $this->admin->notifications()->sole()->data;
        $this->assertSame('receipt_submitted', $alert['type']);
        $this->assertSame('wallet_charge', $alert['purpose']);
        $this->assertSame('/admin/wallet-charges', $alert['link']);
        $this->assertEquals(0, $this->walletBalance());
    }

    public function test_receipt_opens_through_its_signed_link_only(): void
    {
        $charge = $this->openCharge();
        $url = $this->uploadReceipt($charge)->json('data.receipt_url');

        $this->app['auth']->forgetGuards();

        $this->get($url)->assertOk();
        $this->get(route('api.receipts.show', $charge))->assertForbidden();
    }

    public function test_only_images_are_accepted_as_receipts(): void
    {
        $charge = $this->openCharge();

        foreach ([
            UploadedFile::fake()->create('shell.php', 1, 'application/x-php'),
            UploadedFile::fake()->create('statement.pdf', 1, 'application/pdf'),
            UploadedFile::fake()->image('huge.jpg')->size(6000),
        ] as $file) {
            $this->uploadReceipt($charge, $file)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('receipt');
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_customers_only_reach_their_own_charges(): void
    {
        $charge = $this->openCharge();

        $this->uploadReceipt($charge, user: User::factory()->create())->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs(User::factory()->create())
            ->getJson(route('api.wallet.charges'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->customer)
            ->getJson(route('api.wallet.charges'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $charge->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_charge_list_only_shows_card_wallet_charges(): void
    {
        $charge = $this->openCharge();

        $this->actingAs($this->customer)->postJson(route('api.wallet.charge'), ['amount' => 1000000])->assertOk();

        $order = Order::create(['user_id' => $this->customer->id, 'console_type' => 'PS5', 'total' => 1000, 'status' => 'awaiting_payment', 'payment_status' => 'pending']);
        Payment::create(['user_id' => $this->customer->id, 'order_id' => $order->id, 'amount' => 1000, 'method' => PaymentMethod::CardToCard, 'status' => PaymentStatus::Pending]);

        $this->actingAs($this->customer)
            ->getJson(route('api.wallet.charges'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $charge->id);
    }

    public function test_a_second_receipt_is_refused(): void
    {
        $charge = $this->openCharge();
        $this->uploadReceipt($charge)->assertOk();

        $this->uploadReceipt($charge)->assertUnprocessable();

        $this->assertCount(1, Storage::disk('local')->allFiles('receipts'));
    }

    public function test_approving_credits_the_wallet_exactly_once(): void
    {
        $charge = $this->openCharge(750000);
        $this->uploadReceipt($charge)->assertOk();

        $this->approve($charge)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.card', null)
            ->assertJsonPath('data.reviewed_by.id', $this->admin->id)
            ->assertJsonPath('data.user.id', $this->customer->id);

        $this->assertEquals(750000, $this->walletBalance());
        $this->assertNotNull($charge->fresh()->paid_at);

        $this->approve($charge)->assertUnprocessable();
        $this->reject($charge)->assertUnprocessable();

        $this->assertEquals(750000, $this->walletBalance());
        $this->assertSame(1, $this->customer->wallet->transactions()->count());

        $this->assertContains('wallet_charged', $this->customer->notifications()->get()->pluck('data.type'));

        $this->actingAs($this->customer)
            ->getJson(route('api.wallet.transactions'))
            ->assertOk()
            ->assertJsonPath('data.data.0.category', 'deposit')
            ->assertJsonPath('data.data.0.type', 'credit');
    }

    public function test_approving_needs_the_admin_to_state_they_saw_the_deposit(): void
    {
        $charge = $this->openCharge();
        $this->uploadReceipt($charge)->assertOk();

        foreach ([[], ['deposit_confirmed' => false]] as $body) {
            $this->actingAs($this->admin)
                ->postJson(route('api.admin.wallet-charges.approve', $charge->id), $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('deposit_confirmed');
        }

        $this->assertEquals(0, $this->walletBalance());
    }

    public function test_rejecting_credits_nothing_and_needs_a_new_charge(): void
    {
        $charge = $this->openCharge();
        $this->uploadReceipt($charge)->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.wallet-charges.reject', $charge->id), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->reject($charge, 'مبلغ رسید درست نیست.')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'مبلغ رسید درست نیست.');

        $this->assertEquals(0, $this->walletBalance());

        $notification = $this->customer->notifications()->sole()->data;
        $this->assertSame('wallet_charge_rejected', $notification['type']);
        $this->assertSame('مبلغ رسید درست نیست.', $notification['reason']);

        $this->uploadReceipt($charge)->assertUnprocessable();
        $this->approve($charge)->assertUnprocessable();

        $this->assertCount(1, Storage::disk('local')->allFiles('receipts'));
    }

    public function test_a_charge_with_no_receipt_cannot_be_decided_on(): void
    {
        $charge = $this->openCharge();

        $this->approve($charge)->assertUnprocessable();
        $this->reject($charge)->assertUnprocessable();

        $this->assertEquals(0, $this->walletBalance());
    }

    public function test_only_admins_can_review_charges(): void
    {
        $charge = $this->openCharge();
        $this->uploadReceipt($charge)->assertOk();

        $this->actingAs($this->customer)->getJson(route('api.admin.wallet-charges'))->assertForbidden();
        $this->actingAs($this->customer)->postJson(route('api.admin.wallet-charges.approve', $charge->id), ['deposit_confirmed' => true])->assertForbidden();
        $this->actingAs($this->customer)->postJson(route('api.admin.wallet-charges.reject', $charge->id), ['reason' => 'x'])->assertForbidden();

        $this->assertEquals(0, $this->walletBalance());
    }

    public function test_charge_endpoints_do_not_touch_order_or_gateway_payments(): void
    {
        $order = Order::create(['user_id' => $this->customer->id, 'console_type' => 'PS5', 'total' => 1000, 'status' => 'awaiting_payment', 'payment_status' => 'pending']);
        $orderPayment = Payment::create(['user_id' => $this->customer->id, 'order_id' => $order->id, 'amount' => 1000, 'method' => PaymentMethod::CardToCard, 'status' => PaymentStatus::Pending, 'receipt_path' => 'receipts/a.jpg']);
        $gatewayPayment = Payment::create(['user_id' => $this->customer->id, 'amount' => 1000, 'method' => PaymentMethod::Gateway, 'status' => PaymentStatus::Pending, 'receipt_path' => 'receipts/b.jpg']);

        foreach ([$orderPayment, $gatewayPayment] as $payment) {
            $this->approve($payment)->assertNotFound();
            $this->reject($payment)->assertNotFound();
            $this->uploadReceipt($payment)->assertNotFound();
        }

        $this->assertEquals(0, $this->walletBalance());
        $this->assertSame(PaymentStatus::Pending, $orderPayment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $gatewayPayment->fresh()->status);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/wallet-charges/abc/approve', ['deposit_confirmed' => true])
            ->assertNotFound();
    }

    public function test_a_charge_cannot_be_completed_through_the_gateway_callback(): void
    {
        $charge = $this->openCharge();
        $this->uploadReceipt($charge)->assertOk();

        $this->app['auth']->forgetGuards();

        $this->getJson(route('api.wallet.callback', ['payment' => $charge->id, 'transaction_id' => 'anything']))->assertUnprocessable();
        $this->getJson(route('api.wallet.callback', ['payment' => $charge->id, 'transaction_id' => 'anything', 'status' => 'NOK']))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $charge->fresh()->status);
        $this->assertEquals(0, $this->walletBalance());
    }

    public function test_admin_can_list_charges_and_see_the_count(): void
    {
        $inReview = $this->openCharge();
        $this->uploadReceipt($inReview)->assertOk();
        $this->openCharge();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.wallet-charges', ['status' => 'in_review']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inReview->id)
            ->assertJsonPath('data.0.user.id', $this->customer->id)
            ->assertJsonPath('data.0.duplicate_order_ids', [])
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.wallet-charges'))
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.wallet-charges', ['user_id' => $this->admin->id]))
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.dashboard-stats'))
            ->assertJsonPath('data.walletChargesInReview', 1);
    }

    public function test_the_same_receipt_is_flagged_across_charges_and_orders(): void
    {
        $image = UploadedFile::fake()->image('r.jpg', 300, 300);
        $content = file_get_contents($image->getRealPath());
        $sameFile = fn () => UploadedFile::fake()->createWithContent('receipt.jpg', $content);

        $firstCharge = $this->openCharge();
        $this->uploadReceipt($firstCharge, $sameFile())->assertOk();

        $secondCharge = $this->openCharge();
        $this->uploadReceipt($secondCharge, $sameFile())->assertOk();

        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $variant = Game::create(['title' => 'GTA V', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 1000000, 'size' => 50, 'is_available' => true]);
        $orderId = $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'card_to_card', 'items' => [['variantId' => $variant->id]]])
            ->assertCreated()
            ->json('data.order_id');
        $this->actingAs($this->customer)
            ->post(route('api.orders.receipt', $orderId), ['receipt' => $sameFile()], ['Accept' => 'application/json'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders.show', $orderId))
            ->assertJsonPath('data.receipts.0.duplicate_order_ids', [])
            ->assertJsonPath('data.receipts.0.duplicate_wallet_charge_ids', [$firstCharge->id, $secondCharge->id]);

        $rows = collect($this->actingAs($this->admin)->getJson(route('api.admin.wallet-charges'))->assertOk()->json('data'))->keyBy('id');

        $this->assertSame([$orderId], $rows[$firstCharge->id]['duplicate_order_ids']);
        $this->assertSame([$secondCharge->id], $rows[$firstCharge->id]['duplicate_wallet_charge_ids']);
    }

    public function test_charges_with_no_receipt_expire_but_those_under_review_stay(): void
    {
        $abandoned = $this->openCharge();
        $inReview = $this->openCharge();
        $this->uploadReceipt($inReview)->assertOk();

        $this->travel(23)->hours();
        $this->artisan('payments:expire-pending')->assertSuccessful();
        $this->assertSame(PaymentStatus::Pending, $abandoned->fresh()->status);

        $this->travel(2)->hours();
        $this->artisan('payments:expire-pending')->assertSuccessful();

        $this->assertSame(PaymentStatus::Failed, $abandoned->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $inReview->fresh()->status);

        $this->actingAs($this->customer)
            ->getJson(route('api.wallet.charges'))
            ->assertJsonPath('data.1.status', 'cancelled');

        $this->uploadReceipt($abandoned)->assertUnprocessable();
    }
}
