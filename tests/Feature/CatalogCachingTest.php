<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_banner_cache_is_invalidated_when_admin_changes_banners(): void
    {
        $admin = User::factory()->admin()->create();

        $this->getJson(route('api.banners'))->assertOk()->assertJsonCount(0, 'data');

        $bannerId = $this->actingAs($admin)
            ->postJson(route('api.admin.banners.store'), [
                'title' => 'حراج پاییزه',
                'image' => 'https://example.com/banner.jpg',
            ])
            ->assertCreated()
            ->json('id');

        $this->getJson(route('api.banners'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.image', 'https://example.com/banner.jpg');

        $this->actingAs($admin)->deleteJson(route('api.admin.banners.delete', $bannerId))->assertOk();

        $this->getJson(route('api.banners'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_consoles_endpoint_returns_platforms(): void
    {
        Platform::firstOrCreate(['slug' => 'ps5'], ['name' => 'PS5']);

        $this->getJson(route('api.consoles'))
            ->assertOk()
            ->assertJsonStructure(['ok', 'data' => ['*' => ['id', 'label', 'value', 'short']]])
            ->assertJsonFragment(['value' => 'ps5']);
    }
}
