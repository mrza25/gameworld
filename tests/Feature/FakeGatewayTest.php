<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\Game;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers the sandbox payment page that stands in for the bank.
 */
class FakeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'http://frontend.test']);
        $this->user = User::factory()->create();
    }

    private function chargeWallet(int $amount = 1000000): Payment
    {
        $gatewayUrl = $this->actingAs($this->user)
            ->postJson(route('api.wallet.charge'), ['amount' => $amount])
            ->assertOk()
            ->json('data.gateway_url');

        $payment = Payment::latest('id')->firstOrFail();
        $this->assertSame($this->gatewayPageUrl($payment), $gatewayUrl);
        $this->app['auth']->forgetGuards();

        return $payment;
    }

    private function gatewayPageUrl(Payment $payment, ?string $transactionId = null): string
    {
        return route('payments.fake-gateway.show', [
            'payment' => $payment->id,
            'transaction_id' => $transactionId ?? $payment->transaction_id,
        ]);
    }

    /**
     * Press a button on the gateway page and follow it back to the API callback.
     */
    private function decideAtGateway(Payment $payment, string $result): TestResponse
    {
        $callbackUrl = $this->post(route('payments.fake-gateway.submit', $payment), [
            'transaction_id' => $payment->transaction_id,
            'result' => $result,
        ])->assertRedirect()->headers->get('Location');

        return $this->get($callbackUrl);
    }

    private function walletBalance(): float
    {
        return (float) $this->user->wallet()->value('balance');
    }

    public function test_wallet_charge_sends_customer_to_the_gateway_page(): void
    {
        $payment = $this->chargeWallet(1500000);

        $this->get($this->gatewayPageUrl($payment))
            ->assertOk()
            ->assertSee('1,500,000')
            ->assertSee('شارژ کیف پول')
            ->assertSee($payment->transaction_id)
            ->assertSee('انصراف');
    }

    public function test_paying_on_the_gateway_page_charges_the_wallet_and_returns_to_the_frontend(): void
    {
        $payment = $this->chargeWallet();

        $this->decideAtGateway($payment, 'pay')
            ->assertRedirect('http://frontend.test/my/wallet?payment=success&balance=1000000');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEquals(1000000, $this->walletBalance());
    }

    public function test_cancelling_on_the_gateway_page_fails_the_payment_for_good(): void
    {
        $payment = $this->chargeWallet();

        $location = $this->decideAtGateway($payment, 'cancel')->headers->get('Location');

        $this->assertStringStartsWith('http://frontend.test/my/wallet?payment=failed&error=', $location);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);

        $this->get(route('api.wallet.callback', ['payment' => $payment->id, 'transaction_id' => $payment->transaction_id]));

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertEquals(0, $this->walletBalance());
    }

    public function test_order_checkout_through_the_gateway_page_creates_the_order(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $variant = Game::create(['title' => 'God of War', 'slug' => 'god-of-war', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 850000, 'size' => 90, 'is_available' => true]);

        $gatewayUrl = $this->actingAs($this->user)
            ->postJson(route('api.orders.checkout'), ['method' => 'gateway', 'items' => [['variant_id' => $variant->id, 'quantity' => 1]]])
            ->assertOk()
            ->json('data.gateway_url');
        $this->app['auth']->forgetGuards();

        $this->get($gatewayUrl)->assertOk()->assertSee('God of War')->assertSee('850,000');

        $response = $this->decideAtGateway(Payment::firstOrFail(), 'pay');

        $order = Order::where('user_id', $this->user->id)->firstOrFail();
        $response->assertRedirect("http://frontend.test/my/orders?payment=success&order_id={$order->id}");
    }

    public function test_cancelling_an_order_payment_creates_no_order(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $variant = Game::create(['title' => 'God of War', 'slug' => 'god-of-war', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 850000, 'size' => 90, 'is_available' => true]);

        $this->actingAs($this->user)
            ->postJson(route('api.orders.checkout'), ['method' => 'gateway', 'items' => [['variant_id' => $variant->id, 'quantity' => 1]]])
            ->assertOk();
        $this->app['auth']->forgetGuards();

        $location = $this->decideAtGateway(Payment::firstOrFail(), 'cancel')->headers->get('Location');

        $this->assertStringStartsWith('http://frontend.test/my/orders?payment=failed&error=', $location);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_gateway_page_and_failure_callback_require_the_transaction_id(): void
    {
        $payment = $this->chargeWallet();

        $this->get($this->gatewayPageUrl($payment, 'FAKE-GUESS'))->assertNotFound();
        $this->post(route('payments.fake-gateway.submit', $payment), ['transaction_id' => 'FAKE-GUESS', 'result' => 'cancel'])
            ->assertNotFound();
        $this->get(route('api.wallet.callback', ['payment' => $payment->id, 'transaction_id' => 'FAKE-GUESS', 'status' => 'NOK']));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_paid_payment_page_only_returns_to_the_site(): void
    {
        $payment = $this->chargeWallet();
        $this->decideAtGateway($payment, 'pay');

        $this->get($this->gatewayPageUrl($payment))
            ->assertOk()
            ->assertSee('این پرداخت قبلاً انجام شده است.')
            ->assertDontSee('انصراف');

        $this->decideAtGateway($payment, 'cancel')
            ->assertRedirect('http://frontend.test/my/wallet?payment=success');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEquals(1000000, $this->walletBalance());
    }

    public function test_gateway_page_is_unavailable_when_a_real_gateway_is_bound(): void
    {
        $payment = $this->chargeWallet();

        $this->app->instance(PaymentGateway::class, new class implements PaymentGateway
        {
            public function purchase(Payment $payment): string
            {
                return 'REAL-1';
            }

            public function redirectUrl(Payment $payment): string
            {
                return 'https://bank.example/pay';
            }

            public function verify(Payment $payment, string $transactionId): bool
            {
                return false;
            }
        });

        $this->get($this->gatewayPageUrl($payment))->assertNotFound();
    }
}
