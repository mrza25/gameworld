<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameRating;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\OrderStatus;
use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackendRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function createOrderFor(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    private function createAvailableGame(Platform $platform): Game
    {
        $game = Game::create([
            'title' => 'Game '.Str::random(8),
            'genre' => 'Action',
            'is_available' => true,
            'price' => 100000,
        ]);

        GameVariant::create([
            'game_id' => $game->id,
            'platform_id' => $platform->id,
            'price' => 100000,
            'size' => 50,
            'is_available' => true,
        ]);

        return $game;
    }

    public function test_admin_can_set_partial_payment_status(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->createOrderFor(User::factory()->create());

        $this->actingAs($admin)
            ->patchJson(route('api.admin.orders.payment-status', $order->id), ['payment_status' => 'partial'])
            ->assertOk();

        $this->assertEquals(PaymentStatus::Partial, $order->fresh()->payment_status);
    }

    public function test_admin_api_rejects_unknown_status_instead_of_resetting_order(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->createOrderFor(User::factory()->create());
        $order->update(['status' => OrderStatus::Completed]);

        $this->actingAs($admin)
            ->patchJson(route('api.admin.orders.update', $order->id), ['status' => 'typo-status'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertEquals(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_checkout_payment_cannot_be_redeemed_as_wallet_charge(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 0]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => 120000,
            'method' => PaymentMethod::Gateway,
            'status' => PaymentStatus::Pending,
            'transaction_id' => 'FAKE-CHECKOUT',
            'metadata' => [
                'gateway_transaction_id' => 'FAKE-CHECKOUT',
                'console_type' => 'PS5',
                'items' => [['game_variant_id' => 1, 'game_id' => 1, 'quantity' => 1, 'price' => '120000.00']],
            ],
        ]);

        $this->actingAs($user)
            ->getJson(route('api.wallet.callback', ['payment' => $payment->id, 'transaction_id' => 'FAKE-CHECKOUT']))
            ->assertUnprocessable();

        $this->assertEquals(0, (float) $user->wallet->fresh()->balance);
        $this->assertEquals(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_otp_is_invalidated_after_too_many_wrong_attempts(): void
    {
        $phone = '09121112233';
        $code = $this->postJson(route('api.auth.send-otp'), ['phone' => $phone])->json('debug_code');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.auth.verify-otp'), ['phone' => $phone, 'code' => '00000'])
                ->assertUnprocessable();
        }

        $this->postJson(route('api.auth.verify-otp'), ['phone' => $phone, 'code' => $code])
            ->assertUnprocessable();
    }

    public function test_admin_wallet_balance_change_is_recorded_in_ledger(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 50000]);

        $this->actingAs($admin)
            ->patchJson(route('api.admin.users.update', $user->id), ['wallet_balance' => 20000])
            ->assertOk();

        $wallet = $user->wallet->fresh();
        $this->assertEquals(20000, (float) $wallet->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 30000,
            'reference' => 'admin-adjustment',
        ]);
    }

    public function test_product_listing_query_count_does_not_grow_with_game_count(): void
    {
        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PS5']);
        $this->createAvailableGame($platform);

        DB::enableQueryLog();
        $this->getJson(route('api.products.index'))->assertOk();
        $baselineQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 0; $i < 5; $i++) {
            $this->createAvailableGame($platform);
        }
        DB::flushQueryLog();

        $this->getJson(route('api.products.index'))->assertOk();
        $this->assertSame($baselineQueries, count(DB::getQueryLog()));
    }

    public function test_updating_a_rating_keeps_its_original_created_at(): void
    {
        $user = User::factory()->create();
        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PS5']);
        $game = $this->createAvailableGame($platform);

        $this->actingAs($user)->postJson(route('api.games.rate', $game->id), ['rating' => 3])->assertOk();
        $createdAt = GameRating::firstOrFail()->created_at;

        $this->travel(1)->hours();

        $this->actingAs($user)->postJson(route('api.games.rate', $game->id), ['rating' => 5])
            ->assertOk()
            ->assertJson(['userRating' => 5]);

        $rating = GameRating::firstOrFail();
        $this->assertEquals(5, $rating->rating);
        $this->assertEquals($createdAt, $rating->created_at);
    }
}
