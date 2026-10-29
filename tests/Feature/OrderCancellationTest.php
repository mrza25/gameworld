<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $variant = Game::create(['title' => 'GoW', 'slug' => 'gow', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 2500000, 'size' => 90, 'is_available' => true]);

        $this->customer = User::factory()->create();
        $this->customer->wallet()->create(['balance' => 3000000]);

        $orderId = $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['console' => 'ps5', 'method' => 'wallet', 'variants' => [$variant->id]])
            ->assertCreated()
            ->json('data.order_id');

        $this->order = Order::findOrFail($orderId);
    }

    private function walletBalance(): float
    {
        return (float) $this->customer->wallet()->value('balance');
    }

    public function test_customer_can_cancel_pending_order_and_is_refunded_to_wallet(): void
    {
        $this->assertEquals(500000, $this->walletBalance());

        $this->actingAs($this->customer)->getJson(route('api.orders.show', $this->order->id))
            ->assertJsonPath('data.cancellable', true);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.cancel', $this->order->id))
            ->assertOk()
            ->assertJsonPath('data.statusRaw', 'cancelled')
            ->assertJsonPath('data.paymentStatusRaw', 'refunded')
            ->assertJsonPath('data.cancellable', false);

        $this->assertEquals(3000000, $this->walletBalance());
        $this->assertDatabaseHas('wallet_transactions', ['type' => 'credit', 'amount' => 2500000, 'reference' => "order-refund-{$this->order->id}"]);
        $this->assertEquals(PaymentStatus::Refunded, Payment::where('order_id', $this->order->id)->firstOrFail()->status);
    }

    public function test_order_cannot_be_cancelled_twice(): void
    {
        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $this->order->id))->assertOk();
        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $this->order->id))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertEquals(3000000, $this->walletBalance());
    }

    public function test_customer_cannot_cancel_once_installation_started(): void
    {
        $this->order->items()->update(['installation_status' => 'installing']);
        $this->order->syncStatusFromItems();

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.cancel', $this->order->id))
            ->assertUnprocessable();

        $this->assertEquals(OrderStatus::Processing, $this->order->fresh()->status);
        $this->assertEquals(500000, $this->walletBalance());
    }

    public function test_customer_cannot_cancel_someone_elses_order(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('api.orders.cancel', $this->order->id))
            ->assertNotFound();

        $this->assertEquals(OrderStatus::Pending, $this->order->fresh()->status);
    }

    public function test_admin_cancelling_refunds_and_notifies_and_cancelled_orders_stay_cancelled(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson(route('api.admin.orders.update', $this->order->id), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.statusRaw', 'cancelled');

        $this->assertEquals(3000000, $this->walletBalance());
        Notification::assertSentTo($this->customer, OrderStatusUpdated::class);

        $this->actingAs($admin)
            ->patchJson(route('api.admin.orders.status', $this->order->id), ['status' => 'processing'])
            ->assertUnprocessable();

        $item = $this->order->items()->firstOrFail();
        $this->actingAs($admin)
            ->patchJson(route('api.admin.orders.items.installation', [$this->order->id, $item->id]), ['installation_status' => 'installed'])
            ->assertUnprocessable();

        $this->assertEquals(OrderStatus::Cancelled, $this->order->fresh()->status);
    }

    public function test_web_admin_panel_cannot_reactivate_cancelled_order(): void
    {
        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $this->order->id))->assertOk();

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.orders.status.update', $this->order), ['status' => 'processing'])
            ->assertSessionHas('error');

        $this->assertEquals(OrderStatus::Cancelled, $this->order->fresh()->status);
    }

    public function test_dashboard_counts_cancelled_orders(): void
    {
        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $this->order->id))->assertOk();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('api.admin.dashboard-stats'))
            ->assertOk()
            ->assertJsonPath('stats.cancelled_orders', 1)
            ->assertJsonPath('stats.pending_orders', 0);
    }
}
