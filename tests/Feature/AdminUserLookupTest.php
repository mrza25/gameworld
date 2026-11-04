<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\PaymentStatus;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin lookups by user, consistent pagination and the password length rule.
 */
class AdminUserLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create(['phonenumber' => '09121234567', 'email' => 'ali@example.com']);
    }

    private function createOrderFor(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'console_type' => 'PS5',
            'total' => 100000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    public function test_admin_can_fetch_a_single_user(): void
    {
        $this->customer->wallet()->create(['balance' => 750000]);
        $this->createOrderFor($this->customer);

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.users.show', $this->customer->id))
            ->assertOk()
            ->assertJsonPath('data.id', $this->customer->id)
            ->assertJsonPath('data.phone', '09121234567')
            ->assertJsonPath('data.email', 'ali@example.com')
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.wallet_balance', 750000)
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonStructure(['data' => ['avatar_url', 'created_at'], 'user']);

        $this->actingAs($this->admin)->getJson(route('api.admin.users.show', 999999))->assertNotFound();
    }

    public function test_admin_orders_and_transactions_filter_by_user(): void
    {
        $other = User::factory()->create();
        $mine = $this->createOrderFor($this->customer);
        $this->createOrderFor($other);

        $wallets = app(WalletService::class);
        $wallets->credit($this->customer, 1000, 'test-1', 'one');
        $wallets->credit($other, 2000, 'test-2', 'two');

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders', ['user_id' => $this->customer->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine->id)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.transactions', ['user_id' => $this->customer->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.reference', 'test-1')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
    }

    public function test_every_paginated_list_returns_meta_and_links(): void
    {
        $this->actingAs($this->customer)
            ->getJson(route('api.wallet.transactions'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['data'], 'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'], 'links']);

        foreach (['api.admin.users', 'api.admin.orders', 'api.admin.games.index'] as $route) {
            $this->actingAs($this->admin)
                ->getJson(route($route))
                ->assertOk()
                ->assertJsonStructure(['data' => ['data', 'meta'], 'meta' => ['total', 'from', 'to'], 'links']);
        }
    }

    public function test_passwords_need_at_least_eight_characters(): void
    {
        $this->postJson(route('api.auth.register'), ['name' => 'Reza', 'phone' => '09125556677', 'password' => 'short12', 'code' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.users.store'), ['name' => 'Reza', 'phone' => '09125556677', 'password' => 'short12'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson(route('api.auth.register'), ['name' => 'Reza', 'phone' => '09125556677', 'password' => 'long-enough', 'code' => '12345'])
            ->assertCreated();
    }
}
