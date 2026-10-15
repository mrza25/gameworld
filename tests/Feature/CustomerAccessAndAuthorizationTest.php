<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAccessAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_customer_routes(): void
    {
        $this->get(route('orders.index'))->assertRedirect(route('login'));
        $this->get(route('orders.create'))->assertRedirect(route('login'));
        $this->get(route('wallet.index'))->assertRedirect(route('login'));
        $this->get(route('checkout.index'))->assertRedirect(route('login'));
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $order = Order::create([
            'user_id' => $owner->id,
            'console_type' => 'PS5',
            'total' => 50000,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $response = $this->actingAs($stranger)->get(route('orders.show', $order));

        $response->assertForbidden();
    }

    public function test_customer_can_view_own_order(): void
    {
        $owner = User::factory()->create();

        $order = Order::create([
            'user_id' => $owner->id,
            'console_type' => 'PS5',
            'total' => 50000,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $response = $this->actingAs($owner)->get(route('orders.show', $order));

        $response->assertOk();
    }

    public function test_customer_cannot_verify_another_customers_payment(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $owner->id,
            'amount' => 50000,
            'method' => PaymentMethod::Gateway,
            'status' => PaymentStatus::Pending,
            'transaction_id' => 'TX-STRANGER-TEST',
        ]);

        $response = $this->actingAs($stranger)->get(route('payment.callback', [
            'payment' => $payment->id,
            'transaction_id' => 'TX-STRANGER-TEST',
        ]));

        $response->assertForbidden();
    }
}

