<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_customer_sees_only_own_notifications(): void
    {
        $customerA = User::factory()->create();
        $customerB = User::factory()->create();

        $orderA = Order::create([
            'user_id' => $customerA->id,
            'console_type' => 'PS5',
            'total' => 50000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $orderB = Order::create([
            'user_id' => $customerB->id,
            'console_type' => 'PS5',
            'total' => 60000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $customerA->notify(new OrderStatusUpdated($orderA));
        $customerB->notify(new OrderStatusUpdated($orderB));

        $responseA = $this->actingAs($customerA)->get(route('notifications.index'));
        $responseA->assertOk();
        $responseA->assertSee("Order #{$orderA->id}");
        $responseA->assertDontSee("Order #{$orderB->id}");
    }

    public function test_customer_can_mark_notification_as_read(): void
    {
        $customer = User::factory()->create();

        $order = Order::create([
            'user_id' => $customer->id,
            'console_type' => 'PS5',
            'total' => 50000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $customer->notify(new OrderStatusUpdated($order));
        $notification = $customer->notifications()->first();

        $this->assertNull($notification->read_at);

        $response = $this->actingAs($customer)->patch(route('notifications.read', $notification->id));
        $response->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_customer_cannot_mark_another_users_notification_as_read(): void
    {
        $customerA = User::factory()->create();
        $customerB = User::factory()->create();

        $order = Order::create([
            'user_id' => $customerA->id,
            'console_type' => 'PS5',
            'total' => 50000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $customerA->notify(new OrderStatusUpdated($order));
        $notification = $customerA->notifications()->first();

        $response = $this->actingAs($customerB)->patch(route('notifications.read', $notification->id));
        $response->assertNotFound();
    }
}
