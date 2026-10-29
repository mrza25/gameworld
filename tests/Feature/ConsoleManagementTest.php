<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Order;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Platform::query()->delete();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_public_console_list_exposes_display_details_in_sort_order(): void
    {
        Platform::create(['name' => 'PlayStation 5 (کپی‌خور)', 'slug' => 'ps5-copy', 'short' => 'PS5 کپی‌خور', 'brand' => 'playstation', 'sort_order' => 20]);
        Platform::create(['name' => 'PlayStation 5', 'slug' => 'ps5', 'short' => 'PS5', 'sort_order' => 10]);

        $this->getJson(route('api.consoles'))
            ->assertOk()
            ->assertJsonPath('data.0', [
                'id' => Platform::where('slug', 'ps5')->value('id'),
                'value' => 'ps5',
                'label' => 'PlayStation 5',
                'short' => 'PS5',
                'brand' => 'playstation',
                'family' => 'ps5',
                'mode' => 'standard',
                'sort_order' => 10,
            ])
            ->assertJsonPath('data.1.family', 'ps5')
            ->assertJsonPath('data.1.mode', 'copy');
    }

    public function test_admin_can_create_console_and_public_list_is_refreshed(): void
    {
        $this->getJson(route('api.consoles'))->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.consoles.store'), [
                'label' => 'Nintendo Switch',
                'value' => 'switch',
                'short' => 'Switch',
                'brand' => 'nintendo',
            ])
            ->assertCreated()
            ->assertJsonPath('data.value', 'switch')
            ->assertJsonPath('data.brand', 'nintendo');

        $this->getJson(route('api.consoles'))->assertOk()->assertJsonPath('data.0.short', 'Switch');
    }

    public function test_renaming_console_keeps_existing_orders_linked(): void
    {
        $platform = Platform::create(['name' => 'PlayStation 5', 'slug' => 'ps5']);
        $order = User::factory()->create()->orders()->create([
            'console_type' => 'PlayStation 5', 'total' => 100000, 'status' => 'pending', 'payment_status' => 'paid',
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.consoles.update', $platform->id), ['label' => 'PS5 Pro'])
            ->assertOk()
            ->assertJsonPath('data.label', 'PS5 Pro');

        $this->assertSame('PS5 Pro', Order::findOrFail($order->id)->console_type);
    }

    public function test_console_used_by_games_cannot_change_value_or_be_deleted(): void
    {
        $platform = Platform::create(['name' => 'PlayStation 5', 'slug' => 'ps5']);
        Game::create(['title' => 'GoW', 'slug' => 'gow', 'genre' => 'Action'])
            ->variants()->create(['platform_id' => $platform->id, 'price' => 100000, 'size' => 50, 'is_available' => true]);

        $this->actingAs($this->admin)
            ->patchJson(route('api.admin.consoles.update', 'ps5'), ['value' => 'playstation-5'])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->actingAs($this->admin)
            ->deleteJson(route('api.admin.consoles.delete', $platform->id))
            ->assertUnprocessable();

        $this->assertDatabaseHas('platforms', ['id' => $platform->id, 'slug' => 'ps5']);
    }

    public function test_unused_console_can_be_deleted_and_admin_list_shows_usage(): void
    {
        $unused = Platform::create(['name' => 'Old Console', 'slug' => 'old']);

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.consoles.index'))
            ->assertOk()
            ->assertJsonPath('data.0.variants_count', 0);

        $this->actingAs($this->admin)->deleteJson(route('api.admin.consoles.delete', $unused->id))->assertOk();

        $this->assertDatabaseMissing('platforms', ['id' => $unused->id]);
    }

    public function test_console_management_requires_admin(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('api.admin.consoles.store'), ['label' => 'X', 'value' => 'x'])
            ->assertForbidden();
    }
}
