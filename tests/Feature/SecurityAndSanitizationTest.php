<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAndSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_persian_and_arabic_numerals_are_normalized_seamlessly_in_web_auth(): void
    {
        // 1. Register with Persian digits: ۰۹۱۲۱۱۱۲۲۳۳
        $response = $this->post(route('register.store'), [
            'name' => 'Saeed Test',
            'phonenumber' => '۰۹۱۲۱۱۱۲۲۳۳',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertRedirect(route('home'));
        $this->assertDatabaseHas('users', [
            'phonenumber' => '09121112233',
        ]);

        $this->post(route('logout'));

        // 2. Login with standard English digits
        $loginRes = $this->post(route('login.store'), [
            'phonenumber' => '09121112233',
            'password' => 'secret1234',
        ]);

        $loginRes->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    public function test_admin_privilege_cannot_be_escalated_via_api_registration(): void
    {
        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'Malicious Hacker',
            'phone' => '09129999999',
            'password' => 'password123',
            'is_admin' => true,
            'role' => 'admin',
        ]);

        $response->assertCreated();

        $user = User::where('phonenumber', '09129999999')->firstOrFail();
        $this->assertFalse((bool) $user->is_admin);
    }

    public function test_api_checkout_rejects_cross_platform_variant_tampering(): void
    {
        $ps5Platform = Platform::create(['name' => 'PS5', 'slug' => 'ps5']);
        $xboxPlatform = Platform::create(['name' => 'Xbox One', 'slug' => 'xbox-one']);

        $game = Game::create([
            'title' => 'FIFA 24',
            'slug' => 'fifa-24',
            'genre' => 'Sports',
            'platform' => 'PS5',
            'size' => 50,
            'is_available' => true,
        ]);

        $xboxVariant = GameVariant::create([
            'game_id' => $game->id,
            'platform_id' => $xboxPlatform->id,
            'price' => 45000,
            'size' => 40,
            'is_available' => true,
        ]);

        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 100000]);
        $token = $user->createToken('test')->plainTextToken;

        // User chooses console 'ps5' but maliciously submits Xbox variant ID
        $response = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5',
            'method' => 'wallet',
            'variants' => [$xboxVariant->id],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(100000, (float) $user->fresh()->wallet->balance);
    }

    public function test_negative_or_zero_top_up_amounts_are_strictly_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Zero
        $resZero = $this->withToken($token)->postJson(route('api.wallet.charge'), [
            'amount' => 0,
        ]);
        $resZero->assertStatus(422);

        // Negative
        $resNeg = $this->withToken($token)->postJson(route('api.wallet.charge'), [
            'amount' => -100000,
        ]);
        $resNeg->assertStatus(422);

        // Below minimum (50k)
        $resBelow = $this->withToken($token)->postJson(route('api.wallet.charge'), [
            'amount' => 1000,
        ]);
        $resBelow->assertStatus(422);
    }
}

