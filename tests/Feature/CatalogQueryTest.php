<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameRating;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogQueryTest extends TestCase
{
    use RefreshDatabase;

    private Platform $ps5;

    private Platform $ps4;

    protected function setUp(): void
    {
        parent::setUp();

        Game::query()->delete();
        $this->ps5 = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->ps5->update(['short' => 'PS5', 'brand' => 'playstation']);
        $this->ps4 = Platform::firstOrCreate(['slug' => 'ps4'], ['name' => 'PlayStation 4']);
    }

    /**
     * @param  array<int, int>  $ratings
     */
    private function makeGame(string $slug, array $attributes = [], array $platforms = [], array $ratings = []): Game
    {
        $game = Game::create(array_merge(['title' => $slug, 'slug' => $slug, 'genre' => 'Action', 'is_available' => true], $attributes));

        foreach ($platforms as $platform) {
            $game->variants()->create(['platform_id' => $platform->id, 'price' => 100000, 'size' => 50, 'is_available' => true]);
        }

        foreach ($ratings as $rating) {
            GameRating::create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'rating' => $rating]);
        }

        return $game;
    }

    /**
     * @return list<string>
     */
    private function slugsFor(array $query): array
    {
        return collect($this->getJson(route('api.products.index', $query))->assertOk()->json('data'))->pluck('slug')->all();
    }

    public function test_filters_by_console_brand_and_minimum_rating(): void
    {
        $this->makeGame('ps5-game', ['developer' => 'Santa Monica Studio'], [$this->ps5], [5, 4]);
        $this->makeGame('ps4-game', ['developer' => 'Naughty Dog'], [$this->ps4], [3]);
        $this->makeGame('unrated', ['developer' => 'Santa Monica Studio'], [$this->ps5, $this->ps4]);

        $this->assertEqualsCanonicalizing(['ps5-game', 'unrated'], $this->slugsFor(['console' => 'ps5']));
        $this->assertEqualsCanonicalizing(['ps5-game', 'unrated'], $this->slugsFor(['brand' => 'Santa Monica Studio']));
        $this->assertSame(['ps5-game'], $this->slugsFor(['min_rating' => 4]));
        $this->assertCount(3, $this->slugsFor(['min_rating' => 0]));
    }

    public function test_console_filter_ignores_unavailable_variants(): void
    {
        $game = $this->makeGame('only-ps4-available', [], [$this->ps4]);
        $game->variants()->create(['platform_id' => $this->ps5->id, 'price' => 100000, 'size' => 50, 'is_available' => false]);

        $this->assertSame([], $this->slugsFor(['console' => 'ps5']));
    }

    public function test_sorts_by_rating_size_and_newest(): void
    {
        $this->makeGame('low', ['size' => 80], [$this->ps5], [2]);
        $this->travel(1)->minutes();
        $this->makeGame('high', ['size' => 30], [$this->ps5], [5]);
        $this->travel(1)->minutes();
        $this->makeGame('mid', ['size' => 50], [$this->ps5], [4]);

        $this->assertSame(['high', 'mid', 'low'], $this->slugsFor(['sort' => 'rating']));
        $this->assertSame(['high', 'mid', 'low'], $this->slugsFor(['sort' => 'size']));
        $this->assertSame(['mid', 'high', 'low'], $this->slugsFor(['sort' => 'newest']));
    }

    public function test_pagination_is_opt_in_and_capped(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeGame("game-{$i}", [], [$this->ps5]);
        }

        $this->getJson(route('api.products.index'))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonMissingPath('meta');

        $this->getJson(route('api.products.index', ['per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);

        $this->getJson(route('api.products.index', ['per_page' => 500]))->assertJsonPath('meta.per_page', 48);
    }

    public function test_variants_keep_platform_slug_and_add_platform_info(): void
    {
        $this->makeGame('gow', [], [$this->ps5]);

        $this->getJson(route('api.products.show', 'gow'))
            ->assertOk()
            ->assertJsonPath('data.variants.0.platform', 'ps5')
            ->assertJsonPath('data.variants.0.platform_id', 'ps5')
            ->assertJsonPath('data.variants.0.platform_info.short', 'PS5')
            ->assertJsonPath('data.variants.0.platform_info.brand', 'playstation')
            ->assertJsonPath('data.rating_count', 0);
    }
}
