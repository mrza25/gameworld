<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameRating;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GamePinTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createGame(string $title, array $attributes = []): Game
    {
        return Game::create($attributes + ['title' => $title, 'genre' => 'Action', 'is_available' => true]);
    }

    private function pin(Game $game, bool $isPinned = true): TestResponse
    {
        return $this->actingAs($this->admin)->patchJson(route('api.admin.games.pin', $game->id), ['is_pinned' => $isPinned]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, string>
     */
    private function productTitles(array $query = []): array
    {
        $response = $this->getJson(route('api.products.index', $query))->assertOk();

        return array_column($response->json('data'), 'title');
    }

    public function test_admin_can_pin_and_unpin_a_game(): void
    {
        $game = $this->createGame('A');

        $this->pin($game)->assertOk()->assertJsonPath('data.is_pinned', true);
        $this->assertNotNull($game->fresh()->pinned_at);

        $this->pin($game, false)->assertOk()->assertJsonPath('data.is_pinned', false);
        $this->assertNull($game->fresh()->pinned_at);
    }

    public function test_pin_requires_a_boolean(): void
    {
        $game = $this->createGame('A');

        $this->actingAs($this->admin)->patchJson(route('api.admin.games.pin', $game->id), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_pinned');

        $this->actingAs($this->admin)->patchJson(route('api.admin.games.pin', $game->id), ['is_pinned' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_pinned');
    }

    public function test_only_admins_can_pin(): void
    {
        $game = $this->createGame('A');

        $this->patchJson(route('api.admin.games.pin', $game->id), ['is_pinned' => true])->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->patchJson(route('api.admin.games.pin', $game->id), ['is_pinned' => true])
            ->assertForbidden();

        $this->assertFalse($game->fresh()->is_pinned);
    }

    public function test_pinned_games_come_first_with_the_latest_pin_on_top(): void
    {
        $a = $this->createGame('A');
        $this->createGame('B');
        $c = $this->createGame('C');

        $this->assertSame(['C', 'B', 'A'], $this->productTitles());

        $this->pin($a);
        $this->assertSame(['A', 'C', 'B'], $this->productTitles());

        $this->travel(1)->minutes();
        $this->pin($c);
        $this->assertSame(['C', 'A', 'B'], $this->productTitles());

        $this->travel(1)->minutes();
        $this->pin($a);
        $this->assertSame(['C', 'A', 'B'], $this->productTitles(), 'Pinning an already pinned game must not move it.');

        $this->pin($c, false);
        $this->assertSame(['A', 'C', 'B'], $this->productTitles());
    }

    public function test_pins_come_before_every_sort(): void
    {
        $lowest = $this->createGame('Lowest', ['sales' => 1, 'size' => 90]);
        $middle = $this->createGame('Middle', ['sales' => 5, 'size' => 50]);
        $highest = $this->createGame('Highest', ['sales' => 9, 'size' => 10]);

        foreach ([[$lowest, 1], [$middle, 3], [$highest, 5]] as [$game, $rating]) {
            GameRating::create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'rating' => $rating]);
        }

        $this->assertSame(['Highest', 'Middle', 'Lowest'], $this->productTitles(['sort' => 'popular']));
        $this->assertSame(['Highest', 'Middle', 'Lowest'], $this->productTitles(['sort' => 'rating']));
        $this->assertSame(['Highest', 'Middle', 'Lowest'], $this->productTitles(['sort' => 'size']));

        $this->pin($lowest);

        $this->assertSame(['Lowest', 'Highest', 'Middle'], $this->productTitles(['sort' => 'popular']));
        $this->assertSame(['Lowest', 'Highest', 'Middle'], $this->productTitles(['sort' => 'rating']));
        $this->assertSame(['Lowest', 'Highest', 'Middle'], $this->productTitles(['sort' => 'size']));
        $this->assertSame(['Lowest', 'Highest', 'Middle'], $this->productTitles(['sort' => 'newest']));
    }

    public function test_pins_work_with_search_filters_pagination_and_the_games_alias(): void
    {
        $pinnedMatch = $this->createGame('Dark Souls');
        $this->createGame('Dark Forest');
        $pinnedOther = $this->createGame('Other');
        $hidden = $this->createGame('Dark Hidden', ['is_available' => false]);
        $this->pin($pinnedMatch);
        $this->pin($pinnedOther);
        $this->pin($hidden);

        $this->assertSame(['Dark Souls', 'Dark Forest'], $this->productTitles(['search' => 'Dark']));

        $this->getJson(route('api.products.index', ['per_page' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('meta.total', 3);

        $this->assertTrue($this->getJson(route('api.games.index'))->json('data.0.is_pinned'));
    }

    public function test_admin_game_list_puts_pins_first(): void
    {
        $first = $this->createGame('First');
        $this->createGame('Second');
        $this->pin($first);

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.games.index'))
            ->assertOk()
            ->assertJsonPath('data.data.0.title', 'First')
            ->assertJsonPath('data.data.0.is_pinned', true)
            ->assertJsonPath('data.data.1.is_pinned', false);
    }

    public function test_game_create_and_update_accept_is_pinned_including_form_data_values(): void
    {
        Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);

        $gameId = $this->actingAs($this->admin)->post(route('api.admin.games.store'), [
            'title' => 'Pinned on create',
            'genre' => 'Action',
            'price' => 1000000,
            'is_pinned' => 'true',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.is_pinned', true)
            ->json('data.id');

        $this->assertNotNull(Game::findOrFail($gameId)->pinned_at);

        $this->actingAs($this->admin)
            ->post(route('api.admin.games.update', $gameId), ['is_pinned' => 'false'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.is_pinned', false);

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.games.store'), ['title' => 'Default', 'genre' => 'Action', 'price' => 1000000])
            ->assertCreated()
            ->assertJsonPath('data.is_pinned', false);
    }

    public function test_saving_the_game_form_does_not_reorder_pins(): void
    {
        $game = $this->createGame('A');
        $this->pin($game);
        $pinnedAt = $game->fresh()->pinned_at;

        $this->travel(1)->hours();

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.games.update', $game->id), ['title' => 'A edited', 'is_pinned' => true])
            ->assertOk();

        $this->assertEquals($pinnedAt, $game->fresh()->pinned_at);
    }

    public function test_game_update_without_is_pinned_leaves_it_alone(): void
    {
        $game = $this->createGame('A');
        $this->pin($game);

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.games.update', $game->id), ['title' => 'A edited'])
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);
    }
}
