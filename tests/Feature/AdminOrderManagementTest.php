<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Platform;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $customer;
    private Platform $platform;
    private Game $game;
    private GameVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create();

        $this->platform = Platform::create([
            'name' => 'PS5',
            'slug' => 'ps5',
        ]);

        $this->game = Game::create([
            'title' => 'Elden Ring',
            'slug' => 'elden-ring',
            'genre' => 'RPG',
            'description' => 'Fantasy action RPG',
            'is_available' => true,
        ]);

        $this->variant = GameVariant::create([
            'game_id' => $this->game->id,
            'platform_id' => $this->platform->id,
            'price' => 120000,
            'size' => 50,
            'is_available' => true,
        ]);
    }

    public function test_admin_can_view_orders_index_and_details(): void
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'game_id' => $this->game->id,
            'game_variant_id' => $this->variant->id,
            'price' => 120000,
            'installation_status' => 'pending',
        ]);

        $indexResponse = $this->actingAs($this->admin)->get(route('admin.orders.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee((string) $order->id);

        $showResponse = $this->actingAs($this->admin)->get(route('admin.orders.show', $order));
        $showResponse->assertOk();
        $showResponse->assertSee('Elden Ring');
        $showResponse->assertSee('PS5');
    }

    public function test_non_admin_cannot_update_order_status(): void
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $response = $this->actingAs($this->customer)->patch(
            route('admin.orders.status.update', $order),
            ['status' => 'processing']
        );

        $response->assertForbidden();
    }

    public function test_admin_can_update_order_status_and_notification_is_sent(): void
    {
        Notification::fake();

        $order = Order::create([
            'user_id' => $this->customer->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.status.update', $order),
            ['status' => 'processing']
        );

        $response->assertSessionHas('success');
        $this->assertEquals(OrderStatus::Processing, $order->fresh()->status);

        Notification::assertSentTo(
            $this->customer,
            OrderStatusUpdated::class,
            function ($notification) use ($order) {
                return $notification->order->id === $order->id;
            }
        );
    }

    public function test_admin_cannot_update_order_to_invalid_status(): void
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.status.update', $order),
            ['status' => 'invalid_status_xyz']
        );

        $response->assertSessionHasErrors(['status']);
        $this->assertEquals(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_admin_can_update_payment_status(): void
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.payment-status.update', $order),
            ['payment_status' => 'paid']
        );

        $response->assertSessionHas('success');
        $this->assertEquals(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_item_installation_status_transitions_and_auto_sync_order_status(): void
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'console_type' => 'PS5',
            'total' => 240000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'game_id' => $this->game->id,
            'game_variant_id' => $this->variant->id,
            'price' => 120000,
            'installation_status' => 'pending',
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'game_id' => $this->game->id,
            'game_variant_id' => $this->variant->id,
            'price' => 120000,
            'installation_status' => 'pending',
        ]);

        // 1. Transition item 1 to 'installing' -> order should become 'processing'
        $this->actingAs($this->admin)->patch(
            route('admin.orders.items.installation-status.update', $order),
            [
                'item_id' => $item1->id,
                'installation_status' => 'installing',
            ]
        );

        $this->assertEquals('installing', $item1->fresh()->installation_status);
        $this->assertNull($item1->fresh()->installed_at);
        $this->assertEquals(OrderStatus::Processing, $order->fresh()->status);

        // 2. Transition item 1 to 'installed' -> order remains 'processing' because item 2 is still pending
        $this->actingAs($this->admin)->patch(
            route('admin.orders.items.installation-status.update', $order),
            [
                'item_id' => $item1->id,
                'installation_status' => 'installed',
            ]
        );

        $this->assertEquals('installed', $item1->fresh()->installation_status);
        $this->assertNotNull($item1->fresh()->installed_at);
        $this->assertEquals(OrderStatus::Processing, $order->fresh()->status);

        // 3. Transition item 2 to 'installed' -> ALL items installed, order auto-syncs to 'completed'
        $this->actingAs($this->admin)->patch(
            route('admin.orders.items.installation-status.update', $order),
            [
                'item_id' => $item2->id,
                'installation_status' => 'installed',
            ]
        );

        $this->assertEquals('installed', $item2->fresh()->installation_status);
        $this->assertNotNull($item2->fresh()->installed_at);
        $this->assertEquals(OrderStatus::Completed, $order->fresh()->status);

        // 4. Revert item 2 back to 'pending' -> installed_at is cleared, order reverts to 'processing'
        $this->actingAs($this->admin)->patch(
            route('admin.orders.items.installation-status.update', $order),
            [
                'item_id' => $item2->id,
                'installation_status' => 'pending',
            ]
        );

        $this->assertEquals('pending', $item2->fresh()->installation_status);
        $this->assertNull($item2->fresh()->installed_at);
        $this->assertEquals(OrderStatus::Processing, $order->fresh()->status);
    }
}
