<?php

namespace Tests\Feature;

use App\Jobs\SendOtpSms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PhoneChangeTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PHONE = '09127778899';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['phonenumber' => '09121112233']);
    }

    private function requestCode(User $user, string $phone = self::NEW_PHONE): string
    {
        return $this->actingAs($user)
            ->postJson(route('api.auth.phone.otp'), ['phone' => $phone])
            ->assertOk()
            ->json('debug_code');
    }

    public function test_profile_update_rejects_a_new_phone_without_a_code(): void
    {
        $this->actingAs($this->user)
            ->patchJson(route('api.auth.profile'), ['name' => 'Ali', 'phone' => self::NEW_PHONE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame('09121112233', $this->user->fresh()->phonenumber);
        $this->assertNotSame('Ali', $this->user->fresh()->name);
    }

    public function test_profile_update_accepts_the_unchanged_phone(): void
    {
        $this->actingAs($this->user)
            ->patchJson(route('api.auth.profile'), ['name' => 'Ali', 'phone' => '+989121112233'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ali')
            ->assertJsonPath('user.phone', '09121112233');
    }

    public function test_phone_changes_only_after_the_code_sent_to_the_new_number_is_confirmed(): void
    {
        Bus::fake([SendOtpSms::class]);

        $code = $this->requestCode($this->user);

        Bus::assertDispatched(SendOtpSms::class, fn (SendOtpSms $job) => $job->phone === self::NEW_PHONE && $job->code === $code);

        $wrongCode = $code === '99999' ? '99998' : '99999';
        $this->actingAs($this->user)
            ->postJson(route('api.auth.phone.verify'), ['phone' => self::NEW_PHONE, 'code' => $wrongCode])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertSame('09121112233', $this->user->fresh()->phonenumber);

        $this->actingAs($this->user)
            ->postJson(route('api.auth.phone.verify'), ['phone' => self::NEW_PHONE, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.phone', self::NEW_PHONE);
        $this->assertSame(self::NEW_PHONE, $this->user->fresh()->phonenumber);

        $this->actingAs($this->user)
            ->postJson(route('api.auth.phone.verify'), ['phone' => '09127778800', 'code' => $code])
            ->assertUnprocessable();
    }

    public function test_a_phone_change_code_only_works_for_the_account_that_requested_it(): void
    {
        $code = $this->requestCode($this->user);
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->postJson(route('api.auth.phone.verify'), ['phone' => self::NEW_PHONE, 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertNotSame(self::NEW_PHONE, $otherUser->fresh()->phonenumber);
    }

    public function test_a_login_code_cannot_be_used_to_change_the_phone(): void
    {
        $loginCode = $this->postJson(route('api.auth.send-otp'), ['phone' => self::NEW_PHONE])->json('debug_code');

        $this->actingAs($this->user)
            ->postJson(route('api.auth.phone.verify'), ['phone' => self::NEW_PHONE, 'code' => $loginCode])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_cannot_request_a_code_for_a_taken_or_unchanged_number(): void
    {
        User::factory()->create(['phonenumber' => self::NEW_PHONE]);

        $this->actingAs($this->user)
            ->postJson(route('api.auth.phone.otp'), ['phone' => self::NEW_PHONE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->actingAs($this->user)
            ->postJson(route('api.auth.phone.otp'), ['phone' => '09121112233'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }
}
