<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private GameVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create(['email' => 'ali@example.com', 'phonenumber' => '09121234567']);
        $this->customer->wallet()->create(['balance' => 10000000]);

        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->variant = Game::create(['title' => 'GoW', 'slug' => 'gow', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 2500000, 'size' => 90, 'is_available' => true]);
    }

    private function placeWalletOrder(): int
    {
        return $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['console' => 'ps5', 'method' => 'wallet', 'variants' => [$this->variant->id]])
            ->assertCreated()
            ->json('data.order_id');
    }

    public function test_dashboard_stats_include_revenue_and_recent_users_and_orders(): void
    {
        $this->placeWalletOrder();
        $cancelledId = $this->placeWalletOrder();
        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $cancelledId))->assertOk();

        $response = $this->actingAs($this->admin)->getJson(route('api.admin.dashboard-stats'))->assertOk();

        $response->assertJsonPath('data.totalRevenue', 2500000)
            ->assertJsonPath('data.totalOrders', 2)
            ->assertJsonPath('data.totalUsers', 2)
            ->assertJsonPath('data.totalGames', 1)
            ->assertJsonCount(2, 'data.recentOrders')
            ->assertJsonPath('stats.total_orders', 2);

        $recentCustomer = collect($response->json('data.recentUsers'))->firstWhere('id', $this->customer->id);
        $this->assertSame('09121234567', $recentCustomer['phone']);
        $this->assertSame('ali@example.com', $recentCustomer['email']);
        $this->assertEquals(7500000, $recentCustomer['wallet_balance']);
        $this->assertSame($this->customer->created_at->toIso8601String(), $recentCustomer['created_at']);
    }

    public function test_admin_order_list_accepts_frontend_filter_names_and_includes_user(): void
    {
        $firstId = $this->placeWalletOrder();
        $secondId = $this->placeWalletOrder();
        Order::whereKey($secondId)->update(['status' => 'processing', 'payment_status' => 'partial']);

        $this->actingAs($this->admin)->getJson(route('api.admin.orders', ['statusRaw' => 'processing']))
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $secondId);

        $this->actingAs($this->admin)->getJson(route('api.admin.orders', ['paymentStatusRaw' => 'paid']))
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $firstId);

        $this->actingAs($this->admin)->getJson(route('api.admin.orders', ['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString(), 'console' => 'ps5']))
            ->assertOk()->assertJsonCount(2, 'data.data');

        $this->actingAs($this->admin)->getJson(route('api.admin.orders', ['search' => "ORD-{$firstId}"]))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.user', [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => '09121234567',
                'email' => 'ali@example.com',
            ]);
    }

    public function test_admin_order_detail_includes_user(): void
    {
        $orderId = $this->placeWalletOrder();

        $this->actingAs($this->admin)->getJson(route('api.admin.orders.show', "ORD-{$orderId}"))
            ->assertOk()
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.user.email', 'ali@example.com');
    }

    public function test_admin_games_list_filters_by_is_available(): void
    {
        Game::create(['title' => 'Hidden', 'slug' => 'hidden', 'genre' => 'Action', 'is_available' => false]);

        $this->actingAs($this->admin)->getJson(route('api.admin.games.index', ['is_available' => '0']))
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.slug', 'hidden');
    }

    public function test_wallet_transactions_have_categories_order_ids_and_type_filter(): void
    {
        $orderId = $this->placeWalletOrder();
        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $orderId))->assertOk();
        $this->actingAs($this->admin)->patchJson(route('api.admin.users.update', $this->customer->id), ['wallet_balance' => 10000500])->assertOk();

        $transactions = collect($this->actingAs($this->customer)->getJson(route('api.wallet.transactions'))->assertOk()->json('data.data'));

        $this->assertEqualsCanonicalizing(['payment', 'refund', 'adjustment'], $transactions->pluck('category')->all());
        $this->assertSame($orderId, $transactions->firstWhere('category', 'payment')['order_id']);
        $this->assertSame($orderId, $transactions->firstWhere('category', 'refund')['order_id']);
        $this->assertNull($transactions->firstWhere('category', 'adjustment')['order_id']);

        $this->actingAs($this->customer)->getJson(route('api.wallet.transactions', ['type' => 'refund']))
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.category', 'refund');

        $this->actingAs($this->customer)->getJson(route('api.wallet.index'))
            ->assertOk()->assertJsonPath('data.transactions.0.category', 'adjustment');
    }
}
