<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the order and wallet API exactly as the Next.js customer panel uses it.
 */
class FrontendOrderContractTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private Game $game;

    private GameVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'http://frontend.test']);

        $this->platform = Platform::firstOrCreate(['slug' => 'ps5-copy'], ['name' => 'PlayStation 5 (کپی‌خور)']);
        $this->game = Game::create([
            'title' => 'God of War Ragnarök',
            'slug' => 'god-of-war',
            'genre' => 'Action',
            'image' => 'games/gow.jpg',
            'is_available' => true,
        ]);
        $this->variant = $this->game->variants()->create([
            'platform_id' => $this->platform->id,
            'price' => 850000,
            'size' => 120,
            'is_available' => true,
        ]);
    }

    private function checkout(User $user, string $method)
    {
        return $this->actingAs($user)->postJson(route('api.orders.checkout'), [
            'console' => 'ps5-copy',
            'console_note' => 'PS3 Slim',
            'method' => $method,
            'variants' => [$this->variant->id],
        ]);
    }

    public function test_product_variants_reference_platform_by_slug(): void
    {
        $this->getJson(route('api.products.index'))
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.id', $this->variant->id)
            ->assertJsonPath('data.0.variants.0.platform_id', 'ps5-copy')
            ->assertJsonPath('data.0.variants.0.platform', 'ps5-copy')
            ->assertJsonPath('data.0.variants.0.is_available', true);
    }

    public function test_wallet_checkout_returns_order_id_inside_data(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 1000000]);

        $response = $this->checkout($user, 'wallet')->assertCreated();

        $orderId = Order::where('user_id', $user->id)->value('id');
        $response->assertJsonPath('ok', true)
            ->assertJsonPath('data.method', 'wallet')
            ->assertJsonPath('data.order_id', $orderId)
            ->assertJsonPath('data.order.consoleNote', 'PS3 Slim')
            ->assertJsonPath('order_id', $orderId);
        $this->assertNotEmpty($response->json('data.message'));
    }

    public function test_insufficient_wallet_balance_returns_error_string(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 1000]);

        $this->checkout($user, 'wallet')
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['error']);
    }

    public function test_gateway_checkout_returns_gateway_url_inside_data(): void
    {
        $user = User::factory()->create();

        $response = $this->checkout($user, 'gateway')->assertOk();

        $response->assertJsonPath('data.method', 'gateway')
            ->assertJsonPath('data.order_id', null)
            ->assertJsonPath('data.payment_id', Payment::firstOrFail()->id);
        $this->assertStringContainsString('/payments/fake-gateway/', $response->json('data.gateway_url'));
        $this->assertStringContainsString('/api/orders/payment/callback', $response->json('callback_url'));
    }

    public function test_browser_returning_from_gateway_without_token_completes_the_order(): void
    {
        $user = User::factory()->create();
        $callbackUrl = $this->checkout($user, 'gateway')->json('callback_url');

        $this->app['auth']->forgetGuards();
        $response = $this->get($callbackUrl);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect("http://frontend.test/my/orders?payment=success&order_id={$order->id}");
    }

    public function test_gateway_callback_with_wrong_transaction_id_does_not_create_order(): void
    {
        $user = User::factory()->create();
        $paymentId = $this->checkout($user, 'gateway')->json('data.payment_id');

        $this->app['auth']->forgetGuards();
        $response = $this->get(route('api.orders.callback', ['payment' => $paymentId, 'transaction_id' => 'FAKE-GUESS']));

        $this->assertStringStartsWith('http://frontend.test/my/orders?payment=failed', $response->headers->get('Location'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_public_callback_does_not_leak_paid_order_without_matching_transaction_id(): void
    {
        $user = User::factory()->create();
        $callbackUrl = $this->checkout($user, 'gateway')->json('callback_url');
        $paymentId = Payment::firstOrFail()->id;
        $this->get($callbackUrl);

        $this->app['auth']->forgetGuards();
        $this->getJson(route('api.orders.callback', ['payment' => $paymentId, 'transaction_id' => 'FAKE-GUESS']))
            ->assertUnprocessable()
            ->assertJsonMissingPath('order');
        $this->getJson(route('api.wallet.callback', ['payment' => $paymentId, 'transaction_id' => 'FAKE-GUESS']))
            ->assertUnprocessable()
            ->assertJsonMissingPath('balance');
    }

    public function test_wallet_charge_returns_gateway_url_and_public_callback_credits_wallet(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('api.wallet.charge'), [
            'amount' => 1000000,
            'payment_method' => 'gateway',
        ])->assertOk()->assertJsonPath('data.method', 'gateway')->assertJsonPath('data.authority', Payment::firstOrFail()->transaction_id);

        $this->app['auth']->forgetGuards();
        $this->get($response->json('callback_url'))
            ->assertRedirect('http://frontend.test/my/wallet?payment=success&balance=1000000');

        $this->assertEquals(1000000, (float) $user->wallet()->value('balance'));
    }

    public function test_wallet_summary_is_available_inside_data(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 2500000]);

        $this->actingAs($user)->getJson(route('api.wallet.index'))
            ->assertOk()
            ->assertJsonPath('data.balance', 2500000)
            ->assertJsonPath('data.transactions', [])
            ->assertJsonPath('balance', 2500000);
    }

    public function test_order_items_include_game_and_variant_objects_and_payment_method(): void
    {
        $this->platform->update(['short' => 'PS5 کپی‌خور', 'brand' => 'playstation']);
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 1000000]);
        $orderId = $this->checkout($user, 'wallet')->json('data.order_id');

        $this->actingAs($user)->getJson(route('api.orders.show', "ORD-{$orderId}"))
            ->assertOk()
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.paymentMethod', 'wallet')
            ->assertJsonPath('data.items.0.variant_id', $this->variant->id)
            ->assertJsonPath('data.items.0.game.slug', 'god-of-war')
            ->assertJsonPath('data.items.0.game.image', url('storage/games/gow.jpg'))
            ->assertJsonPath('data.items.0.variant.platform_id', 'ps5-copy')
            ->assertJsonPath('data.items.0.variant.platform_info.short', 'PS5 کپی‌خور')
            ->assertJsonPath('data.items.0.variant.size', 120);
    }

    public function test_order_list_filters_by_status_and_console_and_paginates_on_request(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 5000000]);
        foreach (range(1, 3) as $i) {
            $this->checkout($user, 'wallet')->assertCreated();
        }
        Order::where('user_id', $user->id)->latest('id')->first()->update(['status' => 'processing']);

        $this->actingAs($user)->getJson(route('api.orders.index', ['status' => 'pending']))
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($user)->getJson(route('api.orders.index', ['console' => 'ps5-copy']))
            ->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($user)->getJson(route('api.orders.index', ['console' => 'ps4']))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($user)->getJson(route('api.orders.index'))
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonMissingPath('meta');
        $this->actingAs($user)->getJson(route('api.orders.index', ['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_order_items_include_variant_size_and_game_image(): void
    {
        $user = User::factory()->create();
        $user->wallet()->create(['balance' => 1000000]);
        $this->checkout($user, 'wallet')->assertCreated();

        $this->actingAs($user)->getJson(route('api.orders.index'))
            ->assertOk()
            ->assertJsonPath('data.0.console', 'ps5-copy')
            ->assertJsonPath('data.0.items.0.size', 120)
            ->assertJsonPath('data.0.items.0.image', url('storage/games/gow.jpg'));
    }
}
