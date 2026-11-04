<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\GameVariantOption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Each variant (a game on one console) can have up to six price rows. The
 * customer buys exactly one, and its price is the item's whole price.
 */
class GameOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Platform $ps5;

    private Platform $xbox;

    private Game $game;

    private GameVariant $ps5Variant;

    private GameVariant $xboxVariant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'http://frontend.test']);

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create();
        $this->customer->wallet()->create(['balance' => 10000000]);

        $this->ps5 = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->xbox = Platform::firstOrCreate(['slug' => 'xbox-one'], ['name' => 'Xbox One']);

        $this->game = Game::create(['title' => 'GTA', 'genre' => 'Action', 'is_available' => true, 'price' => 700000]);
        $this->ps5Variant = $this->game->variants()->create(['platform_id' => $this->ps5->id, 'price' => 700000, 'size' => 50, 'is_available' => true]);
        $this->xboxVariant = $this->game->variants()->create(['platform_id' => $this->xbox->id, 'price' => 650000, 'size' => 50, 'is_available' => true]);
    }

    /**
     * @param  array<string, int>  $pricesByLabel
     * @return array<int, GameVariantOption>
     */
    private function addOptions(GameVariant $variant, array $pricesByLabel): array
    {
        $variant->syncOptions(array_map(
            fn (string $label, int $price) => ['label' => $label, 'price' => $price],
            array_keys($pricesByLabel),
            $pricesByLabel
        ));
        $variant->syncPriceWithDefaultOption();

        return $variant->activeOptions()->get()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function saveVariants(array $variants): TestResponse
    {
        return $this->actingAs($this->admin)->patchJson(route('api.admin.games.update', $this->game->id), ['variants' => $variants]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function savePs5Options(array $options): TestResponse
    {
        return $this->saveVariants([['platform_id' => 'ps5', 'size' => 50, 'options' => $options]]);
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $extra
     */
    private function checkout(array $items, string $method = 'wallet', array $extra = []): TestResponse
    {
        return $this->actingAs($this->customer)->postJson(route('api.orders.checkout'), ['method' => $method, 'items' => $items] + $extra);
    }

    private function walletBalance(): float
    {
        return (float) $this->customer->wallet()->value('balance');
    }

    private function assertNothingWasCharged(): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(10000000, $this->walletBalance());
    }

    public function test_each_console_keeps_its_own_price_rows(): void
    {
        $response = $this->saveVariants([
            ['platform_id' => 'ps5', 'size' => 50, 'options' => [
                ['label' => 'ظرفیت ۱', 'price' => 1000000],
                ['label' => 'ظرفیت ۲', 'price' => 1500000],
            ]],
            ['platform_id' => 'xbox-one', 'size' => 50, 'options' => [
                ['label' => 'ظرفیت ۱', 'price' => 800000],
            ]],
        ])->assertOk();

        $variants = collect($response->json('data.variants'))->keyBy('platform_id');

        $this->assertEquals([['ظرفیت ۱', 1000000], ['ظرفیت ۲', 1500000]], array_map(fn ($o) => [$o['label'], $o['price']], $variants['ps5']['options']));
        $this->assertEquals([['ظرفیت ۱', 800000]], array_map(fn ($o) => [$o['label'], $o['price']], $variants['xbox-one']['options']));
        $this->assertSame(2, $this->ps5Variant->activeOptions()->count());
        $this->assertSame(1, $this->xboxVariant->activeOptions()->count());
    }

    public function test_game_can_be_created_with_price_rows_and_its_price_comes_from_them(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('api.admin.games.store'), [
                'title' => 'Elden Ring',
                'genre' => 'RPG',
                'variants' => [['platform_id' => 'ps5', 'size' => 40, 'options' => [
                    ['label' => '  ظرفیت ۱  ', 'price' => 2000000],
                    ['label' => 'ظرفیت ۲', 'price' => 2500000],
                ]]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.price', 2000000)
            ->assertJsonPath('data.variants.0.price', 2000000)
            ->assertJsonPath('data.variants.0.options.0.label', 'ظرفیت ۱');

        $this->assertSame([0, 1], GameVariant::findOrFail($response->json('data.variants.0.id'))->options()->pluck('sort_order')->all());
    }

    public function test_price_rows_can_arrive_as_json_strings_from_form_data(): void
    {
        $this->actingAs($this->admin)
            ->post(route('api.admin.games.update', $this->game->id), [
                'variants' => json_encode([['platform_id' => 'ps5', 'size' => 50, 'options' => [['label' => 'A', 'price' => 900000]]]]),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.variants.0.options.0.price', 900000);

        $this->actingAs($this->admin)
            ->post(route('api.admin.games.update', $this->game->id), [
                'variants' => [['platform_id' => 'ps5', 'size' => 50, 'options' => json_encode([['label' => 'B', 'price' => 950000]])]],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.variants.0.options.0.label', 'B');
    }

    public function test_variant_price_follows_the_first_option_on_create_edit_and_reorder(): void
    {
        $this->savePs5Options([['label' => 'A', 'price' => 1000000], ['label' => 'B', 'price' => 1500000]])->assertOk();
        $this->assertEquals(1000000, (float) $this->ps5Variant->fresh()->price);
        [$first, $second] = $this->ps5Variant->activeOptions()->get()->all();

        $this->savePs5Options([['id' => $first->id, 'label' => 'A', 'price' => 1100000], ['id' => $second->id, 'label' => 'B', 'price' => 1500000]])->assertOk();
        $this->assertEquals(1100000, (float) $this->ps5Variant->fresh()->price);

        $this->savePs5Options([['id' => $second->id, 'label' => 'B', 'price' => 1500000], ['id' => $first->id, 'label' => 'A', 'price' => 1100000]])->assertOk();
        $this->assertEquals(1500000, (float) $this->ps5Variant->fresh()->price);
        $this->assertSame([$second->id, $first->id], $this->ps5Variant->activeOptions()->pluck('id')->all());
    }

    public function test_a_price_sent_for_a_variant_with_options_cannot_break_the_sync(): void
    {
        $this->addOptions($this->ps5Variant, ['A' => 1000000]);

        $this->saveVariants([['platform_id' => 'ps5', 'size' => 50, 'price' => 5]])->assertOk();

        $this->assertEquals(1000000, (float) $this->ps5Variant->fresh()->price);
    }

    public function test_changing_the_game_price_leaves_variants_with_options_alone(): void
    {
        $this->addOptions($this->ps5Variant, ['A' => 1000000]);

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.games.update', $this->game->id), ['price' => 400000])
            ->assertOk();

        $this->assertEquals(1000000, (float) $this->ps5Variant->fresh()->price);
        $this->assertEquals(400000, (float) $this->xboxVariant->fresh()->price);
    }

    public function test_game_price_shows_the_default_option_of_its_first_variant(): void
    {
        $this->saveVariants([['platform_id' => 'ps5', 'size' => 50, 'options' => [['label' => 'A', 'price' => 1200000]]]])
            ->assertOk()
            ->assertJsonPath('data.price', 1200000);
    }

    public function test_options_left_out_stay_untouched_and_an_empty_list_deactivates_them(): void
    {
        [$option] = $this->addOptions($this->ps5Variant, ['A' => 1000000]);

        $this->saveVariants([['platform_id' => 'ps5', 'size' => 60, 'price' => 1000000]])
            ->assertOk()
            ->assertJsonPath('data.variants.0.options.0.id', $option->id);

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.games.update', $this->game->id), ['title' => 'New title'])
            ->assertOk();
        $this->assertTrue($option->fresh()->is_active);

        $this->savePs5Options([])->assertOk()->assertJsonPath('data.variants.0.options', []);

        $this->assertFalse($option->fresh()->is_active);
        $this->assertEquals(1000000, (float) $this->ps5Variant->fresh()->price);
    }

    public function test_removed_rows_are_deactivated_and_the_next_one_becomes_the_default(): void
    {
        [$first, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->savePs5Options([['id' => $second->id, 'label' => 'B', 'price' => 1500000]])->assertOk();

        $this->assertFalse($first->fresh()->is_active);
        $this->assertEquals(1500000, (float) $this->ps5Variant->fresh()->price);
    }

    public function test_a_single_row_may_have_no_label(): void
    {
        $this->savePs5Options([['price' => 900000]])
            ->assertOk()
            ->assertJsonPath('data.variants.0.options.0.label', null);

        $this->savePs5Options([['label' => '   ', 'price' => 900000]])
            ->assertOk()
            ->assertJsonPath('data.variants.0.options.0.label', null);
    }

    public function test_price_rows_are_validated(): void
    {
        $rows = fn (int $count) => array_map(fn (int $i) => ['label' => "row {$i}", 'price' => 1000], range(1, $count));

        $this->savePs5Options($rows(6))->assertOk();

        $this->savePs5Options($rows(7))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.options')
            ->assertJsonPath('error', 'هر کنسول حداکثر ۶ گزینه‌ی قیمت می‌تواند داشته باشد.');

        foreach ([0, -1, 'free', null] as $price) {
            $this->savePs5Options([['label' => 'A', 'price' => $price]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('variants.0.options.0.price');
        }

        $this->savePs5Options([['label' => 'تکراری', 'price' => 1000], ['label' => ' تکراری ', 'price' => 2000]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.options.0.label', 'variants.0.options.1.label'])
            ->assertJsonPath('error', 'توضیح ردیف‌های یک کنسول نباید تکراری باشد.');

        $this->savePs5Options([['label' => 'A', 'price' => 1000], ['price' => 2000]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.options.1.label')
            ->assertJsonPath('error', 'وقتی بیش از یک ردیف قیمت هست، توضیح هر ردیف الزامی است.');

        $this->savePs5Options([['label' => str_repeat('x', 101), 'price' => 1000]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.options.0.label');
    }

    public function test_an_option_of_another_console_cannot_be_edited(): void
    {
        [$xboxOption] = $this->addOptions($this->xboxVariant, ['A' => 800000]);

        $this->savePs5Options([['id' => $xboxOption->id, 'label' => 'stolen', 'price' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.options.0.id')
            ->assertJsonPath('error', 'این گزینه متعلق به این کنسول نیست.');

        $this->assertSame('A', $xboxOption->fresh()->label);
    }

    public function test_resending_labels_without_ids_keeps_the_same_rows(): void
    {
        [$first, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->savePs5Options([['label' => 'a', 'price' => 1000000], ['label' => 'B', 'price' => 1500000]])
            ->assertOk()
            ->assertJsonPath('data.variants.0.options.0.id', $first->id)
            ->assertJsonPath('data.variants.0.options.1.id', $second->id);

        $this->assertSame(2, $this->ps5Variant->options()->count());
    }

    public function test_game_level_options_are_gone(): void
    {
        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.games.update', $this->game->id), ['options' => [['label' => 'A', 'price' => 1]]])
            ->assertOk()
            ->assertJsonMissingPath('data.options')
            ->assertJsonPath('data.variants.0.options', []);
    }

    public function test_every_catalog_endpoint_returns_active_options_inside_each_variant(): void
    {
        [$first, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);
        $this->ps5Variant->options()->create(['label' => 'hidden', 'price' => 1, 'sort_order' => 9, 'is_active' => false]);

        $expected = [
            ['id' => $first->id, 'label' => 'A', 'price' => 1000000],
            ['id' => $second->id, 'label' => 'B', 'price' => 1500000],
        ];

        $variantsOf = fn (TestResponse $response, string $path) => collect($response->assertOk()->json($path))->keyBy('platform_id');

        foreach ([
            [$this->getJson(route('api.products.index')), 'data.0.variants'],
            [$this->getJson(route('api.games.index')), 'data.0.variants'],
            [$this->getJson(route('api.products.show', $this->game->slug)), 'data.variants'],
            [$this->getJson(route('api.games.show', $this->game->slug)), 'data.variants'],
            [$this->actingAs($this->admin)->getJson(route('api.admin.games.index')), 'data.data.0.variants'],
            [$this->actingAs($this->admin)->getJson(route('api.admin.games.show', $this->game->id)), 'data.variants'],
        ] as [$response, $path]) {
            $variants = $variantsOf($response, $path);
            $this->assertSame($expected, $variants['ps5']['options']);
            $this->assertSame([], $variants['xbox-one']['options']);
        }
    }

    public function test_listing_options_does_not_add_a_query_per_game(): void
    {
        $this->addOptions($this->ps5Variant, ['A' => 1000]);
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
            $game = Game::create(['title' => "Game {$i}", 'genre' => 'Action', 'is_available' => true]);
            $this->addOptions($game->variants()->create(['platform_id' => $this->ps5->id, 'price' => 1000, 'size' => 1, 'is_available' => true]), ['A' => 1000]);
        }

        $this->assertSame($queriesForOneGame, $countQueries());
    }

    public function test_checkout_without_an_option_charges_the_default(): void
    {
        [$first] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->checkout([['variantId' => $this->ps5Variant->id]])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1000000)
            ->assertJsonPath('data.order.items.0.price', 1000000)
            ->assertJsonPath('data.order.items.0.selected_option', ['id' => $first->id, 'label' => 'A', 'price' => 1000000]);

        $this->assertEquals(9000000, $this->walletBalance());
    }

    public function test_the_flat_variants_and_games_inputs_use_the_default_option(): void
    {
        [$first] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'wallet', 'console' => 'ps5', 'variants' => [$this->ps5Variant->id]])
            ->assertCreated()
            ->assertJsonPath('data.order.items.0.selected_option.id', $first->id);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['method' => 'wallet', 'console' => 'ps5', 'games' => [$this->game->slug]])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1000000)
            ->assertJsonPath('data.order.items.0.selected_option.id', $first->id);
    }

    public function test_wallet_checkout_with_a_non_default_option_charges_its_price_and_refunds_it(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $response = $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => $second->id]])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1500000)
            ->assertJsonPath('data.order.total', 1500000)
            ->assertJsonPath('data.order.items.0.price', 1500000);

        $this->assertEquals(8500000, $this->walletBalance());

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.cancel', $response->json('data.order_id')))
            ->assertOk();

        $this->assertEquals(10000000, $this->walletBalance());
    }

    public function test_gateway_checkout_with_a_non_default_option_charges_its_price(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $response = $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => $second->id]], 'gateway')
            ->assertOk()
            ->assertJsonPath('data.amount', 1500000);

        $this->assertEquals(1500000, (float) Payment::sole()->amount);
        $this->assertDatabaseCount('order_item_options', 0);

        $this->app['auth']->forgetGuards();
        $this->get($response->json('callback_url'))->assertRedirect();

        $order = Order::where('user_id', $this->customer->id)->sole();
        $this->assertEquals(1500000, (float) $order->total);

        $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $order->id))
            ->assertJsonPath('data.items.0.selected_option', ['id' => $second->id, 'label' => 'B', 'price' => 1500000]);
    }

    public function test_gateway_payment_keeps_the_agreed_price_if_the_option_changes_during_payment(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $callbackUrl = $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => $second->id]], 'gateway')
            ->assertOk()
            ->json('callback_url');

        $this->savePs5Options([['label' => 'other', 'price' => 99000]])->assertOk();
        $this->assertFalse($second->fresh()->is_active);

        $this->app['auth']->forgetGuards();
        $this->get($callbackUrl)->assertRedirect();

        $order = Order::where('user_id', $this->customer->id)->sole();
        $this->assertEquals(1500000, (float) $order->total);
        $this->assertSame('B', $order->items()->sole()->selectedOption->label);
    }

    public function test_the_automatic_payment_method_uses_the_option_price(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);
        $this->customer->wallet()->update(['balance' => 1200000]);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['items' => [['variantId' => $this->ps5Variant->id, 'option_id' => $second->id]]])
            ->assertOk()
            ->assertJsonPath('data.method', 'gateway')
            ->assertJsonPath('data.amount', 1500000);

        $this->actingAs($this->customer)
            ->postJson(route('api.orders.checkout'), ['items' => [['variantId' => $this->ps5Variant->id]]])
            ->assertCreated()
            ->assertJsonPath('data.method', 'wallet')
            ->assertJsonPath('data.amount', 1000000);
    }

    public function test_the_wallet_balance_is_checked_against_the_option_price(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);
        $this->customer->wallet()->update(['balance' => 1200000]);

        $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => $second->id]])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(1200000, $this->walletBalance());
    }

    public function test_invalid_options_are_rejected_without_charging(): void
    {
        $this->addOptions($this->ps5Variant, ['A' => 1000000]);
        [$xboxOption] = $this->addOptions($this->xboxVariant, ['A' => 800000]);
        $inactive = $this->ps5Variant->options()->create(['label' => 'hidden', 'price' => 1, 'sort_order' => 9, 'is_active' => false]);

        foreach ([$xboxOption->id, $inactive->id, 999999] as $optionId) {
            foreach (['wallet', 'gateway'] as $method) {
                $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => $optionId]], $method)
                    ->assertUnprocessable()
                    ->assertJsonPath('ok', false)
                    ->assertJsonPath('error', 'گزینه‌ی انتخاب‌شده برای بازی «GTA» روی کنسول PlayStation 5 معتبر نیست.');
            }
        }

        $this->assertNothingWasCharged();
    }

    public function test_an_option_is_rejected_when_the_variant_has_none(): void
    {
        $inactive = $this->ps5Variant->options()->create(['label' => 'hidden', 'price' => 1, 'is_active' => false]);

        $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => $inactive->id]])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'بازی «GTA» روی کنسول PlayStation 5 گزینه‌ی قیمت ندارد.');

        $this->assertNothingWasCharged();
    }

    public function test_an_option_of_a_variant_swapped_out_for_the_console_is_rejected(): void
    {
        $this->addOptions($this->ps5Variant, ['A' => 1000000]);
        [$xboxOption] = $this->addOptions($this->xboxVariant, ['A' => 800000]);

        $this->checkout([['variantId' => $this->xboxVariant->id, 'option_id' => $xboxOption->id]], extra: ['console' => 'ps5'])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'گزینه‌ی انتخاب‌شده برای بازی «GTA» روی کنسول PlayStation 5 معتبر نیست.');

        $this->assertNothingWasCharged();
    }

    public function test_an_option_of_the_swapped_in_variant_is_accepted(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->checkout([['variantId' => $this->xboxVariant->id, 'option_id' => $second->id]], extra: ['console' => 'ps5'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1500000)
            ->assertJsonPath('data.order.items.0.variant_id', $this->ps5Variant->id);
    }

    public function test_an_item_can_reference_the_game_instead_of_the_variant(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->checkout([['slug' => $this->game->slug, 'option_id' => $second->id]], extra: ['console' => 'ps5'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1500000);
    }

    public function test_one_variant_cannot_get_two_different_options(): void
    {
        [$first, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->checkout([
            ['variantId' => $this->ps5Variant->id, 'option_id' => $first->id],
            ['variantId' => $this->ps5Variant->id, 'option_id' => $second->id],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'برای یک بازی نمی‌توان دو گزینه‌ی قیمت متفاوت انتخاب کرد.');

        $this->assertNothingWasCharged();
    }

    public function test_an_option_for_an_unknown_item_is_rejected(): void
    {
        [$first] = $this->addOptions($this->ps5Variant, ['A' => 1000000]);

        $this->checkout([
            ['variantId' => $this->ps5Variant->id],
            ['variantId' => 999999, 'option_id' => $first->id],
        ])->assertUnprocessable();

        $this->assertNothingWasCharged();
    }

    public function test_option_id_must_be_an_integer(): void
    {
        $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => 'abc']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.option_id');
    }

    public function test_order_total_sums_the_options_chosen_for_each_game(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);
        $otherGame = Game::create(['title' => 'Other', 'genre' => 'Action', 'is_available' => true]);
        $otherVariant = $otherGame->variants()->create(['platform_id' => $this->ps5->id, 'price' => 1000, 'size' => 1, 'is_available' => true]);
        [$otherFirst, $otherSecond] = $this->addOptions($otherVariant, ['X' => 300000, 'Y' => 450000]);

        $response = $this->checkout([
            ['variantId' => $this->ps5Variant->id, 'option_id' => $second->id],
            ['variantId' => $otherVariant->id, 'option_id' => $otherSecond->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 1950000);

        $this->assertEquals(8050000, $this->walletBalance());

        $order = Order::findOrFail($response->json('data.order_id'));
        $this->assertEquals((float) $order->items()->sum('price'), (float) $order->total);
        $this->assertNotSame($otherFirst->id, $order->items()->where('game_id', $otherGame->id)->sole()->selectedOption->game_variant_option_id);
    }

    public function test_later_changes_never_alter_a_past_order(): void
    {
        [$first] = $this->addOptions($this->ps5Variant, ['A' => 1000000]);
        $orderId = $this->checkout([['variantId' => $this->ps5Variant->id, 'option_id' => $first->id]])->json('data.order_id');

        $this->savePs5Options([['id' => $first->id, 'label' => 'A edited', 'price' => 5000000]])->assertOk();

        $expected = ['id' => $first->id, 'label' => 'A', 'price' => 1000000];

        foreach ([
            [$this->actingAs($this->customer)->getJson(route('api.orders.index')), 'data.0'],
            [$this->actingAs($this->customer)->getJson(route('api.orders.show', $orderId)), 'data'],
            [$this->actingAs($this->admin)->getJson(route('api.admin.orders')), 'data.data.0'],
            [$this->actingAs($this->admin)->getJson(route('api.admin.orders.show', $orderId)), 'data'],
        ] as [$response, $path]) {
            $response->assertOk()
                ->assertJsonPath("{$path}.total", 1000000)
                ->assertJsonPath("{$path}.items.0.price", 1000000)
                ->assertJsonPath("{$path}.items.0.selected_option", $expected);
        }

        $this->ps5Variant->options()->delete();

        $this->actingAs($this->customer)
            ->getJson(route('api.orders.show', $orderId))
            ->assertJsonPath('data.items.0.selected_option', ['id' => null, 'label' => 'A', 'price' => 1000000]);
    }

    public function test_a_variant_without_options_works_as_before(): void
    {
        $this->checkout([['variantId' => $this->ps5Variant->id]])
            ->assertCreated()
            ->assertJsonPath('data.amount', 700000)
            ->assertJsonPath('data.order.items.0.price', 700000)
            ->assertJsonPath('data.order.items.0.selected_option', null);

        $this->assertDatabaseCount('order_item_options', 0);
    }

    public function test_the_service_rejects_a_wrong_amount_on_the_gateway_path(): void
    {
        [, $second] = $this->addOptions($this->ps5Variant, ['A' => 1000000, 'B' => 1500000]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The checkout total has changed');

        try {
            app(CheckoutService::class)->createGatewayPayment(
                $this->customer,
                ['console_type' => 'PlayStation 5'],
                [$this->ps5Variant->id],
                1000000,
                [$this->ps5Variant->id => $second->id]
            );
        } finally {
            $this->assertDatabaseCount('payments', 0);
        }
    }
}
