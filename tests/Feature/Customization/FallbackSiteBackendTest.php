<?php

namespace Tests\Feature\Customization;

use App\Http\Controllers\V2\Admin\Server\FallbackController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\User;
use App\Services\FallbackSiteService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FallbackSiteBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        Sanctum::actingAs(User::create([
            'email' => Str::uuid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'is_admin' => true,
            'is_staff' => true,
        ]));
    }

    public function test_new_compatible_node_receives_the_builtin_fallback_by_default(): void
    {
        $this->postJson($this->routePath(ManageController::class . '@save'), [
            'type' => 'vless',
            'name' => 'default fallback',
            'host' => 'edge.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'show' => 1,
            'enabled' => false,
            'group_ids' => [],
            'protocol_settings' => [
                'tls' => 1,
                'server_tls' => 1,
                'network' => 'tcp',
                'network_settings' => [],
                'flow' => '',
                'tls_settings' => ['server_name' => 'edge.example.com'],
            ],
        ])->assertOk();

        $server = Server::query()->where('name', 'default fallback')->firstOrFail();
        $this->assertSame(FallbackSiteService::defaultConfig(), $server->fallback_site);
        $wire = ServerService::buildNodeConfig($server);
        $this->assertSame('builtin', $wire['fallback_site']['mode']);
        $this->assertStringContainsString('<!doctype html>', strtolower($wire['fallback_site']['content']));
    }

    public function test_templates_upload_proxy_and_raw_modes_are_available(): void
    {
        Storage::fake('local');
        $catalog = $this->getJson($this->routePath(FallbackController::class . '@templates'))
            ->assertOk()
            ->assertJsonPath('data.default', 'portal')
            ->assertJsonCount(3, 'data.templates')
            ->json('data');
        $this->assertSame(['builtin', 'upload', 'proxy', 'raw'], $catalog['modes']);

        $asset = $this->post($this->routePath(FallbackController::class . '@upload'), [
            'page' => UploadedFile::fake()->createWithContent(
                'cover.html',
                '<!doctype html><html><body>custom cover</body></html>'
            ),
        ])->assertOk()->json('data.asset');
        Storage::disk('local')->assertExists('fallback-sites/' . $asset);

        $uploaded = $this->server([
            'enabled' => true,
            'mode' => 'upload',
            'asset' => $asset,
        ]);
        $this->assertStringContainsString('custom cover', ServerService::buildNodeConfig($uploaded)['fallback_site']['content']);

        $proxy = $this->server([
            'enabled' => true,
            'mode' => 'proxy',
            'upstream' => ['host' => 'service.internal', 'port' => 8080, 'scheme' => 'auto'],
        ]);
        $this->assertSame('service.internal', ServerService::buildNodeConfig($proxy)['fallback_site']['upstream']['host']);

        $raw = $this->server([
            'enabled' => true,
            'mode' => 'raw',
            'raw' => [['name' => 'example.com', 'dest' => '127.0.0.1:8080']],
        ]);
        $this->assertSame('127.0.0.1:8080', ServerService::buildNodeConfig($raw)['fallback_site']['raw'][0]['dest']);
    }

    public function test_incompatible_reality_fallback_is_rejected(): void
    {
        $server = $this->server(FallbackSiteService::defaultConfig(), tls: 2);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(FallbackSiteService::class)->validate($server);
    }

    public function test_raw_fallback_survives_server_save_request_validation(): void
    {
        $server = $this->server(['enabled' => false, 'mode' => 'builtin', 'template' => 'portal']);
        $raw = [['name' => 'docs.example.com', 'alpn' => 'http/1.1', 'dest' => 8080]];

        $this->postJson($this->routePath(ManageController::class . '@save'), [
            'id' => $server->id,
            'type' => 'vless',
            'name' => $server->name,
            'host' => $server->host,
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'show' => 1,
            'enabled' => false,
            'group_ids' => [],
            'protocol_settings' => $server->protocol_settings,
            'fallback_site' => ['enabled' => true, 'mode' => 'raw', 'raw' => $raw],
        ])->assertOk();

        $this->assertSame($raw, $server->fresh()->fallback_site['raw']);
    }

    private function server(array $fallbackSite, int $tls = 1): Server
    {
        return Server::withoutEvents(fn () => Server::create([
            'name' => Str::uuid(),
            'type' => 'vless',
            'host' => 'edge.example.com',
            'port' => 443,
            'server_port' => 443,
            'group_ids' => [],
            'rate' => 1,
            'enabled' => false,
            'show' => true,
            'protocol_settings' => [
                'tls' => $tls,
                'server_tls' => $tls,
                'network' => 'tcp',
                'network_settings' => [],
                'flow' => '',
                'tls_settings' => [],
                'reality_settings' => [],
            ],
            'fallback_site' => $fallbackSite,
        ]));
    }

    private function routePath(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === $action) {
                return '/' . ltrim($route->uri(), '/');
            }
        }
        $this->fail("Route not found for {$action}");
    }
}
