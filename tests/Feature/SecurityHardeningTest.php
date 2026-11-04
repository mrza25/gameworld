<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\PaymentStatus;
use App\Services\CheckoutService;
use App\Services\Sms\LogSmsSender;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function createVariant(): GameVariant
    {
        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);

        return Game::create(['title' => 'God of War', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 1000000, 'size' => 50, 'is_available' => true]);
    }

    private function runAsProduction(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_database_errors_never_reach_the_customer(): void
    {
        $variant = $this->createVariant();
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 5000000]);
        $sqlError = new QueryException('mysql', 'insert into secret_table values (?)', ['private'], new RuntimeException('boom'));

        $this->mock(CheckoutService::class)
            ->shouldReceive('checkoutWithWallet')->andThrow($sqlError)
            ->shouldReceive('completeWalletCharge')->andThrow($sqlError);

        $this->actingAs($user)
            ->postJson(route('api.orders.checkout'), ['method' => 'wallet', 'items' => [['variantId' => $variant->id]]])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'خطایی رخ داد. لطفاً دوباره تلاش کنید.')
            ->assertDontSee('secret_table');

        $payment = Payment::create(['user_id' => $user->id, 'amount' => 1000, 'method' => 'gateway', 'status' => 'pending', 'transaction_id' => 'FAKE-1']);
        $location = $this->get(route('api.wallet.callback', ['payment' => $payment->id, 'transaction_id' => 'FAKE-1']))->headers->get('Location');

        $this->assertStringNotContainsString('secret_table', urldecode($location));
    }

    public function test_business_rule_messages_still_reach_the_customer(): void
    {
        $variant = $this->createVariant();
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 1]);

        $this->actingAs($user)
            ->postJson(route('api.orders.checkout'), ['method' => 'wallet', 'items' => [['variantId' => $variant->id]]])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Insufficient wallet balance.');
    }

    public function test_fake_gateway_gives_no_free_credit_in_production(): void
    {
        $user = User::factory()->create();
        $pending = $this->actingAs($user)->postJson(route('api.wallet.charge'), ['amount' => 1000000])->assertOk();
        $payment = Payment::findOrFail($pending->json('payment_id'));

        $this->runAsProduction();

        $this->actingAs($user)
            ->postJson(route('api.wallet.charge'), ['amount' => 1000000])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'درگاه پرداخت در حال حاضر فعال نیست.');
        $this->assertSame(1, Payment::count());

        $this->app['auth']->forgetGuards();
        $this->get($pending->json('data.gateway_url'))->assertNotFound();
        $this->get($pending->json('callback_url'));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertEquals(0, (float) $user->wallet()->value('balance'));
    }

    public function test_fake_gateway_can_be_allowed_explicitly_on_a_staging_server(): void
    {
        $this->runAsProduction();
        config(['services.payment.allow_fake_gateway' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('api.wallet.charge'), ['amount' => 1000000])
            ->assertOk();
    }

    public function test_registering_requires_proof_of_owning_the_phone(): void
    {
        $this->postJson(route('api.auth.register'), ['name' => 'Attacker', 'phone' => '09121234567', 'password' => 'long-enough'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('error', 'کد تأیید شماره موبایل الزامی است.');

        $this->postJson(route('api.auth.register'), ['name' => 'Attacker', 'phone' => '09121234567', 'password' => 'long-enough', 'code' => '99999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertDatabaseMissing('users', ['phonenumber' => '09121234567']);

        $code = $this->postJson(route('api.auth.send-otp'), ['phone' => '09121234567'])->json('debug_code');

        $this->postJson(route('api.auth.register'), ['name' => 'Owner', 'phone' => '09121234567', 'password' => 'long-enough', 'code' => $code])
            ->assertCreated();
    }

    public function test_web_registration_is_closed_in_production(): void
    {
        $this->runAsProduction();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), [
            'name' => 'Attacker',
            'phonenumber' => '09121234567',
            'password' => 'long-enough',
            'password_confirmation' => 'long-enough',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['phonenumber' => '09121234567']);
    }

    public function test_banner_upload_only_accepts_images(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        foreach ([
            UploadedFile::fake()->create('shell.php', 1, 'application/x-php'),
            UploadedFile::fake()->create('page.html', 1, 'text/html'),
            UploadedFile::fake()->create('vector.svg', 1, 'image/svg+xml'),
        ] as $file) {
            $this->actingAs($admin)
                ->post(route('api.admin.banners.store'), ['title' => 'x', 'image' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('image');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->actingAs($admin)
            ->post(route('api.admin.banners.store'), ['title' => 'x', 'image' => UploadedFile::fake()->image('banner.jpg')], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertCount(1, Storage::disk('public')->allFiles('banners'));

        $this->actingAs($admin)
            ->postJson(route('api.admin.banners.store'), ['title' => 'x', 'image' => ['not', 'a', 'string']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_links_cannot_run_scripts_or_leave_the_site_unnoticed(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['javascript:alert(1)', '//evil.example', 'data:text/html,x'] as $link) {
            $this->actingAs($admin)
                ->postJson(route('api.admin.banners.store'), ['title' => 'x', 'image' => '/a.jpg', 'link' => $link])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('link');

            $this->actingAs($admin)
                ->postJson(route('api.admin.notifications.store'), ['title' => 'x', 'message' => 'y', 'audience' => 'all', 'link' => $link])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('link');
        }

        foreach (['/products', 'https://example.com/a'] as $link) {
            $this->actingAs($admin)
                ->postJson(route('api.admin.banners.store'), ['title' => 'x', 'image' => '/a.jpg', 'link' => $link])
                ->assertCreated();
        }
    }

    public function test_private_network_origins_are_only_trusted_in_local_development(): void
    {
        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertNotContains('192.168.1.149', config('sanctum.stateful'));

        // With one allowed origin the header always names that origin, which browsers reject for any other.
        $allowedOrigin = $this->withHeaders(['Origin' => 'http://192.168.77.5:3000'])
            ->getJson(route('api.ping'))
            ->assertOk()
            ->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('http://192.168.77.5:3000', $allowedOrigin);

        config(['cors.allowed_origins' => ['https://shop.example']]);

        $this->withHeaders(['Origin' => 'https://shop.example'])
            ->getJson(route('api.ping'))
            ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_tokens_are_not_accepted_from_the_query_string(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->getJson(route('api.auth.me', ['token' => $token]))->assertUnauthorized();
        $this->getJson(route('api.auth.me', ['auth_token' => $token]))->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.auth.me'))->assertOk();
    }

    public function test_otp_is_only_sent_to_iranian_mobile_numbers(): void
    {
        foreach (['12345', '4915112345678', '02188776655', '0912123456'] as $phone) {
            $this->postJson(route('api.auth.send-otp'), ['phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('phone');
        }

        foreach (['09121234567', '+989121234568', '۰۹۱۲۱۲۳۴۵۶۹'] as $phone) {
            $this->postJson(route('api.auth.send-otp'), ['phone' => $phone])->assertOk();
        }
    }

    public function test_one_number_cannot_be_sent_codes_back_to_back(): void
    {
        config(['services.otp.resend_cooldown_seconds' => 60]);

        $this->postJson(route('api.auth.send-otp'), ['phone' => '09121234567'])->assertOk();

        $this->postJson(route('api.auth.send-otp'), ['phone' => '+989121234567'])
            ->assertTooManyRequests()
            ->assertJsonPath('ok', false)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['error', 'retry_after']);

        $this->postJson(route('api.auth.send-otp'), ['phone' => '09127654321'])->assertOk();

        $this->travel(61)->seconds();

        $this->postJson(route('api.auth.send-otp'), ['phone' => '09121234567'])->assertOk();
    }

    public function test_one_number_has_an_hourly_limit_shared_with_phone_changes(): void
    {
        config(['services.otp.hourly_limit' => 2]);
        $user = User::factory()->create(['phonenumber' => '09120000001']);

        $this->postJson(route('api.auth.send-otp'), ['phone' => '09121234567'])->assertOk();
        $this->actingAs($user)->postJson(route('api.auth.phone.otp'), ['phone' => '09121234567'])->assertOk();

        $this->postJson(route('api.auth.send-otp'), ['phone' => '09121234567'])->assertTooManyRequests();
        $this->actingAs($user)->postJson(route('api.auth.phone.otp'), ['phone' => '09121234567'])->assertTooManyRequests();
    }

    public function test_password_guessing_is_limited_per_phone(): void
    {
        config(['services.rate_limits.login_per_minute' => 2]);
        User::factory()->create(['phonenumber' => '09121234567']);

        foreach (['09121234567', '+989121234567'] as $phone) {
            $this->postJson(route('api.auth.login'), ['phone' => $phone, 'password' => 'wrong-password'])->assertUnauthorized();
        }

        $this->postJson(route('api.auth.login'), ['phone' => '۰۹۱۲۱۲۳۴۵۶۷', 'password' => 'wrong-password'])
            ->assertTooManyRequests()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.');

        $this->postJson(route('api.auth.login'), ['phone' => '09127654321', 'password' => 'wrong-password'])->assertUnauthorized();
    }

    public function test_every_api_route_is_rate_limited(): void
    {
        $middleware = app('router')->getRoutes()->getByName('api.ping')->gatherMiddleware();

        $this->assertContains('throttle:api', app('router')->getMiddlewareGroups()['api']);
        $this->assertContains('api', $middleware);
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $current = $user->createToken('this-device');
        $stolen = $user->createToken('other-device');

        $this->withToken($current->plainTextToken)
            ->patchJson(route('api.auth.password'), ['current_password' => 'old-password', 'password' => 'new-password'])
            ->assertOk();

        $this->assertModelMissing($stolen->accessToken);
        $this->assertModelExists($current->accessToken);
    }

    public function test_admin_resetting_a_password_signs_that_user_out(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('admin');
        $user = User::factory()->create();
        $userToken = $user->createToken('device');

        $this->withToken($adminToken->plainTextToken)
            ->patchJson(route('api.admin.users.update', $user->id), ['password' => 'new-password'])
            ->assertOk();

        $this->assertModelMissing($userToken->accessToken);
        $this->assertModelExists($adminToken->accessToken);

        $this->app['auth']->forgetGuards();

        $this->withToken($adminToken->plainTextToken)
            ->patchJson(route('api.admin.users.update', $admin->id), ['password' => 'new-password'])
            ->assertOk();

        $this->assertModelExists($adminToken->accessToken);
    }

    public function test_deleting_a_user_revokes_their_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device');

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('api.admin.users.delete', $user->id))
            ->assertOk();

        $this->assertModelMissing($token->accessToken);
    }

    public function test_admins_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->deleteJson(route('api.admin.users.delete', $admin->id))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'نمی‌توانید حساب خودتان را حذف کنید.');

        $this->assertModelExists($admin);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device');

        $this->withToken($token->plainTextToken)->postJson(route('api.auth.logout'))->assertOk();

        $this->assertModelMissing($token->accessToken);
    }

    public function test_responses_carry_security_headers(): void
    {
        foreach ([route('api.ping'), route('home')] as $url) {
            $this->get($url)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeaderMissing('Strict-Transport-Security');
        }

        $this->get(str_replace('http://', 'https://', route('api.ping')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_login_codes_are_not_logged_in_production(): void
    {
        Log::spy();
        $this->runAsProduction();

        (new LogSmsSender)->send('09121234567', 'code 54321');

        Log::shouldNotHaveReceived('info');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => ! str_contains($message, '54321'));
    }

    public function test_replacing_a_cover_cannot_delete_files_outside_the_games_folder(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('secret.txt', 'keep');
        $game = Game::create(['title' => 'A', 'genre' => 'Action', 'is_available' => true, 'image' => 'games/../secret.txt']);

        $this->actingAs(User::factory()->admin()->create())
            ->patchJson(route('api.admin.games.update', $game->id), ['image' => 'https://example.com/new.jpg'])
            ->assertOk();

        Storage::disk('public')->assertExists('secret.txt');
    }
}
