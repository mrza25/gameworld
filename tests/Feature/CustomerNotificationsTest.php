<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Every order and wallet event leaves a Persian notification for the customer.
 */
class CustomerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    private GameVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'http://frontend.test']);

        $this->customer = User::factory()->create();
        $this->admin = User::factory()->admin()->create();

        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->variant = Game::create(['title' => 'God of War', 'slug' => 'god-of-war', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 2500000, 'size' => 90, 'is_available' => true]);
    }

    private function placeWalletOrder(): Order
    {
        $this->customer->wallet()->firstOrCreate([], ['balance' => 0])->update(['balance' => 10000000]);

        $orderId = $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'wallet', 'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])
            ->assertCreated()
            ->json('data.order_id');

        return Order::findOrFail($orderId);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function notificationsOfType(string $type): Collection
    {
        return $this->customer->notifications()->get()->pluck('data')->where('type', $type)->values();
    }

    public function test_wallet_checkout_notifies_that_the_order_was_placed(): void
    {
        $order = $this->placeWalletOrder();

        $notification = $this->notificationsOfType('order_placed')->sole();

        $this->assertSame("سفارش #{$order->id} ثبت شد", $notification['title']);
        $this->assertStringContainsString('2,500,000 تومان', $notification['message']);
        $this->assertSame("/my/orders/{$order->id}", $notification['link']);
        $this->assertSame($order->id, $notification['order_id']);
    }

    public function test_gateway_checkout_notifies_once_the_payment_is_confirmed(): void
    {
        $callbackUrl = $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'gateway', 'items' => [['variant_id' => $this->variant->id, 'quantity' => 1]]])
            ->assertOk()
            ->json('callback_url');

        $this->assertCount(0, $this->notificationsOfType('order_placed'));

        $this->get($callbackUrl);

        $order = Order::where('user_id', $this->customer->id)->sole();
        $this->assertSame($order->id, $this->notificationsOfType('order_placed')->sole()['order_id']);
    }

    public function test_customer_cancellation_notifies_with_the_refund(): void
    {
        $order = $this->placeWalletOrder();

        $this->actingAs($this->customer)->postJson(route('api.orders.cancel', $order->id))->assertOk();

        $notification = $this->notificationsOfType('order_status')->sole();
        $this->assertSame('cancelled', $notification['status']);
        $this->assertSame('refunded', $notification['payment_status']);
        $this->assertStringContainsString('2,500,000 تومان به کیف پول شما برگشت', $notification['message']);
    }

    public function test_admin_payment_and_installation_changes_notify_the_customer(): void
    {
        $order = $this->placeWalletOrder();
        $item = $order->items()->sole();

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.payment-status', $order->id), ['payment_status' => 'partial'])
            ->assertOk();
        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.payment-status', $order->id), ['payment_status' => 'partial'])
            ->assertOk();

        $this->assertSame(['partial'], $this->notificationsOfType('payment_status')->pluck('payment_status')->all());

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.items.installation', [$order->id, $item->id]), ['installation_status' => 'installing'])
            ->assertOk();
        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.items.installation', [$order->id, $item->id]), ['installation_status' => 'installed'])
            ->assertOk();

        $installations = $this->notificationsOfType('installation_status');
        $this->assertEqualsCanonicalizing(['installing', 'installed'], $installations->pluck('installation_status')->all());
        $this->assertTrue($installations->contains(fn (array $data) => str_contains($data['message'], 'God of War')));

        $this->assertContains('completed', $this->notificationsOfType('order_status')->pluck('status')->all());
    }

    public function test_unified_admin_order_update_notifies_each_change(): void
    {
        $order = $this->placeWalletOrder();
        $item = $order->items()->sole();

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.update', $order->id), [
                'payment_status' => 'partial',
                'install_statuses' => [['item_id' => $item->id, 'install_status' => 'installing']],
            ])
            ->assertOk();

        $this->assertCount(1, $this->notificationsOfType('payment_status'));
        $this->assertCount(1, $this->notificationsOfType('installation_status'));
        $this->assertSame(['processing'], $this->notificationsOfType('order_status')->pluck('status')->all());
    }

    public function test_wallet_charge_success_and_failure_are_notified(): void
    {
        $this->actingAs($this->customer)->postJson(route('api.wallet.charge'), ['amount' => 1000000])->assertOk();
        $paid = Payment::latest('id')->firstOrFail();
        $this->get(route('api.wallet.callback', ['payment' => $paid->id, 'transaction_id' => $paid->transaction_id, 'status' => 'OK']));

        $this->actingAs($this->customer)->postJson(route('api.wallet.charge'), ['amount' => 500000])->assertOk();
        $cancelled = Payment::latest('id')->firstOrFail();
        $this->get(route('api.wallet.callback', ['payment' => $cancelled->id, 'transaction_id' => $cancelled->transaction_id, 'status' => 'NOK']));

        $charged = $this->notificationsOfType('wallet_charged')->sole();
        $this->assertEquals(1000000, $charged['balance']);
        $this->assertSame('/my/wallet', $charged['link']);

        $failed = $this->notificationsOfType('payment_failed')->sole();
        $this->assertSame('wallet_charge', $failed['purpose']);
        $this->assertStringContainsString('500,000 تومان', $failed['message']);
    }

    public function test_admin_balance_adjustment_is_notified(): void
    {
        $this->customer->wallet()->create(['balance' => 1000000]);

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.users.update', $this->customer->id), ['wallet_balance' => 1500000])
            ->assertOk();
        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.users.update', $this->customer->id), ['wallet_balance' => 1500000])
            ->assertOk();

        $adjusted = $this->notificationsOfType('wallet_adjusted')->sole();
        $this->assertEquals(1000000, $adjusted['previous_balance']);
        $this->assertEquals(1500000, $adjusted['balance']);
        $this->assertEquals(500000, $adjusted['amount']);
    }

    public function test_notification_list_has_pagination_meta(): void
    {
        $this->placeWalletOrder();

        $this->actingAs($this->customer)
            ->getJson(route('api.notifications.index', ['per_page' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.data.0.data.type', 'order_placed');
    }
}
