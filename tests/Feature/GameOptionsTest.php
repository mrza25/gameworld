<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameOption;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GameOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Platform $platform;

    private Game $game;

    private GameVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'http://frontend.test']);

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create();
        $this->customer->wallet()->create(['balance' => 10000000]);

        $this->platform = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->game = $this->createGame('God of War');
        $this->variant = $this->game->variants()->sole();
    }

    private function createGame(string $title): Game
    {
        $game = Game::create(['title' => $title, 'genre' => 'Action', 'is_available' => true]);
        $game->variants()->create(['platform_id' => $this->platform->id, 'price' => 1000000, 'size' => 50, 'is_available' => true]);

        return $game;
    }

    private function addOption(string $label, int $sortOrder = 0, bool $isActive = true, ?Game $game = null): GameOption
    {
        return ($game ?? $this->game)->options()->create(['label' => $label, 'sort_order' => $sortOrder, 'is_active' => $isActive]);
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private function updateOptions(array $options): TestResponse
    {
        return $this->actingAs($this->admin)->patchJson(route('api.admin.games.update', $this->game->id), ['options' => $options]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function checkout(array $items, string $method = 'wallet', array $extra = []): TestResponse
    {
        return $this->actingAs($this->customer)->postJson(route('api.orders.checkout'), ['method' => $method, 'items' => $items] + $extra);
    }

    public function test_admin_can_create_a_game_with_options(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('api.admin.games.store'), [
                'title' => 'Elden Ring',
                'genre' => 'RPG',
                'price' => 2000000,
                'options' => [['label' => '  ظرفیت ۱  '], ['label' => 'ظرفیت ۲']],
            ])
            ->assertCreated();

        $this->assertSame(['ظرفیت ۱', 'ظرفیت ۲'], array_column($response->json('data.options'), 'label'));
        $this->assertSame([0, 1], GameOption::where('game_id', $response->json('data.id'))->orderBy('id')->pluck('sort_order')->all());
    }

    public function test_options_can_arrive_as_a_json_string_from_form_data(): void
    {
        $response = $this->actingAs($this->admin)->post(route('api.admin.games.store'), [
            'title' => 'Elden Ring',
            'genre' => 'RPG',
            'price' => 2000000,
            'options' => json_encode([['label' => 'ظرفیت ۱'], ['label' => 'ظرفیت ۲']]),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['ظرفیت ۱', 'ظرفیت ۲'], array_column($response->json('data.options'), 'label'));

        $gameId = $response->json('data.id');
        $this->actingAs($this->admin)
            ->post(route('api.admin.games.update', $gameId), ['options' => '[]'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.options', []);
    }

    public function test_update_edits_adds_reorders_and_deactivates_options(): void
    {
        $first = $this->addOption('A', 0);
        $second = $this->addOption('B', 1);
        $removed = $this->addOption('C', 2);

        $response = $this->updateOptions([
            ['id' => $second->id, 'label' => 'B edited'],
            ['label' => 'D'],
            ['id' => $first->id, 'label' => 'A'],
        ])->assertOk();

        $this->assertSame(['B edited', 'D', 'A'], array_column($response->json('data.options'), 'label'));
        $this->assertSame($second->id, $response->json('data.options.0.id'));
        $this->assertFalse($removed->fresh()->is_active);
        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
    }

    public function test_game_update_without_options_leaves_them_untouched(): void
    {
        $option = $this->addOption('A');

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.games.update', $this->game->id), ['title' => 'New title'])
            ->assertOk()
            ->assertJsonPath('data.options', [['id' => $option->id, 'label' => 'A']]);
    }

    public function test_empty_list_removes_all_options(): void
    {
        $option = $this->addOption('A');

        $this->updateOptions([])->assertOk()->assertJsonPath('data.options', []);

        $this->assertFalse($option->fresh()->is_active);
    }

    public function test_resending_labels_without_ids_keeps_the_same_rows(): void
    {
        $first = $this->addOption('A', 0);
        $removed = $this->addOption('B', 1, isActive: false);

        $this->updateOptions([['label' => 'a'], ['label' => 'B']])
            ->assertOk()
            ->assertJsonPath('data.options', [['id' => $first->id, 'label' => 'a'], ['id' => $removed->id, 'label' => 'B']]);

        $this->assertSame(2, $this->game->options()->count());
    }

    public function test_an_explicit_id_wins_over_a_label_match(): void
    {
        $option = $this->addOption('A', 0);

        $response = $this->updateOptions([['label' => 'A'], ['id' => $option->id, 'label' => 'B']])->assertOk();

        $this->assertSame(['A', 'B'], array_column($response->json('data.options'), 'label'));
        $this->assertNotSame($option->id, $response->json('data.options.0.id'));
        $this->assertSame($option->id, $response->json('data.options.1.id'));
    }

    public function test_a_game_can_have_up_to_six_options(): void
    {
        $this->updateOptions(array_map(fn (int $i) => ['label' => "option {$i}"], range(1, 6)))
            ->assertOk()
            ->assertJsonCount(6, 'data.options');

        $this->updateOptions(array_map(fn (int $i) => ['label' => "option {$i}"], range(1, 7)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options')
            ->assertJsonPath('error', 'هر بازی حداکثر ۶ گزینه می‌تواند داشته باشد.');
    }

    public function test_options_are_validated(): void
    {
        $this->updateOptions([['label' => 'تکراری'], ['label' => ' تکراری ']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options.0.label', 'options.1.label'])
            ->assertJsonPath('error', 'متن گزینه‌های یک بازی نباید تکراری باشد.');

        $this->updateOptions([['label' => 'Same'], ['label' => 'same']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.1.label');

        $this->updateOptions([['label' => '   ']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.0.label');

        $this->updateOptions([['label' => str_repeat('x', 101)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.0.label');

        $otherOption = $this->addOption('other', game: $this->createGame('Other'));
        $this->updateOptions([['id' => $otherOption->id, 'label' => 'stolen']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.0.id');

        $this->assertSame('other', $otherOption->fresh()->label);
        $this->assertSame(0, $this->game->options()->count());
    }

    public function test_every_catalog_endpoint_returns_active_options_in_order(): void
    {
        $second = $this->addOption('B', 1);
        $first = $this->addOption('A', 0);
        $this->addOption('hidden', 2, isActive: false);

        $expected = [['id' => $first->id, 'label' => 'A'], ['id' => $second->id, 'label' => 'B']];

        $this->getJson(route('api.products.index'))->assertJsonPath('data.0.options', $expected);
        $this->getJson(route('api.games.index'))->assertJsonPath('data.0.options', $expected);
        $this->getJson(route('api.products.show', $this->game->slug))->assertJsonPath('data.options', $expected);
        $this->getJson(route('api.games.show', $this->game->slug))->assertJsonPath('data.options', $expected);

        $this->actingAs($this->admin)->getJson(route('api.admin.games.index'))->assertJsonPath('data.data.0.options', $expected);
        $this->actingAs($this->admin)->getJson(route('api.admin.games.show', $this->game->id))->assertJsonPath('data.options', $expected);
    }

    public function test_listing_options_does_not_add_a_query_per_game(): void
    {
        $this->addOption('A');
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(route('api.products.index'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queriesForOneGame = $countQueries();

        foreach (range(1, 4) as $i) {
            $this->addOption('A', game: $this->createGame("Game {$i}"));
        }

        $this->assertSame($queriesForOneGame, $countQueries());
    }

    public function test_wallet_checkout_saves_selected_options_and_every_order_endpoint_returns_them(): void
    {
        $first = $this->addOption('A', 0);
        $this->addOption('B', 1);
        $third = $this->addOption('C', 2);
        $expected = [['id' => $first->id, 'label' => 'A'], ['id' => $third->id, 'label' => 'C']];

        $response = $this->checkout([['variantId' => $this->variant->id, 'option_ids' => [$third->id, $first->id, $first->id]]])
            ->assertCreated()
            ->assertJsonPath('data.order.items.0.selected_options', $expected);
        $orderId = $response->json('data.order_id');

        $this->actingAs($this->customer)->getJson(route('api.orders.index'))->assertJsonPath('data.0.items.0.selected_options', $expected);
        $this->actingAs($this->customer)->getJson(route('api.orders.show', $orderId))->assertJsonPath('data.items.0.selected_options', $expected);
        $this->actingAs($this->admin)->getJson(route('api.admin.orders'))->assertJsonPath('data.data.0.items.0.selected_options', $expected);
        $this->actingAs($this->admin)->getJson(route('api.admin.orders.show', $orderId))->assertJsonPath('data.items.0.selected_options', $expected);
    }

    public function test_gateway_checkout_keeps_selections_through_the_payment_even_if_the_option_changes(): void
    {
        $edited = $this->addOption('A', 0);
        $removed = $this->addOption('B', 1);

        $callbackUrl = $this->checkout(
            [['variant_id' => $this->variant->id, 'option_ids' => [$edited->id, $removed->id]]],
            'gateway'
        )->assertOk()->json('callback_url');

        $this->assertDatabaseCount('order_item_options', 0);

        $this->updateOptions([['id' => $edited->id, 'label' => 'A edited']])->assertOk();

        $this->get($callbackUrl)->assertRedirect();

        $order = Order::where('user_id', $this->customer->id)->sole();
        $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $order->id))
            ->assertJsonPath('data.items.0.selected_options', [
                ['id' => $edited->id, 'label' => 'A'],
                ['id' => $removed->id, 'label' => 'B'],
            ]);
    }

    public function test_inactive_or_foreign_options_are_rejected_without_charging(): void
    {
        $inactive = $this->addOption('hidden', isActive: false);
        $foreign = $this->addOption('other', game: $this->createGame('Other'));

        foreach ([$inactive->id, $foreign->id, 999999] as $optionId) {
            $this->checkout([['variantId' => $this->variant->id, 'option_ids' => [$optionId]]])
                ->assertUnprocessable()
                ->assertJsonPath('ok', false)
                ->assertJsonPath('error', 'گزینه‌ی انتخاب‌شده برای بازی «God of War» معتبر نیست.');
        }

        $this->checkout([['variantId' => $this->variant->id, 'option_ids' => [$foreign->id]]], 'gateway')
            ->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(10000000, (float) $this->customer->wallet()->value('balance'));
    }

    public function test_option_ids_must_be_a_list_of_integers(): void
    {
        $this->checkout([['variantId' => $this->variant->id, 'option_ids' => 'not-a-list']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.option_ids');

        $this->checkout([['variantId' => $this->variant->id, 'option_ids' => ['abc']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.option_ids.0');
    }

    public function test_selecting_options_is_optional(): void
    {
        $this->addOption('A');
        $withoutOptions = $this->createGame('No options')->variants()->sole();

        $this->checkout([
            ['variantId' => $this->variant->id],
            ['variantId' => $withoutOptions->id, 'option_ids' => []],
        ])
            ->assertCreated()
            ->assertJsonPath('data.order.items.0.selected_options', [])
            ->assertJsonPath('data.order.items.1.selected_options', []);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'wallet', 'variants' => [$this->variant->id]])
            ->assertCreated()
            ->assertJsonPath('data.order.items.0.selected_options', []);
    }

    public function test_past_orders_keep_the_label_after_the_option_is_edited_or_deleted(): void
    {
        $option = $this->addOption('A');
        $orderId = $this->checkout([['variantId' => $this->variant->id, 'option_ids' => [$option->id]]])->json('data.order_id');

        $this->updateOptions([['id' => $option->id, 'label' => 'A edited']])->assertOk();
        $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $orderId))
            ->assertJsonPath('data.items.0.selected_options', [['id' => $option->id, 'label' => 'A']]);

        $option->delete();
        $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $orderId))
            ->assertJsonPath('data.items.0.selected_options', [['id' => null, 'label' => 'A']]);
    }

    public function test_options_survive_when_checkout_swaps_the_variant_for_the_chosen_console(): void
    {
        $option = $this->addOption('A');
        $otherPlatform = Platform::firstOrCreate(['slug' => 'ps4'], ['name' => 'PlayStation 4']);
        $otherVariant = $this->game->variants()->create(['platform_id' => $otherPlatform->id, 'price' => 500000, 'size' => 40, 'is_available' => true]);

        $this->checkout([['variantId' => $otherVariant->id, 'option_ids' => [$option->id]]], extra: ['console' => 'ps5'])
            ->assertCreated()
            ->assertJsonPath('data.order.items.0.variant_id', $this->variant->id)
            ->assertJsonPath('data.order.items.0.selected_options', [['id' => $option->id, 'label' => 'A']]);
    }

    public function test_options_work_when_the_item_references_the_game_instead_of_a_variant(): void
    {
        $option = $this->addOption('A');

        $this->checkout([['slug' => $this->game->slug, 'option_ids' => [$option->id]]], extra: ['console' => 'ps5'])
            ->assertCreated()
            ->assertJsonPath('data.order.items.0.selected_options', [['id' => $option->id, 'label' => 'A']]);
    }
}
