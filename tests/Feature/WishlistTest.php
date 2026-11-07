<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_add_and_remove_games_by_id_or_slug(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['title' => 'Elden Ring', 'slug' => 'elden-ring']);

        $this->actingAs($user)->postJson(route('api.wishlist.store', $game->id))->assertOk()->assertJsonPath('in_wishlist', true);
        $this->actingAs($user)->postJson(route('api.wishlist.store', 'elden-ring'))->assertOk();

        $this->assertDatabaseCount('wishlists', 1);

        $this->actingAs($user)
            ->getJson(route('api.wishlist.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Elden Ring')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($user)->deleteJson(route('api.wishlist.destroy', 'elden-ring'))->assertOk()->assertJsonPath('in_wishlist', false);

        $this->actingAs($user)->getJson(route('api.wishlist.index'))->assertJsonCount(0, 'data');
    }

    public function test_each_customer_only_sees_their_own_list(): void
    {
        $game = Game::factory()->create();
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJson(route('api.wishlist.store', $game->id))->assertOk();

        $this->actingAs(User::factory()->create())->getJson(route('api.wishlist.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_games_and_guests_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('api.wishlist.store', 'no-such-game'))->assertNotFound();

        $this->app['auth']->forgetGuards();

        $this->getJson(route('api.wishlist.index'))->assertUnauthorized();
    }
}
