<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_profile_update_store_an_optional_email(): void
    {
        $token = $this->postJson(route('api.auth.register'), [
            'name' => 'Ali',
            'phone' => '09121234567',
            'email' => 'ali@example.com',
            'password' => 'secret123',
        ])->assertCreated()->assertJsonPath('user.email', 'ali@example.com')->json('token');

        $this->withToken($token)->getJson(route('api.auth.me'))->assertJsonPath('user.email', 'ali@example.com');

        $this->withToken($token)->patchJson(route('api.auth.profile'), ['email' => 'ali.new@example.com'])
            ->assertOk()
            ->assertJsonPath('user.email', 'ali.new@example.com');

        $this->postJson(route('api.auth.register'), [
            'name' => 'Reza', 'phone' => '09127654321', 'password' => 'secret123',
        ])->assertCreated()->assertJsonPath('user.email', null);
    }

    public function test_email_must_be_valid_and_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('api.auth.profile'), ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->actingAs($user)->patchJson(route('api.auth.profile'), ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_creates_user_with_email_role_and_opening_balance(): void
    {
        $admin = User::factory()->admin()->create();

        $userId = $this->actingAs($admin)->postJson(route('api.admin.users.store'), [
            'name' => 'Sara',
            'phone' => '09351112233',
            'email' => 'sara@example.com',
            'password' => 'secret123',
            'role' => 'admin',
            'wallet_balance' => 50000,
        ])->assertCreated()->json('data.id');

        $user = User::findOrFail($userId);
        $this->assertTrue($user->is_admin);
        $this->assertSame('sara@example.com', $user->email);
        $this->assertEquals(50000, (float) $user->wallet->balance);
        $this->assertDatabaseHas('wallet_transactions', ['wallet_id' => $user->wallet->id, 'type' => 'credit', 'amount' => 50000]);

        $this->actingAs($admin)->patchJson(route('api.admin.users.update', $userId), ['role' => 'user', 'email' => 'sara2@example.com'])
            ->assertOk();
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertSame('sara2@example.com', $user->fresh()->email);
    }

    public function test_admin_cannot_remove_their_own_admin_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patchJson(route('api.admin.users.update', $admin->id), ['role' => 'user'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_admin_user_list_adds_frontend_fields_and_filters_by_role_and_email(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);
        $customer = User::factory()->create(['email' => 'customer@example.com', 'phonenumber' => '09120000001']);
        $customer->wallet()->create(['balance' => 75000]);
        $customer->orders()->create(['console_type' => 'PS5', 'total' => 1000, 'status' => 'pending', 'payment_status' => 'paid']);

        $row = collect($this->actingAs($admin)->getJson(route('api.admin.users'))->assertOk()->json('data.data'))
            ->firstWhere('id', $customer->id);

        $this->assertSame('09120000001', $row['phone']);
        $this->assertSame('09120000001', $row['phonenumber']);
        $this->assertSame('user', $row['role']);
        $this->assertSame('customer@example.com', $row['email']);
        $this->assertEquals(75000, $row['wallet_balance']);
        $this->assertSame(1, $row['orders_count']);

        $this->actingAs($admin)->getJson(route('api.admin.users', ['role' => 'admin']))
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $admin->id);

        $this->actingAs($admin)->getJson(route('api.admin.users', ['search' => 'customer@']))
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $customer->id);
    }
}
