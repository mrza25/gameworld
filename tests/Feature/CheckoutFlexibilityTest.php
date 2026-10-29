<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkout requests that only send items (no console / method), as in the
 * frontend's newer contract.
 */
class CheckoutFlexibilityTest extends TestCase
{
    use RefreshDatabase;

    private GameVariant $ps5Variant;

    private GameVariant $ps4Variant;

    protected function setUp(): void
    {
        parent::setUp();

        $ps5 = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $ps4 = Platform::firstOrCreate(['slug' => 'ps4'], ['name' => 'PlayStation 4']);

        $gow = Game::create(['title' => 'GoW', 'slug' => 'gow', 'genre' => 'Action', 'is_available' => true]);
        $tlou = Game::create(['title' => 'TLOU', 'slug' => 'tlou', 'genre' => 'Action', 'is_available' => true]);

        $this->ps5Variant = $gow->variants()->create(['platform_id' => $ps5->id, 'price' => 2500000, 'size' => 90, 'is_available' => true]);
        $this->ps4Variant = $tlou->variants()->create(['platform_id' => $ps4->id, 'price' => 2000000, 'size' => 80, 'is_available' => true]);
    }

    private function customerWithBalance(int $balance): User
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => $balance]);

        return $user;
    }

    public function test_items_only_checkout_infers_console_and_pays_from_sufficient_wallet(): void
    {
        $user = $this->customerWithBalance(3000000);

        $response = $this->actingAs($user)->postJson(route('api.orders.checkout'), [
            'items' => [['variant_id' => $this->ps5Variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $response->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_method', 'wallet')
            ->assertJsonPath('data.redirect_url', "/my/orders/{$order->id}")
            ->assertJsonPath('data.amount', 2500000);
        $this->assertSame('PlayStation 5', $order->console_type);
        $this->assertEquals(500000, (float) $user->wallet()->value('balance'));
    }

    public function test_items_only_checkout_uses_gateway_when_wallet_is_insufficient(): void
    {
        $user = $this->customerWithBalance(1000);

        $this->actingAs($user)->postJson(route('api.orders.checkout'), [
            'items' => [['variant_id' => $this->ps5Variant->id, 'quantity' => 1]],
        ])
            ->assertOk()
            ->assertJsonPath('data.order_id', null)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_method', 'gateway')
            ->assertJsonPath('data.amount', 2500000)
            ->assertJsonStructure(['data' => ['gateway_url', 'authority']]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_items_for_different_consoles_without_console_are_rejected(): void
    {
        $this->actingAs($this->customerWithBalance(9000000))
            ->postJson(route('api.orders.checkout'), [
                'items' => [['variant_id' => $this->ps5Variant->id], ['variant_id' => $this->ps4Variant->id]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quantity_above_one_is_rejected(): void
    {
        $this->actingAs($this->customerWithBalance(9000000))
            ->postJson(route('api.orders.checkout'), [
                'items' => [['variant_id' => $this->ps5Variant->id, 'quantity' => 2]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_coupon_code_is_rejected_instead_of_silently_ignored(): void
    {
        $this->actingAs($this->customerWithBalance(9000000))
            ->postJson(route('api.orders.checkout'), [
                'items' => [['variant_id' => $this->ps5Variant->id, 'quantity' => 1]],
                'coupon_code' => 'NOWRUZ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['coupon_code']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_explicit_console_and_method_still_work(): void
    {
        $this->actingAs($this->customerWithBalance(9000000))
            ->postJson(route('api.orders.checkout'), [
                'console' => 'ps5',
                'method' => 'gateway',
                'variants' => [$this->ps5Variant->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.method', 'gateway')
            ->assertJsonPath('data.payment_method', 'gateway');
    }
}
