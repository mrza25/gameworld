<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Platform;
use App\Models\User;
use App\Services\CardPaymentService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What an order and the money around it keep on record, whatever changes later.
 */
class OrderRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private GameVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.card_to_card.number' => '6037-0000-0000-0000']);

        $this->admin = User::factory()->admin()->create(['name' => 'مدیر']);
        $this->customer = User::factory()->create(['name' => 'مشتری']);
        $this->customer->wallet()->create(['balance' => 5000000]);

        $platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->variant = Game::create(['title' => 'GTA V', 'genre' => 'Action', 'is_available' => true])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 1000000, 'size' => 50, 'is_available' => true]);
    }

    private function placeOrder(string $method = 'wallet'): Order
    {
        $id = $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => $method, 'items' => [['variantId' => $this->variant->id]]])
            ->assertCreated()
            ->json('data.order_id');

        return Order::findOrFail($id);
    }

    public function test_orders_get_a_public_number_that_is_not_their_id(): void
    {
        $first = $this->placeOrder();
        $second = $this->placeOrder();

        $this->assertMatchesRegularExpression('/^GW-\d{6}-[2-9A-HJ-NP-Z]{5}$/', $first->number);
        $this->assertNotSame($first->number, $second->number);

        $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $first->id))
            ->assertOk()
            ->assertJsonPath('data.number', $first->number)
            ->assertJsonPath('data.orderId', '#'.$first->id);
    }

    public function test_an_order_opens_by_its_number_only_for_its_owner_and_admins(): void
    {
        $order = $this->placeOrder();

        foreach ([$order->number, mb_strtolower($order->number)] as $reference) {
            $this->actingAs($this->customer)
                ->getJson(route('api.orders.show', $reference))
                ->assertOk()
                ->assertJsonPath('data.id', $order->id);
        }

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders.show', $order->number))
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);

        $this->actingAs(User::factory()->create())
            ->getJson(route('api.orders.show', $order->number))
            ->assertNotFound();

        $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', 'GW-000000-XXXXX'))
            ->assertNotFound();
    }

    public function test_admins_can_search_orders_by_number(): void
    {
        $order = $this->placeOrder();
        $this->placeOrder();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders', ['search' => mb_strtolower($order->number)]))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $order->id);
    }

    public function test_an_order_keeps_what_was_bought_after_the_game_changes(): void
    {
        $order = $this->placeOrder();

        $this->variant->game->update(['title' => 'نام جدید']);
        $this->variant->delete();

        $item = $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $order->id))
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame('GTA V', $item['title']);
        $this->assertSame('PlayStation 5', $item['platform_name']);
        $this->assertSame('نام جدید', $item['game']['title']);
        $this->assertNull($item['variant']);
    }

    public function test_items_created_directly_still_copy_the_game_and_console(): void
    {
        $order = Order::create(['user_id' => $this->customer->id, 'console_type' => 'PS5', 'total' => 1000, 'status' => 'pending', 'payment_status' => 'paid']);

        $item = $order->items()->create([
            'game_id' => $this->variant->game_id,
            'game_variant_id' => $this->variant->id,
            'quantity' => 1,
            'price' => 1000,
        ]);

        $this->assertSame('GTA V', $item->title);
        $this->assertSame('PlayStation 5', $item->platform_name);
    }

    public function test_history_records_each_change_and_who_made_it(): void
    {
        $order = $this->placeOrder();
        $itemId = $order->items()->sole()->id;

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.items.installation', [$order->id, $itemId]), ['installation_status' => 'installing'])
            ->assertOk();

        $history = $order->histories()->get();

        $this->assertSame([OrderHistory::EVENT_CREATED, OrderHistory::EVENT_INSTALLATION, OrderHistory::EVENT_STATUS], $history->pluck('event')->all());

        $this->assertSame($this->customer->id, $history[0]->user_id);
        $this->assertSame('pending', $history[0]->to_value);

        $this->assertSame($this->admin->id, $history[1]->user_id);
        $this->assertSame(['pending', 'installing', 'GTA V'], [$history[1]->from_value, $history[1]->to_value, $history[1]->note]);

        $this->assertSame(['pending', 'processing'], [$history[2]->from_value, $history[2]->to_value]);
    }

    public function test_admins_see_who_acted_and_customers_do_not(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.orders.status', $order->id), ['status' => 'cancelled'])
            ->assertOk();

        $history = $this->actingAs($this->admin)
            ->getJson(route('api.admin.orders.show', $order->id))
            ->assertOk()
            ->json('data.history');

        $this->assertSame('سفارش ثبت شد', $history[0]['label']);
        $this->assertSame($this->customer->id, $history[0]['actor']['id']);
        $this->assertSame('مدیر', collect($history)->firstWhere('to', 'cancelled')['actor']['name']);
        $this->assertSame('وضعیت سفارش: لغو شده', collect($history)->firstWhere('to', 'cancelled')['label']);
        $this->assertSame('وضعیت پرداخت: مسترد شده', collect($history)->firstWhere('to', 'refunded')['label']);

        $timeline = $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $order->id))
            ->assertOk()
            ->json('data.timeline');

        $this->assertNotEmpty($timeline);
        $this->assertArrayNotHasKey('actor', $timeline[0]);
        $this->assertNotNull($timeline[0]['date']);
    }

    public function test_the_lists_do_not_carry_the_history(): void
    {
        $this->placeOrder();

        $this->actingAs($this->customer)
            ->getJson(route('api.orders.index'))
            ->assertOk()
            ->assertJsonMissingPath('data.0.timeline');
    }

    public function test_a_change_nobody_made_is_recorded_as_the_system(): void
    {
        $order = $this->placeOrder('card_to_card');

        $this->app['auth']->forgetGuards();
        $this->travel(25)->hours();
        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $entry = $order->histories()->where('to_value', 'cancelled')->sole();

        $this->assertNull($entry->user_id);
    }

    public function test_receipts_and_their_rejection_reason_are_on_record(): void
    {
        $order = $this->placeOrder('card_to_card');
        $service = app(CardPaymentService::class);

        $service->submitReceipt($order, UploadedFile::fake()->image('r.jpg', 100, 100));
        $service->reject($order, $this->admin, 'مبلغ درست نیست.');

        $this->assertSame(1, $order->histories()->where('event', OrderHistory::EVENT_RECEIPT_SUBMITTED)->count());
        $this->assertSame('مبلغ درست نیست.', $order->histories()->where('event', OrderHistory::EVENT_RECEIPT_REJECTED)->sole()->note);
    }

    public function test_the_database_refuses_to_apply_one_payment_to_a_wallet_twice(): void
    {
        $wallets = app(WalletService::class);

        $wallets->credit($this->customer, 1000, 'payment-77', 'first');

        try {
            $wallets->credit($this->customer, 1000, 'payment-77', 'second');
            $this->fail('The second credit should have been refused.');
        } catch (QueryException) {
            // Expected.
        }

        $this->assertEquals(5001000, $this->customer->wallet()->value('balance'));
        $this->assertSame(1, $this->customer->wallet->transactions()->where('reference', 'payment-77')->count());
    }

    public function test_admin_adjustments_can_repeat(): void
    {
        $this->actingAs($this->admin)->patchJson(route('api.admin.users.update', $this->customer->id), ['wallet_balance' => 6000000])->assertOk();
        $this->actingAs($this->admin)->patchJson(route('api.admin.users.update', $this->customer->id), ['wallet_balance' => 7000000])->assertOk();

        $this->assertEquals(7000000, $this->customer->wallet()->value('balance'));
        $this->assertSame(2, $this->customer->wallet->transactions()->where('reference', 'admin-adjustment')->count());
    }

    public function test_users_with_financial_records_cannot_be_deleted(): void
    {
        $withWalletHistory = User::factory()->create();
        app(WalletService::class)->credit($withWalletHistory, 1000, 'admin-adjustment');

        $withPayment = User::factory()->create();
        $this->actingAs($withPayment)
            ->postJson(route('api.wallet.charge'), ['amount' => 100000, 'method' => 'card_to_card'])
            ->assertCreated();

        foreach ([$withWalletHistory, $withPayment] as $user) {
            $this->actingAs($this->admin)->deleteJson(route('api.admin.users.delete', $user->id))->assertUnprocessable();
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }

        $clean = User::factory()->create();
        $this->actingAs($this->admin)->deleteJson(route('api.admin.users.delete', $clean->id))->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $clean->id]);
    }

    public function test_the_database_itself_keeps_orders_when_a_user_is_deleted_directly(): void
    {
        $this->placeOrder();

        $this->expectException(QueryException::class);

        $this->customer->delete();
    }
}
