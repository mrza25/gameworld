<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What a server behind a proxy depends on.
 */
class DeploymentConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The request class keeps these statically, so tests that run as production leave them behind.
        Request::setTrustedHosts([]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        putenv('CORS_ALLOWED_ORIGINS');

        parent::tearDown();
    }

    private function registerProbeRoute(): void
    {
        Route::get('/probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->secure(),
            'url' => url('/receipt'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function proxyHeaders(): array
    {
        return [
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'api.gameworld.example',
        ];
    }

    public function test_links_and_client_ip_come_from_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);
        $this->registerProbeRoute();

        $this->withHeaders($this->proxyHeaders())
            ->getJson('/probe')
            ->assertOk()
            ->assertJson([
                'ip' => '203.0.113.9',
                'secure' => true,
                'url' => 'https://api.gameworld.example/receipt',
            ]);
    }

    public function test_forwarded_headers_are_ignored_unless_a_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);
        $this->registerProbeRoute();

        $this->withHeaders($this->proxyHeaders())
            ->getJson('/probe')
            ->assertOk()
            ->assertJson(['ip' => '127.0.0.1', 'secure' => false]);
    }

    public function test_only_the_app_host_is_trusted(): void
    {
        $this->app['env'] = 'staging';
        config(['app.url' => 'https://api.gameworld.example']);

        $this->get('https://api.gameworld.example/up')->assertOk();

        $this->get('https://attacker.example/up')->assertBadRequest();
        $this->get('https://api.gameworld.example.attacker.example/up')->assertBadRequest();
    }

    public function test_extra_frontend_origins_can_be_allowed(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=https://front.example/, https://preview.example');
        config(['cors.allowed_origins' => (require config_path('cors.php'))['allowed_origins']]);

        foreach (['https://front.example', 'https://preview.example'] as $origin) {
            $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'])
                ->options('/api/health')
                ->assertHeader('Access-Control-Allow-Origin', $origin);
        }

        $this->withHeaders(['Origin' => 'https://attacker.example', 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/health')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_seeding_a_public_server_makes_no_accounts_without_a_chosen_password(): void
    {
        $this->app['env'] = 'staging';
        config(['app.seed_password' => null]);

        $this->seed();

        $this->assertGreaterThan(0, Game::count());
        $this->assertSame(0, User::count());
    }

    public function test_seeding_a_public_server_uses_the_chosen_password(): void
    {
        $this->app['env'] = 'staging';
        config(['app.seed_password' => 'a-chosen-secret']);

        $this->seed();

        $admin = User::where('phonenumber', '09120000000')->sole();

        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('a-chosen-secret', $admin->password));
        $this->assertFalse(Hash::check('password123', $admin->password));
    }

    public function test_seeding_for_local_development_keeps_the_default_password(): void
    {
        config(['app.seed_password' => null]);

        $this->seed();

        $admin = User::where('phonenumber', '09120000000')->sole();

        $this->assertTrue(Hash::check('password123', $admin->password));
    }
}
