<?php

namespace Tests\Feature;

use App\Helpers\Jalali;
use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function createPlatformAndGame(): array
    {
        $platform = Platform::create([
            'name' => 'PS5',
            'slug' => 'ps5',
        ]);

        $game = Game::create([
            'title' => 'Elden Ring',
            'slug' => 'elden-ring',
            'genre' => 'RPG',
            'developer' => 'FromSoftware',
            'release_date' => '2022-02-25',
            'age_rating' => 16,
            'is_available' => true,
            'platform' => 'PS5',
            'size' => 60,
            'price' => 120000,
        ]);

        $variant = GameVariant::create([
            'game_id' => $game->id,
            'platform_id' => $platform->id,
            'price' => 120000,
            'size' => 60,
            'is_available' => true,
        ]);

        return [$platform, $game, $variant];
    }

    public function test_api_can_list_consoles(): void
    {
        Platform::create(['name' => 'PS5', 'slug' => 'ps5']);
        Platform::create(['name' => 'Xbox Series X', 'slug' => 'xbox-series-x']);

        $response = $this->getJson(route('api.consoles'));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
            ])
            ->assertJsonFragment(['value' => 'ps5'])
            ->assertJsonFragment(['value' => 'xbox-series-x']);
    }

    public function test_api_can_list_products_with_variants_matching_frontend_format(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $response = $this->getJson(route('api.products.index'));

        $response->assertOk()
            ->assertJsonStructure([
                'ok',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'genre',
                        'developer',
                        'available',
                        'rate',
                        'ratingCount',
                        'sales',
                        'modes',
                        'languages',
                        'screenshots',
                        'platforms' => [
                            '*' => [
                                'id',
                                'console',
                                'size',
                                'price',
                                'is_available',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_api_auth_flow_check_phone_register_login_and_me(): void
    {
        // 1. Check phone (non-existing)
        $checkRes1 = $this->postJson(route('api.auth.check-phone'), ['phone' => '09120000000']);
        $checkRes1->assertOk()->assertJson(['ok' => true, 'exists' => false]);

        // 2. Register
        $regRes = $this->postJson(route('api.auth.register'), [
            'name' => 'Ali Reza',
            'phone' => '09120000000',
            'password' => 'secret123',
        ]);
        $regRes->assertCreated()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'phone', 'walletBalance']]);

        $token = $regRes->json('token');

        // 3. Check phone (now existing)
        $checkRes2 = $this->postJson(route('api.auth.check-phone'), ['phone' => '09120000000']);
        $checkRes2->assertOk()->assertJson(['ok' => true, 'exists' => true]);

        // 4. Authenticated me
        $meRes = $this->withToken($token)->getJson(route('api.auth.me'));
        $meRes->assertOk()
            ->assertJson([
                'ok' => true,
                'user' => [
                    'phone' => '09120000000',
                    'walletBalance' => 0,
                ],
            ]);

        // 5. Login
        $loginRes = $this->postJson(route('api.auth.login'), [
            'phone' => '09120000000',
            'password' => 'secret123',
        ]);
        $loginRes->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_api_wallet_top_up_flow(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Initiate charge
        $chargeRes = $this->withToken($token)->postJson(route('api.wallet.charge'), [
            'amount' => 150000,
        ]);
        $chargeRes->assertOk()->assertJsonStructure(['payment_id', 'transaction_id', 'gateway_url']);

        $paymentId = $chargeRes->json('payment_id');
        $transactionId = $chargeRes->json('transaction_id');

        // Complete callback
        $callbackRes = $this->withToken($token)->getJson(route('api.wallet.callback', [
            'payment' => $paymentId,
            'transaction_id' => $transactionId,
        ]));

        $callbackRes->assertOk()->assertJson(['ok' => true, 'balance' => 150000]);

        // Check wallet index
        $indexRes = $this->withToken($token)->getJson(route('api.wallet.index'));
        $indexRes->assertOk()
            ->assertJson([
                'ok' => true,
                'balance' => 150000,
            ])
            ->assertJsonCount(1, 'transactions');
    }

    public function test_api_order_checkout_with_wallet(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 200000]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5',
            'method' => 'wallet',
            'variants' => [$variant->id],
        ]);

        $response->assertCreated()
            ->assertJson([
                'ok' => true,
                'method' => 'wallet',
            ])
            ->assertJsonStructure(['order_id', 'order']);

        $this->assertEquals(80000, (float) $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_api_order_checkout_rejects_insufficient_wallet_balance(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 10000]); // Only 10k, while variant is 120k
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5',
            'method' => 'wallet',
            'variants' => [$variant->id],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);

        $this->assertEquals(10000, (float) $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_api_order_checkout_with_gateway_and_callback(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // 1. Checkout with gateway -> pending payment, zero orders created yet
        $response = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5',
            'method' => 'gateway',
            'variants' => [$variant->id],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'method' => 'gateway',
                'amount' => 120000,
            ])
            ->assertJsonStructure(['payment_id', 'transaction_id', 'gateway_url', 'action_url', 'frontend_redirect_url', 'params']);

        $paymentId = $response->json('payment_id');
        $transactionId = $response->json('transaction_id');

        // Business rule verification: zero orders must exist before callback verification
        $this->assertDatabaseCount('orders', 0);

        // 2. Gateway callback verification -> creates order atomically
        $callbackRes = $this->withToken($token)->getJson(route('api.orders.callback', [
            'payment' => $paymentId,
            'transaction_id' => $transactionId,
        ]));

        $callbackRes->assertOk()
            ->assertJson([
                'ok' => true,
            ])
            ->assertJsonStructure(['order_id', 'order']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'paid',
        ]);
    }

    public function test_api_order_checkout_validates_standard_method_and_persian_messages(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 500000]);
        $token = $user->createToken('test')->plainTextToken;

        // 1. Missing method pays from the wallet when its balance covers the total
        $res1 = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5',
            'variants' => [$variant->id],
        ]);
        $res1->assertCreated()->assertJsonPath('data.payment_method', 'wallet');

        // 2. Invalid method returns 422 with Persian message
        $res2 = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5',
            'method' => 'invalid_method',
            'variants' => [$variant->id],
        ]);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['method'])
            ->assertJsonFragment(['method' => ['روش پرداخت باید کیف پول یا درگاه بانکی باشد.']]);

        // 3. Missing console is inferred from the selected variants
        $res3 = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'method' => 'wallet',
            'variants' => [$variant->id],
        ]);
        $res3->assertCreated()->assertJsonPath('data.order.console', 'ps5');

        // 4. Standard valid payload succeeds cleanly
        $res4 = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5',
            'method' => 'wallet',
            'variants' => [$variant->id],
        ]);
        $res4->assertCreated()->assertJson(['ok' => true, 'method' => 'wallet']);
    }

    public function test_api_admin_dashboard_and_orders_management(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('admin-test')->plainTextToken;

        // 1. Admin dashboard
        $dashRes = $this->withToken($adminToken)->getJson(route('api.admin.dashboard'));
        $dashRes->assertOk()
            ->assertJsonStructure([
                'ok',
                'stats' => ['games', 'orders', 'users', 'pending_orders', 'total_orders', 'today_orders', 'total_users', 'total_games'],
                'recentOrders',
            ]);

        $statsRes = $this->withToken($adminToken)->getJson(route('api.admin.dashboard-stats'));
        $statsRes->assertOk()
            ->assertJsonStructure(['ok', 'stats' => ['total_orders', 'today_orders', 'total_users', 'total_games'], 'total_orders', 'today_orders', 'total_users', 'total_games']);

        // Test admin games list endpoint
        $adminGamesRes = $this->withToken($adminToken)->getJson(route('api.admin.games.index'));
        $adminGamesRes->assertOk()->assertJsonStructure(['ok', 'data']);

        // 2. Create customer and order
        $customer = User::factory()->create();
        $order = Order::create([
            'user_id' => $customer->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'game_id' => $game->id,
            'game_variant_id' => $variant->id,
            'price' => 120000,
            'installation_status' => 'pending',
        ]);

        // 3. Admin list orders
        $ordersRes = $this->withToken($adminToken)->getJson(route('api.admin.orders'));
        $ordersRes->assertOk()
            ->assertJsonStructure(['ok', 'data' => ['data']]);

        // 4. Admin update order status
        $statusRes = $this->withToken($adminToken)->patchJson(route('api.admin.orders.status', $order->id), [
            'status' => 'processing',
        ]);
        $statusRes->assertOk()->assertJson(['ok' => true]);
        $this->assertEquals('processing', $order->fresh()->status->value);

        // 5. Admin update item installation status -> installs item, auto syncs order to completed
        $installRes = $this->withToken($adminToken)->patchJson(
            route('api.admin.orders.items.installation', ['id' => $order->id, 'itemId' => $item->id]),
            ['installation_status' => 'installed']
        );
        $installRes->assertOk()->assertJson(['ok' => true, 'order_status' => 'completed']);
        $this->assertEquals('installed', $item->fresh()->installation_status);
        $this->assertEquals('completed', $order->fresh()->status->value);
    }

    public function test_api_admin_endpoints_blocked_for_regular_customers(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('cust-test')->plainTextToken;

        $response = $this->withToken($token)->getJson(route('api.admin.dashboard'));
        $response->assertForbidden();
    }

    public function test_api_otp_flow_and_login_with_otp_and_reset_password(): void
    {
        // 1. Send OTP
        $sendRes = $this->postJson(route('api.auth.send-otp'), [
            'phone' => '09129998877',
        ]);
        $sendRes->assertOk()->assertJson(['ok' => true]);
        $code = $sendRes->json('debug_code');
        $this->assertNotNull($code);

        // 2. Verify OTP
        $verifyRes = $this->postJson(route('api.auth.verify-otp'), [
            'phone' => '09129998877',
            'code' => $code,
        ]);
        $verifyRes->assertOk()->assertJson(['ok' => true, 'valid' => true]);

        // 3. Login with OTP (creates new user)
        $loginRes = $this->postJson(route('api.auth.login-with-otp'), [
            'phone' => '09129998877',
            'code' => $code,
            'name' => 'کاربر تستی جدید',
        ]);
        $loginRes->assertOk()
            ->assertJson([
                'ok' => true,
                'is_new_user' => true,
            ])
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'phone', 'avatar', 'wallet_balance']]);

        $token = $loginRes->json('token');

        // 4. Update Profile
        $profileRes = $this->withToken($token)->patchJson(route('api.auth.profile'), [
            'name' => 'نام ویرایش شده',
        ]);
        $profileRes->assertOk()->assertJson(['ok' => true, 'user' => ['name' => 'نام ویرایش شده']]);

        // 5. Send OTP again for reset password
        $sendRes2 = $this->postJson(route('api.auth.send-otp'), ['phone' => '09129998877']);
        $code2 = $sendRes2->json('debug_code');

        $resetRes = $this->postJson(route('api.auth.reset-password'), [
            'phone' => '09129998877',
            'code' => $code2,
            'password' => 'newpassword123',
        ]);
        $resetRes->assertOk()->assertJson(['ok' => true]);
    }

    public function test_api_checkout_supports_console_note(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $customer = User::factory()->create();
        $customer->wallet()->create(['balance' => 200000]);
        $token = $customer->createToken('cust-test')->plainTextToken;

        $response = $this->withToken($token)->postJson(route('api.orders.checkout'), [
            'console' => $platform->slug,
            'console_note' => 'PS3 Slim 500GB',
            'method' => 'wallet',
            'variants' => [$variant->id],
        ]);

        $response->assertCreated()
            ->assertJson([
                'ok' => true,
                'method' => 'wallet',
                'order' => [
                    'consoleNote' => 'PS3 Slim 500GB',
                ],
            ]);

        $this->assertDatabaseHas('orders', [
            'console_note' => 'PS3 Slim 500GB',
        ]);
    }

    public function test_api_banners_and_game_rating_and_notifications(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $customer = User::factory()->create();
        $token = $customer->createToken('cust-test')->plainTextToken;

        // 1. Public banners list
        $bannerRes = $this->getJson(route('api.banners'));
        $bannerRes->assertOk()->assertJson(['ok' => true]);

        // 2. Rate game
        $rateRes = $this->withToken($token)->postJson(route('api.games.rate', $game->id), [
            'rating' => 5,
            'comment' => 'عالی بود!',
        ]);
        $rateRes->assertOk()->assertJson(['ok' => true, 'average_rating' => 5]);

        // 3. User notifications
        $notifRes = $this->withToken($token)->getJson(route('api.notifications.index'));
        $notifRes->assertOk()->assertJson(['ok' => true]);

        // 4. User transactions
        $txRes = $this->withToken($token)->getJson(route('api.wallet.transactions'));
        $txRes->assertOk()->assertJson(['ok' => true]);
    }

    public function test_api_admin_can_manage_users_games_banners_and_transactions(): void
    {
        $platform = Platform::create(['name' => 'PS5 Pro', 'slug' => 'ps5-pro']);
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('admin-test')->plainTextToken;

        // 1. Admin create user
        $createUserRes = $this->withToken($adminToken)->postJson(route('api.admin.users.store'), [
            'name' => 'کاربر جدید ادمین',
            'phone' => '09127776655',
            'password' => 'password123',
            'is_admin' => false,
        ]);
        $createUserRes->assertCreated()->assertJson(['ok' => true]);
        $newUserId = $createUserRes->json('data.id');

        // 2. Admin update user
        $updateUserRes = $this->withToken($adminToken)->patchJson(route('api.admin.users.update', $newUserId), [
            'wallet_balance' => 150000,
        ]);
        $updateUserRes->assertOk()->assertJson(['ok' => true]);
        $this->assertEquals(150000, User::find($newUserId)->wallet->balance);

        // 3. Admin create game
        $createGameRes = $this->withToken($adminToken)->postJson(route('api.admin.games.store'), [
            'title' => 'God of War Ragnarok',
            'genre' => 'Action',
            'variants' => [
                [
                    'platform_id' => $platform->id,
                    'price' => 250000,
                    'size' => 90,
                ],
            ],
        ]);
        $createGameRes->assertCreated()->assertJson(['ok' => true]);
        $newGameId = $createGameRes->json('data.id');

        // 4. Admin update game
        $updateGameRes = $this->withToken($adminToken)->patchJson(route('api.admin.games.update', $newGameId), [
            'description' => 'Updated description',
        ]);
        $updateGameRes->assertOk()->assertJson(['ok' => true]);

        // 5. Admin banners
        $bannerRes = $this->withToken($adminToken)->postJson(route('api.admin.banners.store'), [
            'title' => 'تخفیف ویژه نوروز',
            'image' => '/images/banner.jpg',
            'link' => '/products',
        ]);
        $bannerRes->assertCreated()->assertJson(['ok' => true]);
        $bannerId = $bannerRes->json('id');

        $delBannerRes = $this->withToken($adminToken)->deleteJson(route('api.admin.banners.delete', $bannerId));
        $delBannerRes->assertOk()->assertJson(['ok' => true]);

        // 6. Admin transactions
        $txRes = $this->withToken($adminToken)->getJson(route('api.admin.transactions'));
        $txRes->assertOk()->assertJson(['ok' => true]);

        // 7. Admin delete game
        $delGameRes = $this->withToken($adminToken)->deleteJson(route('api.admin.games.delete', $newGameId));
        $delGameRes->assertOk()->assertJson(['ok' => true]);

        // 8. Admin delete user
        $delUserRes = $this->withToken($adminToken)->deleteJson(route('api.admin.users.delete', $newUserId));
        $delUserRes->assertOk()->assertJson(['ok' => true]);
    }

    public function test_api_register_with_otp_verification(): void
    {
        // Send OTP
        $sendRes = $this->postJson(route('api.auth.send-otp'), ['phone' => '09123334455']);
        $code = $sendRes->json('debug_code');

        // Invalid code should fail
        $invalidRes = $this->postJson(route('api.auth.register'), [
            'name' => 'تست با کد نامعتبر',
            'phone' => '09123334455',
            'password' => 'password123',
            'code' => '99999',
        ]);
        $invalidRes->assertStatus(422)->assertJsonValidationErrors(['code']);

        // Verify without consuming
        $verifyRes = $this->postJson(route('api.auth.verify-otp'), [
            'phone' => '09123334455',
            'code' => $code,
        ]);
        $verifyRes->assertOk()->assertJson(['valid' => true]);

        // Register with valid code
        $regRes = $this->postJson(route('api.auth.register'), [
            'name' => 'کاربر موفق',
            'phone' => '09123334455',
            'password' => 'password123',
            'code' => $code,
        ]);
        $regRes->assertCreated()->assertJson(['ok' => true]);
        $this->assertEquals(Jalali::fromDateTime(now()), $regRes->json('user.joined_at'));
    }

    public function test_api_unified_order_format_and_jalali_date(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('admin')->plainTextToken;

        $customer = User::factory()->create();
        $order = Order::create([
            'user_id' => $customer->id,
            'console_type' => 'PS5',
            'console_note' => 'PS5 Digital',
            'total' => 120000,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $res = $this->withToken($adminToken)->getJson(route('api.admin.orders.show', $order->id));
        $res->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'id' => $order->id,
                    'userId' => $customer->id,
                    'console' => 'ps5',
                    'consoleLabel' => 'PS5',
                    'consoleNote' => 'PS5 Digital',
                    'status' => 'در حال بررسی',
                    'statusRaw' => 'pending',
                    'date' => Jalali::fromDateTime(now()),
                ],
            ]);
    }

    public function test_api_ping_and_health_endpoints(): void
    {
        $ping = $this->getJson(route('api.ping'));
        $ping->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'pong',
            ])
            ->assertJsonStructure(['ok', 'status', 'timestamp']);

        $health = $this->getJson(route('api.health'));
        $health->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'healthy',
                'service' => 'gameworld-api',
                'database' => 'connected',
            ]);
    }

    public function test_api_cookie_based_authentication_flow(): void
    {
        $user = User::factory()->create([
            'phonenumber' => '09129998877',
            'password' => bcrypt('secret123'),
        ]);

        $loginRes = $this->postJson(route('api.auth.login'), [
            'phone' => '09129998877',
            'password' => 'secret123',
        ]);

        $loginRes->assertOk()
            ->assertCookie('gw_token');

        $token = $loginRes->json('token');
        $this->assertNotEmpty($token);

        // Access protected route using gw_token cookie alone (no Authorization header)
        $meRes = $this->withCredentials()
            ->withUnencryptedCookies(['gw_token' => $token])
            ->getJson(route('api.auth.me'));

        $meRes->assertOk()
            ->assertJson([
                'ok' => true,
                'user' => [
                    'id' => $user->id,
                    'phone' => '09129998877',
                ],
            ]);

        // Logout clears cookie
        $logoutRes = $this->withToken($token)
            ->postJson(route('api.auth.logout'));

        $logoutRes->assertOk();
    }

    public function test_api_new_features_developer_release_date_screenshots_persian_genre_ratings_and_otp(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();
        $game->update([
            'developer' => 'FromSoftware',
            'release_date' => '2022-02-25',
            'genre' => 'RPG',
            'screenshots' => ['storage/screenshots/gow-1.jpg'],
        ]);

        // 1. Genres endpoint
        $genresRes = $this->getJson(route('api.genres'));
        $genresRes->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonFragment(['name' => 'اکشن'])
            ->assertJsonFragment(['name' => 'نقش‌آفرینی']);

        // 2. Product returns developer, releaseDate, Persian genre, screenshots
        $productRes = $this->getJson(route('api.products.show', $game->slug));
        $productRes->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'id' => $game->id,
                    'genre' => 'نقش‌آفرینی',
                    'developer' => 'FromSoftware',
                    'releaseDate' => '2022-02-25',
                ],
            ]);
        $this->assertNotEmpty($productRes->json('data.screenshots'));

        // 3. Unauthenticated rating is rejected
        $guestRate = $this->postJson(route('api.products.rate', $game->slug), [
            'rating' => 5,
        ]);
        $guestRate->assertUnauthorized();

        // 4. Authenticated user can rate game via ID or slug
        $user = User::factory()->create();
        $token = $user->createToken('rating-user')->plainTextToken;

        $authRate = $this->withToken($token)->postJson(route('api.products.rate', $game->slug), [
            'rate' => 5,
            'comment' => 'عالی بود',
        ]);
        $authRate->assertOk()
            ->assertJson([
                'ok' => true,
                'userRating' => 5,
                'rate' => 5,
            ]);

        // 5. Subsequent game view shows current user's rating
        $viewAgain = $this->withToken($token)->getJson(route('api.products.show', $game->slug));
        $this->assertEquals(5, $viewAgain->json('data.userRating'));

        // 6. OTP login with Persian digits
        Cache::put('otp:09121112233', '54321', now()->addMinutes(2));
        $otpRes = $this->postJson(route('api.auth.login-with-otp'), [
            'phone' => '۰۹۱۲۱۱۱۲۲۳۳',
            'code' => '۵۴۳۲۱',
        ]);
        $otpRes->assertOk()
            ->assertJson([
                'ok' => true,
                'is_new_user' => true,
            ])
            ->assertCookie('gw_token');
        $this->assertDatabaseHas('users', ['phonenumber' => '09121112233']);
    }

    public function test_api_flexible_order_creation_and_admin_game_creation(): void
    {
        [$platform, $game, $variant] = $this->createPlatformAndGame();

        $customer = User::factory()->create();
        $customer->wallet()->create(['balance' => 500000]);
        $token = $customer->createToken('flex-cust')->plainTextToken;

        // 1. Order creation via POST /api/orders with aliased keys
        $orderRes = $this->withToken($token)->postJson('/api/orders', [
            'platform' => $platform->slug,
            'payment_method' => 'wallet',
            'game_ids' => [$game->id],
        ]);

        $orderRes->assertCreated()
            ->assertJson([
                'ok' => true,
                'method' => 'wallet',
            ]);
        $this->assertDatabaseCount('orders', 1);

        // Reset auth guard between customer and admin requests in test
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        // 2. Admin create game with simplified payload (string platforms, no complex variants)
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('flex-admin')->plainTextToken;

        $createGameRes = $this->withToken($adminToken)->postJson(route('api.admin.games.store'), [
            'name' => 'Witcher 4',
            'genre' => 'RPG',
            'price' => 1800000,
            'size' => 70,
            'platforms' => [$platform->slug],
        ]);

        $createGameRes->assertCreated()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'title' => 'Witcher 4',
                ],
            ]);
        $this->assertDatabaseHas('games', ['title' => 'Witcher 4']);
    }

    public function test_admin_game_detail_view_and_attributes_persistence_and_update(): void
    {
        $platform = Platform::create(['name' => 'PS5 Pro', 'slug' => 'ps5-pro']);
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('game-admin')->plainTextToken;

        // 1. Create game with full attributes
        $createRes = $this->withToken($adminToken)->postJson(route('api.admin.games.store'), [
            'name' => 'Silent Hill 2 Remake',
            'brand' => 'Konami',
            'genre' => 'ترسناک',
            'price' => 2200000,
            'size' => 55.5,
            'age' => 18,
            'description' => 'A classic psychological horror game remade.',
            'modes' => ['تک‌نفره'],
            'languages' => ['فارسی', 'انگلیسی'],
            'platforms' => [$platform->slug],
        ]);

        $createRes->assertCreated()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'title' => 'Silent Hill 2 Remake',
                    'brand' => 'Konami',
                    'developer' => 'Konami',
                    'price' => 2200000,
                    'size' => 55.5,
                    'age' => 18,
                    'age_rating' => 18,
                    'modes' => ['تک‌نفره'],
                    'languages' => ['فارسی', 'انگلیسی'],
                ],
            ]);

        $gameId = $createRes->json('data.id');
        $gameSlug = $createRes->json('data.slug');

        $this->assertDatabaseHas('games', [
            'id' => $gameId,
            'title' => 'Silent Hill 2 Remake',
            'developer' => 'Konami',
            'age_rating' => 18,
            'price' => 2200000,
            'size' => 55.5,
        ]);

        // 2. View game details by ID in admin
        $viewById = $this->withToken($adminToken)->getJson(route('api.admin.games.show', $gameId));
        $viewById->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'id' => $gameId,
                    'price' => 2200000,
                    'size' => 55.5,
                    'developer' => 'Konami',
                ],
            ]);

        // 3. View game details by slug in admin
        $viewBySlug = $this->withToken($adminToken)->getJson(route('api.admin.games.show', $gameSlug));
        $viewBySlug->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'slug' => $gameSlug,
                    'price' => 2200000,
                ],
            ]);

        // 4. Update game via PUT /api/admin/games/{slug}
        $updateRes = $this->withToken($adminToken)->putJson(route('api.admin.games.update', $gameSlug), [
            'price' => 2500000,
            'size' => 60.0,
            'brand' => 'Bloober Team',
            'modes' => ['تک‌نفره', 'آنلاین'],
        ]);

        $updateRes->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'price' => 2500000,
                    'size' => 60.0,
                    'developer' => 'Bloober Team',
                    'brand' => 'Bloober Team',
                    'modes' => ['تک‌نفره', 'آنلاین'],
                ],
            ]);

        $this->assertDatabaseHas('games', [
            'id' => $gameId,
            'price' => 2500000,
            'size' => 60.0,
            'developer' => 'Bloober Team',
        ]);

        $this->assertDatabaseHas('game_variants', [
            'game_id' => $gameId,
            'price' => 2500000,
            'size' => 60.0,
        ]);

        // 5. Public storefront show by slug
        $publicShow = $this->getJson(route('api.products.show', $gameSlug));
        $publicShow->assertOk()
            ->assertJson([
                'ok' => true,
                'data' => [
                    'price' => 2500000,
                    'size' => 60.0,
                    'brand' => 'Bloober Team',
                ],
            ]);
    }
}
