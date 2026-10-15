<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Platform;
use App\Models\User;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $customer;
    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create();

        $this->platform = Platform::create([
            'name' => 'PS5',
            'slug' => 'ps5',
        ]);
    }

    public function test_public_can_browse_games_catalog_and_view_details_by_slug_and_id(): void
    {
        $game = Game::create([
            'title' => 'Cyberpunk 2077',
            'slug' => 'cyberpunk-2077',
            'genre' => 'RPG',
            'description' => 'Night City open-world adventure',
            'developer' => 'CD Projekt Red',
            'is_available' => true,
        ]);

        GameVariant::create([
            'game_id' => $game->id,
            'platform_id' => $this->platform->id,
            'price' => 95000,
            'size' => 70,
            'is_available' => true,
        ]);

        // 1. Browse catalog
        $indexResponse = $this->get(route('games.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('Cyberpunk 2077');

        // 2. View details by slug
        $slugResponse = $this->get('/games/cyberpunk-2077');
        $slugResponse->assertOk();
        $slugResponse->assertSee('Cyberpunk 2077');
        $slugResponse->assertSee('PS5');

        // 3. View details by numeric ID
        $idResponse = $this->get("/games/{$game->id}");
        $idResponse->assertOk();
        $idResponse->assertSee('Cyberpunk 2077');
    }

    public function test_guests_and_customers_cannot_access_game_creation(): void
    {
        $this->get(route('admin.games.create'))->assertRedirect(route('login'));
        $this->actingAs($this->customer)->get(route('admin.games.create'))->assertForbidden();

        $this->actingAs($this->customer)->post(route('admin.games.store'), [
            'title' => 'Hacker Game',
            'genre' => 'Action',
        ])->assertForbidden();
    }

    public function test_admin_can_create_new_game(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.games.store'), [
            'title' => 'The Witcher 3',
            'genre' => 'RPG',
            'developer' => 'CD Projekt Red',
            'description' => 'Monster hunter adventure',
            'price' => 80000,
            'size' => 50,
        ]);

        $response->assertRedirect(route('admin.games.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('games', [
            'title' => 'The Witcher 3',
            'slug' => 'the-witcher-3',
            'genre' => 'RPG',
            'developer' => 'CD Projekt Red',
        ]);
    }

    public function test_admin_can_update_existing_game(): void
    {
        $game = Game::create([
            'title' => 'Old Title',
            'slug' => 'old-title',
            'genre' => 'Action',
            'is_available' => true,
        ]);

        $response = $this->actingAs($this->admin)->patch(route('admin.games.update', $game), [
            'title' => 'Updated Super Game',
            'genre' => 'Action-Adventure',
            'developer' => 'Master Studio',
            'is_available' => true,
        ]);

        $response->assertRedirect(route('admin.games.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('games', [
            'id' => $game->id,
            'title' => 'Updated Super Game',
            'genre' => 'Action-Adventure',
            'developer' => 'Master Studio',
        ]);
    }

    public function test_admin_cannot_delete_game_that_has_customer_orders(): void
    {
        $game = Game::create([
            'title' => 'Protected Game',
            'slug' => 'protected-game',
            'genre' => 'Action',
            'is_available' => true,
        ]);

        $variant = GameVariant::create([
            'game_id' => $game->id,
            'platform_id' => $this->platform->id,
            'price' => 50000,
            'size' => 40,
            'is_available' => true,
        ]);

        $order = Order::create([
            'user_id' => $this->customer->id,
            'console_type' => 'PS5',
            'total' => 50000,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Paid,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'game_id' => $game->id,
            'game_variant_id' => $variant->id,
            'price' => 50000,
            'installation_status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.games.destroy', $game));

        $response->assertRedirect(route('admin.games.index'));
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('games', [
            'id' => $game->id,
            'title' => 'Protected Game',
        ]);
    }

    public function test_admin_can_delete_game_without_orders(): void
    {
        $game = Game::create([
            'title' => 'Disposable Game',
            'slug' => 'disposable-game',
            'genre' => 'Indie',
            'is_available' => true,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.games.destroy', $game));

        $response->assertRedirect(route('admin.games.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('games', [
            'id' => $game->id,
        ]);
    }
}

