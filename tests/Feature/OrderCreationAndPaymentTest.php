<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCreationAndPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createGameWithVariant(string $platformName, float $price = 60000): GameVariant
    {
        $platform = Platform::firstOrCreate(
            ['slug' => Str::slug($platformName)],
            ['name' => $platformName]
        );

        $game = Game::create([
            'title' => 'Test Game '.uniqid(),
            'slug' => 'test-game-'.uniqid(),
            'genre' => 'Action',
            'platform' => $platformName,
            'size' => 50,
            'price' => $price,
            'is_available' => true,
        ]);

        return GameVariant::create([
            'game_id' => $game->id,
            'platform_id' => $platform->id,
            'price' => $price,
            'size' => 50,
            'is_available' => true,
        ]);
    }

    public function test_customer_can_store_checkout_selection_in_session(): void
    {
        $user = User::factory()->create();
        $variant = $this->createGameWithVariant('PS5', 70000);

        $response = $this->actingAs($user)->post(route('orders.store'), [
            'console_type' => 'PS5',
            'variants' => [$variant->id],
        ]);

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHas('checkout', [
            'console_type' => 'PS5',
            'variants' => [$variant->id],
        ]);

        // Verify that NO order has been created yet!
        $this->assertDatabaseEmpty('orders');
    }

    public function test_wallet_payment_fails_when_balance_is_insufficient_and_creates_no_order(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet()->create(['balance' => 10000]); // Less than 70000
        $variant = $this->createGameWithVariant('PS5', 70000);

        $response = $this->actingAs($user)
            ->withSession([
                'checkout' => [
                    'console_type' => 'PS5',
                    'variants' => [$variant->id],
                ],
            ])
            ->post(route('checkout.wallet'));

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHas('error');

        // Order MUST NOT be created
        $this->assertDatabaseEmpty('orders');
        $this->assertEquals(10000, $wallet->fresh()->balance);
    }

    public function test_wallet_payment_succeeds_with_sufficient_balance(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet()->create(['balance' => 100000]);
        $variant = $this->createGameWithVariant('PS5', 60000);

        $response = $this->actingAs($user)
            ->withSession([
                'checkout' => [
                    'console_type' => 'PS5',
                    'variants' => [$variant->id],
                ],
            ])
            ->post(route('checkout.wallet'));

        $order = Order::where('user_id', $user->id)->first();

        $this->assertNotNull($order);
        $this->assertEquals(60000, (float) $order->total);
        $this->assertEquals(PaymentStatus::Paid, $order->payment_status);
        $this->assertEquals(OrderStatus::Pending, $order->status);

        $this->assertEquals(40000, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'game_variant_id' => $variant->id,
            'price' => 60000,
            'installation_status' => 'pending',
        ]);

        $response->assertRedirect(route('orders.show', $order));
    }

    public function test_unpaid_gateway_initiation_does_not_create_an_order(): void
    {
        $user = User::factory()->create();
        $variant = $this->createGameWithVariant('PS5', 80000);

        $response = $this->actingAs($user)
            ->withSession([
                'checkout' => [
                    'console_type' => 'PS5',
                    'variants' => [$variant->id],
                ],
            ])
            ->post(route('checkout.gateway'));

        // Payment record is created
        $this->assertDatabaseCount('payments', 1);

        // But NO finalized order exists
        $this->assertDatabaseEmpty('orders');
    }

    public function test_verified_gateway_callback_creates_order_exactly_once(): void
    {
        $user = User::factory()->create();
        $variant = $this->createGameWithVariant('PS5', 80000);

        // Initiate gateway checkout
        $this->actingAs($user)
            ->withSession([
                'checkout' => [
                    'console_type' => 'PS5',
                    'variants' => [$variant->id],
                ],
            ])
            ->post(route('checkout.gateway'));

        $payment = Payment::where('user_id', $user->id)->first();
        $transactionId = $payment->metadata['gateway_transaction_id'];

        // Gateway callback
        $callbackResponse = $this->actingAs($user)->get(route('payment.callback', [
            'payment' => $payment->id,
            'transaction_id' => $transactionId,
        ]));

        $order = Order::where('user_id', $user->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(80000, (float) $order->total);
        $callbackResponse->assertRedirect(route('orders.show', $order));

        // Replaying the callback MUST NOT duplicate the order (Idempotency)
        $replayResponse = $this->actingAs($user)->get(route('payment.callback', [
            'payment' => $payment->id,
            'transaction_id' => $transactionId,
        ]));

        $this->assertDatabaseCount('orders', 1);
        $replayResponse->assertRedirect(route('orders.show', $order));
    }
}
