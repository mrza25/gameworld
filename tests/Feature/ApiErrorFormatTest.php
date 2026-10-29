<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_errors_include_ok_and_error_alongside_field_errors(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('api.orders.checkout'), [])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['ok', 'error', 'message', 'errors']);
    }

    public function test_unauthenticated_and_not_found_errors_include_ok_and_error(): void
    {
        $this->getJson(route('api.wallet.index'))
            ->assertUnauthorized()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Unauthenticated.');

        $this->getJson(route('api.products.show', 'no-such-game'))
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['error']);
    }

    public function test_hand_written_error_responses_are_left_untouched(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 0]);

        $this->actingAs($user)
            ->patchJson(route('api.auth.password'), ['current_password' => 'wrong-password', 'password' => 'new-password'])
            ->assertUnprocessable()
            ->assertExactJson(['ok' => false, 'error' => 'رمز عبور فعلی نادرست است.']);
    }
}
