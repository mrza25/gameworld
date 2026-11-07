<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private GameVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.card_to_card.number' => '6037-0000-0000-0000']);

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create();
        $this->customer->wallet()->create(['balance' => 5000000]);

        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->variant = Game::create(['title' => 'GTA V', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 1000000, 'size' => 50, 'is_available' => true]);
    }

    private function checkout(?string $code, string $method = 'wallet', ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->customer)->postJson(route('api.orders.checkout'), array_filter([
            'method' => $method,
            'items' => [['variantId' => $this->variant->id]],
            'coupon_code' => $code,
        ]));
    }

    private function balance(?User $user = null): float
    {
        return (float) ($user ?? $this->customer)->wallet()->value('balance');
    }

    public function test_a_percent_coupon_lowers_what_the_wallet_pays_and_is_recorded_on_the_order(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'NOWRUZ', 'value' => 20]);

        $this->checkout('NOWRUZ')
            ->assertCreated()
            ->assertJsonPath('data.amount', 800000)
            ->assertJsonPath('data.order.subtotal', 1000000)
            ->assertJsonPath('data.order.discount_amount', 200000)
            ->assertJsonPath('data.order.coupon_code', 'NOWRUZ')
            ->assertJsonPath('data.order.total', 800000);

        $this->assertEquals(4200000, $this->balance());
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $coupon->id, 'user_id' => $this->customer->id, 'discount_amount' => 200000]);
    }

    public function test_an_order_without_a_coupon_still_shows_its_price_breakdown(): void
    {
        $this->checkout(null)
            ->assertCreated()
            ->assertJsonPath('data.order.subtotal', 1000000)
            ->assertJsonPath('data.order.discount_amount', 0)
            ->assertJsonPath('data.order.coupon_code', null);

        $this->assertEquals(4000000, $this->balance());
    }

    public function test_a_fixed_coupon_and_the_percent_cap_work(): void
    {
        Coupon::factory()->fixed(150000)->create(['code' => 'FIXED']);
        Coupon::factory()->create(['code' => 'CAPPED', 'value' => 50, 'max_discount' => 100000]);

        $this->checkout('FIXED')->assertCreated()->assertJsonPath('data.order.discount_amount', 150000);
        $this->checkout('CAPPED')->assertCreated()->assertJsonPath('data.order.discount_amount', 100000);
    }

    public function test_the_code_is_understood_however_it_is_typed(): void
    {
        Coupon::factory()->create(['code' => 'NOWRUZ14']);

        $this->checkout(' nowruz۱۴ ')->assertCreated()->assertJsonPath('data.order.coupon_code', 'NOWRUZ14');
    }

    public function test_card_to_card_asks_for_the_discounted_amount(): void
    {
        Coupon::factory()->create(['code' => 'CARD', 'value' => 10]);

        $response = $this->checkout('CARD', 'card_to_card')
            ->assertCreated()
            ->assertJsonPath('data.order.card_payment.amount', 900000)
            ->assertJsonPath('data.order.total', 900000);

        $this->assertEquals(900000, Payment::where('order_id', $response->json('data.order_id'))->sole()->amount);
        $this->assertEquals(5000000, $this->balance());
    }

    public function test_the_gateway_charges_the_discount_and_counts_the_use_only_once_paid(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'GATE', 'value' => 10]);

        $response = $this->checkout('GATE', 'gateway')->assertOk()->assertJsonPath('amount', 900000);
        $payment = Payment::findOrFail($response->json('payment_id'));

        $this->assertSame(0, $coupon->fresh()->used_count);

        $this->app['auth']->forgetGuards();
        $this->getJson(route('api.orders.callback', ['payment' => $payment->id, 'transaction_id' => $payment->transaction_id]))->assertOk();

        $order = Order::findOrFail($payment->fresh()->order_id);

        $this->assertEquals(900000, $order->total);
        $this->assertEquals(100000, $order->discount_amount);
        $this->assertSame('GATE', $order->coupon_code);
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
    }

    public function test_a_coupon_that_cannot_be_used_stops_the_checkout(): void
    {
        Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);
        Coupon::factory()->create(['code' => 'SOON', 'starts_at' => now()->addDay()]);
        Coupon::factory()->create(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        Coupon::factory()->create(['code' => 'FULL', 'usage_limit' => 2])->forceFill(['used_count' => 2])->save();
        Coupon::factory()->create(['code' => 'BIG', 'min_order_amount' => 2000000]);
        Coupon::factory()->fixed(1000000)->create(['code' => 'FREE']);

        foreach (['MISSING', 'OFF', 'SOON', 'OLD', 'FULL', 'BIG', 'FREE'] as $code) {
            $this->checkout($code)->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('coupon_usages', 0);
        $this->assertEquals(5000000, $this->balance());
    }

    public function test_limits_count_across_customers_and_per_customer(): void
    {
        $other = User::factory()->create();
        $other->wallet()->create(['balance' => 5000000]);

        Coupon::factory()->create(['code' => 'ONCE', 'usage_limit' => 1]);
        Coupon::factory()->create(['code' => 'EACH', 'per_user_limit' => 1]);

        $this->checkout('ONCE')->assertCreated();
        $this->checkout('ONCE', user: $other)->assertUnprocessable()->assertJsonValidationErrors('coupon_code');

        $this->checkout('EACH')->assertCreated();
        $this->checkout('EACH')->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
        $this->checkout('EACH', user: $other)->assertCreated();
    }

    public function test_cancelling_an_order_refunds_what_was_paid_and_gives_the_use_back(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'BACK', 'value' => 20, 'usage_limit' => 1]);

        $orderId = $this->checkout('BACK')->assertCreated()->json('data.order_id');

        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $orderId))->assertOk();

        $this->assertEquals(5000000, $this->balance());
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertDatabaseCount('coupon_usages', 0);

        $this->checkout('BACK')->assertCreated();
    }

    public function test_an_unpaid_order_that_expires_gives_the_use_back(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'LATE', 'usage_limit' => 1]);

        $this->checkout('LATE', 'card_to_card')->assertCreated();
        $this->assertSame(1, $coupon->fresh()->used_count);

        $this->travel(25)->hours();
        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_the_automatic_payment_method_looks_at_the_discounted_price(): void
    {
        Coupon::factory()->create(['code' => 'ENOUGH', 'value' => 10]);
        $this->customer->wallet()->update(['balance' => 950000]);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['items' => [['variantId' => $this->variant->id]], 'coupon_code' => 'ENOUGH'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'wallet');

        $this->assertEquals(50000, $this->balance());
    }

    public function test_customers_can_preview_a_coupon_on_the_cart_page(): void
    {
        Coupon::factory()->create(['code' => 'LOOK', 'value' => 25]);

        $this->actingAs($this->customer)
            ->postJson(route('api.coupons.check'), ['code' => 'look', 'amount' => 400000])
            ->assertOk()
            ->assertJsonPath('data.code', 'LOOK')
            ->assertJsonPath('data.discount', 100000)
            ->assertJsonPath('data.payable', 300000);

        $this->actingAs($this->customer)
            ->postJson(route('api.coupons.check'), ['code' => 'NOPE', 'amount' => 400000])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'کد تخفیف معتبر نیست.')
            ->assertJsonValidationErrors('code');

        $this->assertSame(0, Coupon::where('code', 'LOOK')->sole()->used_count);

        $this->app['auth']->forgetGuards();
        $this->postJson(route('api.coupons.check'), ['code' => 'LOOK', 'amount' => 400000])->assertUnauthorized();
    }

    public function test_admins_manage_coupons(): void
    {
        $id = $this->actingAs($this->admin)
            ->postJson(route('api.admin.coupons.store'), ['code' => ' sale۱ ', 'type' => 'percent', 'value' => 15, 'usage_limit' => 10])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SALE1')
            ->assertJsonPath('data.is_usable', true)
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.coupons.store'), ['code' => 'SALE1', 'type' => 'fixed', 'value' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.coupons.update', $id), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_usable', false)
            ->assertJsonPath('data.value', 15);

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.coupons.update', $id), ['code' => 'SALE1'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.coupons.index', ['search' => 'sale']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin)->deleteJson(route('api.admin.coupons.destroy', $id))->assertOk();
        $this->assertDatabaseCount('coupons', 0);
    }

    public function test_coupon_values_are_checked(): void
    {
        $invalid = [
            ['code' => 'A', 'type' => 'percent', 'value' => 100],
            ['code' => 'A', 'type' => 'percent', 'value' => 0],
            ['code' => 'A', 'type' => 'other', 'value' => 10],
            ['code' => 'A B$', 'type' => 'fixed', 'value' => 10],
            ['code' => 'A', 'type' => 'fixed', 'value' => 10, 'starts_at' => '2026-12-01', 'expires_at' => '2026-11-01'],
            ['type' => 'fixed', 'value' => 10],
        ];

        foreach ($invalid as $body) {
            $this->actingAs($this->admin)->postJson(route('api.admin.coupons.store'), $body)->assertUnprocessable();
        }

        $this->assertDatabaseCount('coupons', 0);

        $coupon = Coupon::factory()->fixed(5000)->create();

        // Turning a fixed coupon into a percent one must not slip past the limit.
        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.coupons.update', $coupon), ['type' => 'percent'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');
    }

    public function test_a_used_coupon_cannot_be_deleted(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'USED']);
        $this->checkout('USED')->assertCreated();

        $this->actingAs($this->admin)->deleteJson(route('api.admin.coupons.destroy', $coupon))->assertUnprocessable();

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    public function test_only_admins_manage_coupons(): void
    {
        $coupon = Coupon::factory()->create();

        $this->actingAs($this->customer)->getJson(route('api.admin.coupons.index'))->assertForbidden();
        $this->actingAs($this->customer)->postJson(route('api.admin.coupons.store'), ['code' => 'X', 'type' => 'fixed', 'value' => 10])->assertForbidden();
        $this->actingAs($this->customer)->patchJson(route('api.admin.coupons.update', $coupon), ['value' => 90])->assertForbidden();
        $this->actingAs($this->customer)->deleteJson(route('api.admin.coupons.destroy', $coupon))->assertForbidden();
    }
}
