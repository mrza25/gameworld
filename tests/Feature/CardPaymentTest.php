<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\OrderStatus;
use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private GameVariant $variant;

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
        $this->customer->wallet()->create(['balance' => 100]);

        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->variant = Game::create(['title' => 'GTA V', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 1000000, 'size' => 50, 'is_available' => true]);
    }

    private function placeOrder(): Order
    {
        $orderId = $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'card_to_card', 'items' => [['variantId' => $this->variant->id]]])
            ->assertCreated()
            ->json('data.order_id');

        return Order::findOrFail($orderId);
    }

    private function uploadReceipt(Order $order, ?UploadedFile $file = null, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->customer)->post(
            route('api.orders.receipt', $order->id),
            ['receipt' => $file ?? UploadedFile::fake()->image('receipt.jpg', 400, 600)],
            ['Accept' => 'application/json']
        );
    }

    private function approve(Order $order): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(route('api.admin.orders.receipt.approve', $order->id), ['deposit_confirmed' => true]);
    }

    private function reject(Order $order, string $reason = 'رسید خوانا نیست.'): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(route('api.admin.orders.receipt.reject', $order->id), ['reason' => $reason]);
    }

    private function orderForCustomer(Order $order): TestResponse
    {
        return $this->actingAs($this->customer)->getJson(route('api.orders.show', $order->id))->assertOk();
    }

    public function test_payment_methods_show_what_is_available_and_the_card(): void
    {
        $this->getJson(route('api.payment-methods'))->assertUnauthorized();

        $methods = collect($this->actingAs($this->customer)->getJson(route('api.payment-methods'))->assertOk()->json('data'))->keyBy('value');

        $this->assertTrue($methods['card_to_card']['enabled']);
        $this->assertSame('6037-0000-0000-0000', $methods['card_to_card']['card']['number']);
        $this->assertSame('گیم ورلد', $methods['card_to_card']['card']['holder']);
        $this->assertTrue($methods['wallet']['enabled']);

        config(['services.card_to_card.number' => null]);

        $methods = collect($this->actingAs($this->customer)->getJson(route('api.payment-methods'))->json('data'))->keyBy('value');
        $this->assertFalse($methods['card_to_card']['enabled']);
        $this->assertNull($methods['card_to_card']['card']);
    }

    public function test_gateway_shows_as_unavailable_in_production(): void
    {
        $this->app['env'] = 'production';

        $methods = collect($this->actingAs($this->customer)->getJson(route('api.payment-methods'))->assertOk()->json('data'))->keyBy('value');

        $this->assertFalse($methods['gateway']['enabled']);
        $this->assertTrue($methods['card_to_card']['enabled']);
    }

    public function test_checkout_locks_the_price_and_waits_for_the_receipt(): void
    {
        $response = $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'card_to_card', 'items' => [['variantId' => $this->variant->id]]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.payment_method', 'card_to_card')
            ->assertJsonPath('data.amount', 1000000)
            ->assertJsonPath('data.order.statusRaw', 'awaiting_payment')
            ->assertJsonPath('data.order.paymentStatusRaw', 'pending')
            ->assertJsonPath('data.order.paymentMethod', 'card_to_card')
            ->assertJsonPath('data.order.card_payment.status', 'awaiting_receipt')
            ->assertJsonPath('data.order.card_payment.amount', 1000000)
            ->assertJsonPath('data.order.card_payment.can_upload_receipt', true)
            ->assertJsonPath('data.order.card_payment.card.number', '6037-0000-0000-0000')
            ->assertJsonPath('data.order.cancellable', true);

        $this->assertNotNull($response->json('data.order.card_payment.due_at'));
        $this->assertEquals(100, (float) $this->customer->wallet()->value('balance'));

        $notification = $this->customer->notifications()->sole();
        $this->assertSame('order_awaiting_payment', $notification->data['type']);

        $this->variant->update(['price' => 9000000]);

        $this->assertEquals(1000000, (float) Order::findOrFail($response->json('data.order_id'))->total);
    }

    public function test_checkout_charges_the_chosen_price_option(): void
    {
        $this->variant->syncOptions([['label' => 'ظرفیت ۱', 'price' => 1000000], ['label' => 'ظرفیت ۲', 'price' => 1500000]]);
        $this->variant->syncPriceWithDefaultOption();
        $second = $this->variant->activeOptions()->get()[1];

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'card_to_card', 'items' => [['variantId' => $this->variant->id, 'option_id' => $second->id]]])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1500000)
            ->assertJsonPath('data.order.card_payment.amount', 1500000)
            ->assertJsonPath('data.order.items.0.selected_option.id', $second->id);
    }

    public function test_checkout_is_refused_when_no_card_is_set(): void
    {
        config(['services.card_to_card.number' => '']);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'card_to_card', 'items' => [['variantId' => $this->variant->id]]])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'پرداخت کارت به کارت در حال حاضر فعال نیست.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_receipt_goes_on_the_private_disk_and_alerts_admins(): void
    {
        $order = $this->placeOrder();

        $this->uploadReceipt($order)
            ->assertOk()
            ->assertJsonPath('data.card_payment.status', 'in_review')
            ->assertJsonPath('data.card_payment.can_upload_receipt', false)
            ->assertJsonPath('data.cancellable', false);

        $this->assertCount(1, Storage::disk('local')->allFiles('receipts'));
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->assertSame('receipt_submitted', $this->admin->notifications()->sole()->data['type']);
        $this->assertSame(0, $this->customer->notifications()->where('type', 'like', '%ReceiptSubmitted')->count());
    }

    public function test_receipt_is_only_reachable_through_its_signed_link(): void
    {
        $order = $this->placeOrder();
        $url = $this->uploadReceipt($order)->json('data.card_payment.receipt_url');
        $payment = Payment::where('order_id', $order->id)->sole();

        $otherOrder = $this->placeOrder();
        $this->uploadReceipt($otherOrder)->assertOk();
        $otherPayment = Payment::where('order_id', $otherOrder->id)->sole();

        $this->app['auth']->forgetGuards();

        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->get(route('api.receipts.show', $payment))->assertForbidden();
        $this->get($url.'x')->assertForbidden();
        $this->get(str_replace("receipts/{$payment->id}", "receipts/{$otherPayment->id}", $url))->assertForbidden();

        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_receipt_details_never_expose_the_storage_path(): void
    {
        $order = $this->placeOrder();
        $response = $this->uploadReceipt($order);

        $this->assertStringNotContainsString('receipts/', json_encode($response->json('data.card_payment.status')));
        $response->assertJsonMissingPath('data.card_payment.receipt_path');
        $this->assertArrayNotHasKey('receipt_path', Payment::where('order_id', $order->id)->sole()->toArray());
        $this->assertArrayNotHasKey('receipt_hash', Payment::where('order_id', $order->id)->sole()->toArray());
    }

    public function test_only_images_are_accepted_as_receipts(): void
    {
        $order = $this->placeOrder();

        foreach ([
            UploadedFile::fake()->create('shell.php', 1, 'application/x-php'),
            UploadedFile::fake()->create('page.html', 1, 'text/html'),
            UploadedFile::fake()->create('vector.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->create('statement.pdf', 1, 'application/pdf'),
            UploadedFile::fake()->image('huge.jpg')->size(6000),
        ] as $file) {
            $this->uploadReceipt($order, $file)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('receipt');
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull(Payment::where('order_id', $order->id)->sole()->receipt_path);
    }

    public function test_customers_can_only_upload_to_their_own_orders(): void
    {
        $order = $this->placeOrder();

        $this->uploadReceipt($order, user: User::factory()->create())->assertNotFound();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_second_receipt_is_refused_while_one_is_under_review(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();

        $this->uploadReceipt($order)
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertCount(1, Storage::disk('local')->allFiles('receipts'));
    }

    public function test_receipts_are_refused_once_the_order_is_no_longer_waiting(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();
        $this->approve($order)->assertOk();

        $this->uploadReceipt($order)->assertUnprocessable();

        $this->assertCount(1, Storage::disk('local')->allFiles('receipts'));
    }

    public function test_approving_moves_the_order_into_the_normal_flow(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();

        $this->approve($order)
            ->assertOk()
            ->assertJsonPath('data.statusRaw', 'pending')
            ->assertJsonPath('data.paymentStatusRaw', 'paid')
            ->assertJsonPath('data.paymentMethod', 'card_to_card')
            ->assertJsonPath('data.card_payment.status', 'approved')
            ->assertJsonPath('data.card_payment.card', null)
            ->assertJsonPath('data.receipts.0.status', 'approved')
            ->assertJsonPath('data.receipts.0.reviewed_by.id', $this->admin->id);

        $payment = Payment::where('order_id', $order->id)->sole();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);

        $this->assertContains('payment_approved', $this->customer->notifications()->get()->pluck('data.type'));

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.status', $order->id), ['status' => 'processing'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.items.installation', [$order->id, $order->items()->sole()->id]), ['installation_status' => 'installed'])
            ->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_rejecting_tells_the_customer_why_and_allows_a_new_receipt(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();

        $this->travel(5)->hours();

        $this->reject($order, 'مبلغ رسید با سفارش یکی نیست.')
            ->assertOk()
            ->assertJsonPath('data.statusRaw', 'awaiting_payment')
            ->assertJsonPath('data.card_payment.status', 'rejected')
            ->assertJsonPath('data.card_payment.can_upload_receipt', true)
            ->assertJsonPath('data.card_payment.reject_reason', 'مبلغ رسید با سفارش یکی نیست.')
            ->assertJsonPath('data.cancellable', true);

        $this->assertEquals(now()->addHours(24)->timestamp, $order->fresh()->payment_due_at->timestamp);

        $notification = $this->customer->notifications()->get()->firstWhere('data.type', 'payment_rejected');
        $this->assertSame('مبلغ رسید با سفارش یکی نیست.', $notification->data['reason']);

        $this->uploadReceipt($order, UploadedFile::fake()->image('better.jpg', 500, 700))
            ->assertOk()
            ->assertJsonPath('data.card_payment.status', 'in_review');

        $this->assertSame(2, Payment::where('order_id', $order->id)->count());
        $this->assertCount(2, Storage::disk('local')->allFiles('receipts'));

        $this->approve($order)->assertOk()->assertJsonPath('data.paymentStatusRaw', 'paid');

        $this->assertSame(
            ['approved', 'rejected'],
            array_column($this->actingAs($this->admin)->getJson(route('api.admin.orders.show', $order->id))->assertOk()->json('data.receipts'), 'status')
        );
    }

    public function test_rejecting_needs_a_reason(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.orders.receipt.reject', $order->id), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_there_must_be_a_receipt_under_review_to_decide_on(): void
    {
        $order = $this->placeOrder();

        $this->approve($order)->assertUnprocessable();
        $this->reject($order)->assertUnprocessable();

        $this->uploadReceipt($order)->assertOk();
        $this->approve($order)->assertOk();

        $this->approve($order)->assertUnprocessable();
        $this->reject($order)->assertUnprocessable();

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_only_admins_can_review_receipts(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();

        $this->actingAs($this->customer)->postJson(route('api.admin.orders.receipt.approve', $order->id), ['deposit_confirmed' => true])->assertForbidden();
        $this->actingAs($this->customer)->postJson(route('api.admin.orders.receipt.reject', $order->id), ['reason' => 'x'])->assertForbidden();

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_an_unpaid_order_cannot_be_moved_along_by_hand(): void
    {
        $order = $this->placeOrder();
        $item = $order->items()->sole();

        $this->actingAs($this->admin)->patchJson(route('api.admin.orders.status', $order->id), ['status' => 'processing'])->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson(route('api.admin.orders.update', $order->id), ['status' => 'completed'])->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson(route('api.admin.orders.update', $order->id), ['payment_status' => 'paid'])->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson(route('api.admin.orders.payment-status', $order->id), ['payment_status' => 'paid'])->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson(route('api.admin.orders.items.installation', [$order->id, $item->id]), ['installation_status' => 'installed'])->assertUnprocessable();

        $this->actingAs($this->admin)->patch(route('admin.orders.status.update', $order), ['status' => 'processing'])->assertSessionHas('error');
        $this->actingAs($this->admin)->patch(route('admin.orders.payment-status.update', $order), ['payment_status' => 'paid'])->assertSessionHas('error');

        $order->refresh();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame('pending', $item->fresh()->installation_status);
    }

    public function test_awaiting_payment_cannot_be_set_by_hand(): void
    {
        $order = Order::create(['user_id' => $this->customer->id, 'console_type' => 'PS5', 'total' => 1000, 'status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::Paid]);

        $this->actingAs($this->admin)->patchJson(route('api.admin.orders.status', $order->id), ['status' => 'awaiting_payment'])->assertUnprocessable();
        $this->actingAs($this->admin)->patchJson(route('api.admin.orders.update', $order->id), ['status' => 'awaiting_payment'])->assertUnprocessable();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_customer_can_cancel_before_sending_a_receipt_without_a_refund(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.cancel', $order->id))
            ->assertOk()
            ->assertJsonPath('data.statusRaw', 'cancelled')
            ->assertJsonPath('data.paymentStatusRaw', 'failed')
            ->assertJsonPath('data.card_payment.status', 'cancelled')
            ->assertJsonPath('data.card_payment.can_upload_receipt', false);

        $this->assertEquals(100, (float) $this->customer->wallet()->value('balance'));
        $this->assertSame(PaymentStatus::Failed, Payment::where('order_id', $order->id)->sole()->status);
    }

    public function test_nobody_can_cancel_while_a_receipt_is_under_review(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.cancel', $order->id))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'رسید شما در حال بررسی است و تا مشخص شدن نتیجه نمی‌توانید سفارش را لغو کنید.');

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.status', $order->id), ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'رسید این سفارش در حال بررسی است. ابتدا آن را تأیید یا رد کنید.');

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_a_rejected_order_can_be_cancelled(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();
        $this->reject($order)->assertOk();

        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $order->id))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertEquals(100, (float) $this->customer->wallet()->value('balance'));
    }

    public function test_cancelling_an_approved_order_refunds_the_wallet(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();
        $this->approve($order)->assertOk();

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.cancel', $order->id))
            ->assertOk()
            ->assertJsonPath('data.paymentStatusRaw', 'refunded')
            ->assertJsonPath('data.card_payment.status', 'refunded');

        $this->assertEquals(1000100, (float) $this->customer->wallet()->value('balance'));

        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $order->id))->assertUnprocessable();
        $this->assertEquals(1000100, (float) $this->customer->wallet()->value('balance'));
    }

    public function test_the_same_receipt_on_another_order_is_flagged_for_the_admin(): void
    {
        $image = UploadedFile::fake()->image('r.jpg', 300, 300);
        $content = file_get_contents($image->getRealPath());
        $sameFile = fn () => UploadedFile::fake()->createWithContent('receipt.jpg', $content);

        $first = $this->placeOrder();
        $this->uploadReceipt($first, $sameFile())->assertOk();
        $this->approve($first)->assertOk();

        $second = $this->placeOrder();
        $this->uploadReceipt($second, $sameFile())->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders.show', $second->id))
            ->assertOk()
            ->assertJsonPath('data.receipts.0.duplicate_order_ids', [$first->id]);

        $third = $this->placeOrder();
        $this->uploadReceipt($third, UploadedFile::fake()->image('other.jpg', 640, 480))->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders.show', $third->id))
            ->assertJsonPath('data.receipts.0.duplicate_order_ids', []);
    }

    public function test_admin_can_find_receipts_to_review_and_see_the_counts(): void
    {
        $withReceipt = $this->placeOrder();
        $this->uploadReceipt($withReceipt)->assertOk();
        $this->placeOrder();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders', ['receipt_status' => 'in_review']))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $withReceipt->id)
            ->assertJsonPath('data.data.0.card_payment.status', 'in_review');

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders', ['statusRaw' => 'awaiting_payment']))
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.dashboard-stats'))
            ->assertOk()
            ->assertJsonPath('data.awaitingPaymentOrders', 2)
            ->assertJsonPath('data.receiptsInReview', 1)
            ->assertJsonPath('data.pendingOrders', 0)
            ->assertJsonPath('data.totalRevenue', 0);

        $this->approve($withReceipt)->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.dashboard-stats'))
            ->assertJsonPath('data.pendingOrders', 1)
            ->assertJsonPath('data.totalRevenue', 1000000);
    }

    public function test_overdue_orders_are_cancelled_unless_a_receipt_is_under_review(): void
    {
        $abandoned = $this->placeOrder();
        $inReview = $this->placeOrder();
        $this->uploadReceipt($inReview)->assertOk();

        $this->travel(23)->hours();
        $notYetDue = $this->placeOrder();

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::AwaitingPayment, $abandoned->fresh()->status);

        $this->travel(2)->hours();

        $this->artisan('orders:cancel-unpaid')
            ->expectsOutputToContain('Cancelled 1 unpaid order(s).')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $abandoned->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $inReview->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $notYetDue->fresh()->status);
    }

    public function test_expiring_gateway_payments_leaves_card_payments_alone(): void
    {
        $order = $this->placeOrder();
        $payment = Payment::where('order_id', $order->id)->sole();
        $gatewayPayment = Payment::create(['user_id' => $this->customer->id, 'amount' => 1000, 'method' => PaymentMethod::Gateway, 'status' => PaymentStatus::Pending]);

        $this->travel(2)->hours();

        $this->artisan('payments:expire-pending')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $gatewayPayment->fresh()->status);
    }

    public function test_approving_needs_the_admin_to_state_they_saw_the_deposit(): void
    {
        $order = $this->placeOrder();
        $this->uploadReceipt($order)->assertOk();

        foreach ([[], ['deposit_confirmed' => false], ['deposit_confirmed' => 0]] as $body) {
            $this->actingAs($this->admin)
                ->postJson(route('api.admin.orders.receipt.approve', $order->id), $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('deposit_confirmed');
        }

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);

        $this->approve($order)->assertOk();
    }

    public function test_card_payments_cannot_be_completed_through_the_gateway_callbacks(): void
    {
        $order = $this->placeOrder();
        $payment = Payment::where('order_id', $order->id)->sole();

        $this->app['auth']->forgetGuards();

        foreach (['api.orders.callback', 'api.wallet.callback'] as $route) {
            $this->getJson(route($route, ['payment' => $payment->id, 'transaction_id' => 'anything']))->assertUnprocessable();
        }

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_order_list_marks_what_the_customer_has_to_do(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->customer)
            ->getJson(route('api.orders.index'))
            ->assertOk()
            ->assertJsonPath('data.0.statusRaw', 'awaiting_payment')
            ->assertJsonPath('data.0.status', 'در انتظار تأیید پرداخت')
            ->assertJsonPath('data.0.card_payment.status', 'awaiting_receipt');

        $this->uploadReceipt($order)->assertOk();

        $this->orderForCustomer($order)->assertJsonPath('data.card_payment.status', 'in_review');
    }

    public function test_orders_paid_another_way_have_no_card_payment(): void
    {
        $this->customer->wallet()->update(['balance' => 5000000]);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'wallet', 'items' => [['variantId' => $this->variant->id]]])
            ->assertCreated()
            ->assertJsonPath('data.order.card_payment', null)
            ->assertJsonPath('data.order.paymentMethod', 'wallet');
    }
}
