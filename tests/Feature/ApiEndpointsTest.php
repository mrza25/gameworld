<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonStructure(['payment_id', 'transaction_id', 'gateway_url']);

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
                'stats' => ['games', 'orders', 'users', 'pending_orders'],
                'recentOrders',
            ]);

        // 2. Create customer and order
        $customer = User::factory()->create();
        $order = \App\Models\Order::create([
            'user_id' => $customer->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        $item = \App\Models\OrderItem::create([
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
}


