<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the admin games API exactly as the Next.js admin panel calls it:
 * multipart FormData with JSON-encoded arrays and platform slugs.
 */
class AdminGameApiContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Platform $ps5;

    private Platform $ps4;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();
        $this->ps5 = Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PlayStation 5']);
        $this->ps4 = Platform::firstOrCreate(['slug' => 'ps4'], ['name' => 'PlayStation 4']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function frontendFormData(array $variants, array $overrides = []): array
    {
        return array_merge([
            'slug' => 'god-of-war',
            'title' => 'God of War',
            'developer' => 'Santa Monica Studio',
            'genre' => 'Action-Adventure',
            'release_date' => '2018-04-20',
            'age_rating' => '18',
            'is_available' => '1',
            'description' => 'Kratos and Atreus.',
            'modes' => json_encode(['Single', 'Online']),
            'languages' => json_encode(['English', 'Persian']),
            'variants' => json_encode($variants),
        ], $overrides);
    }

    public function test_create_persists_variant_prices_sent_as_json_string_with_platform_slugs(): void
    {
        $response = $this->actingAs($this->admin)->post(route('api.admin.games.store'), $this->frontendFormData([
            ['platform_id' => 'ps5', 'size' => 50, 'price' => 2500000, 'is_available' => true],
            ['platform_id' => 'ps4', 'size' => 45, 'price' => 1800000, 'is_available' => false],
        ], [
            'image' => UploadedFile::fake()->image('cover.jpg'),
            'screenshots' => [UploadedFile::fake()->image('s1.jpg'), UploadedFile::fake()->image('s2.jpg')],
        ]), ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'god-of-war')
            ->assertJsonPath('data.price', 2500000)
            ->assertJsonPath('data.modes', ['Single', 'Online'])
            ->assertJsonPath('data.languages', ['English', 'Persian'])
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonCount(2, 'data.screenshots');

        $game = Game::where('slug', 'god-of-war')->firstOrFail();
        $this->assertSame(2, $game->variants()->count());
        $this->assertEquals(2500000, (float) $game->variants()->where('platform_id', $this->ps5->id)->value('price'));
        $this->assertEquals(1800000, (float) $game->variants()->where('platform_id', $this->ps4->id)->value('price'));
        $this->assertFalse((bool) $game->variants()->where('platform_id', $this->ps4->id)->value('is_available'));

        $this->assertStringContainsString('/storage/games/', $response->json('data.image'));
        $this->assertStringNotContainsString('/storage/storage/', $response->json('data.image'));
        $this->assertStringContainsString('/storage/screenshots/', $response->json('data.screenshots.0'));
        Storage::disk('public')->assertExists($game->image);
    }

    public function test_create_with_unknown_platform_returns_validation_error_instead_of_guessing(): void
    {
        $this->actingAs($this->admin)
            ->post(route('api.admin.games.store'), $this->frontendFormData([
                ['platform_id' => 'nintendo-64', 'size' => 10, 'price' => 100000, 'is_available' => true],
            ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.platform_id']);

        $this->assertDatabaseMissing('games', ['slug' => 'god-of-war']);
    }

    public function test_create_rejects_non_image_uploads(): void
    {
        $this->actingAs($this->admin)
            ->post(route('api.admin.games.store'), $this->frontendFormData([
                ['platform_id' => 'ps5', 'size' => 50, 'price' => 2500000, 'is_available' => true],
            ], [
                'image' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php'),
            ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_create_rejects_duplicate_slug_with_field_error(): void
    {
        Game::create(['title' => 'Existing', 'slug' => 'god-of-war', 'genre' => 'Action']);

        $this->actingAs($this->admin)
            ->post(route('api.admin.games.store'), $this->frontendFormData([
                ['platform_id' => 'ps5', 'size' => 50, 'price' => 2500000, 'is_available' => true],
            ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_multipart_update_syncs_variants_and_replaces_image(): void
    {
        $game = Game::create(['title' => 'God of War', 'slug' => 'god-of-war', 'genre' => 'Action', 'image' => 'games/old.jpg']);
        Storage::disk('public')->put('games/old.jpg', 'old');
        $game->variants()->create(['platform_id' => $this->ps5->id, 'price' => 1000000, 'size' => 40, 'is_available' => true]);
        $game->variants()->create(['platform_id' => $this->ps4->id, 'price' => 900000, 'size' => 40, 'is_available' => true]);

        $response = $this->actingAs($this->admin)->post(route('api.admin.games.update', $game->id), [
            'title' => 'God of War (2018)',
            'is_available' => '0',
            'variants' => json_encode([
                ['platform_id' => 'ps5', 'size' => 55, 'price' => 2700000, 'is_available' => true],
            ]),
            'image' => UploadedFile::fake()->image('new-cover.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.title', 'God of War (2018)')
            ->assertJsonPath('data.is_available', false)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.price', 2700000);

        $this->assertEquals(2700000, (float) $game->variants()->where('platform_id', $this->ps5->id)->value('price'));
        $this->assertFalse($game->variants()->where('platform_id', $this->ps4->id)->exists());
        Storage::disk('public')->assertMissing('games/old.jpg');
    }

    public function test_update_keeps_removed_variant_that_has_orders_but_marks_it_unavailable(): void
    {
        $game = Game::create(['title' => 'God of War', 'slug' => 'god-of-war', 'genre' => 'Action']);
        $game->variants()->create(['platform_id' => $this->ps5->id, 'price' => 1000000, 'size' => 40, 'is_available' => true]);
        $ps4Variant = $game->variants()->create(['platform_id' => $this->ps4->id, 'price' => 900000, 'size' => 40, 'is_available' => true]);

        $order = User::factory()->create()->orders()->create([
            'console_type' => 'PlayStation 4', 'total' => 900000, 'status' => 'pending', 'payment_status' => 'paid',
        ]);
        $order->items()->create(['game_id' => $game->id, 'game_variant_id' => $ps4Variant->id, 'quantity' => 1, 'price' => 900000, 'installation_status' => 'pending']);

        $this->actingAs($this->admin)->patchJson(route('api.admin.games.update', $game->id), [
            'variants' => [['platform_id' => 'ps5', 'size' => 40, 'price' => 1000000, 'is_available' => true]],
        ])->assertOk();

        $this->assertFalse((bool) GameVariant::findOrFail($ps4Variant->id)->is_available);
    }

    public function test_update_can_remove_image_and_replace_screenshot_list(): void
    {
        $game = Game::create([
            'title' => 'God of War', 'slug' => 'god-of-war', 'genre' => 'Action',
            'image' => 'games/cover.jpg', 'screenshots' => ['screenshots/a.jpg', 'screenshots/b.jpg'],
        ]);

        $this->actingAs($this->admin)->post(route('api.admin.games.update', $game->id), [
            'remove_image' => '1',
            'existing_screenshots' => json_encode([url('storage/screenshots/b.jpg')]),
            'screenshots' => [UploadedFile::fake()->image('c.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        $game->refresh();
        $this->assertNull($game->image);
        $this->assertCount(2, $game->screenshots);
        $this->assertSame('screenshots/b.jpg', $game->screenshots[0]);
    }

    public function test_seeded_storage_prefixed_paths_produce_single_storage_url(): void
    {
        $game = Game::create([
            'title' => 'God of War', 'slug' => 'god-of-war', 'genre' => 'Action',
            'image' => 'storage/screenshots/gow-1.jpg', 'screenshots' => ['storage/screenshots/gow-2.jpg', 'screenshots/gow-3.jpg'],
        ]);

        $data = $game->toCatalogArray();

        $this->assertSame(url('storage/screenshots/gow-1.jpg'), $data['image']);
        $this->assertSame([url('storage/screenshots/gow-2.jpg'), url('storage/screenshots/gow-3.jpg')], $data['screenshots']);
    }
}
