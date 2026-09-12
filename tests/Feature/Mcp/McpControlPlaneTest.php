<?php

namespace Tests\Feature\Mcp;

use App\Http\Controllers\V2\Admin\McpController;
use App\Http\Controllers\V2\Admin\NoticeController;
use App\Models\McpKey;
use App\Models\Server;
use App\Models\User;
use App\Services\AdminOperationCatalog;
use App\Services\McpKeyService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class McpControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
    }

    public function test_admin_creates_one_time_hashed_key_and_can_revoke_it(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $created = $this->postJson($this->routePath(McpController::class . '@create'), [
            'name' => 'Codex local',
            'scope' => 'custom',
            'domains' => ['infrastructure', 'operations'],
            'client' => 'codex',
            'expires_in_days' => 90,
        ])->assertOk()->json('data');

        $this->assertStringStartsWith('xbmcp_', $created['secret']);
        $key = McpKey::query()->findOrFail($created['key']['id']);
        $this->assertSame(hash('sha256', $created['secret']), $key->token_hash);
        $this->assertNotSame($created['secret'], $key->token_hash);

        $list = $this->getJson($this->routePath(McpController::class . '@keys'))
            ->assertOk()
            ->assertJsonMissing(['secret' => $created['secret']])
            ->json('data.0');
        $this->assertArrayNotHasKey('token_hash', $list);

        $this->postJson($this->routePath(McpController::class . '@revoke'), ['id' => $key->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->mcp($created['secret'], 'ping')->assertUnauthorized();
    }

    public function test_transport_supports_legacy_initialize_and_modern_discovery(): void
    {
        $secret = $this->secretFor();

        $this->mcp($secret, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'PHPUnit', 'version' => '1'],
        ])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-11-25')
            ->assertJsonPath('result.serverInfo.name', 'xboard-admin');

        $modernMeta = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']];
        $this->mcp($secret, 'server/discover', $modernMeta, [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'server/discover',
        ])->assertOk()
            ->assertJsonPath('result.supportedVersions.0', '2026-07-28')
            ->assertJsonPath('result.resultType', 'complete');

        $this->mcp($secret, 'server/discover', $modernMeta, [
            'MCP-Protocol-Version' => '',
            'Mcp-Method' => '',
        ])
            ->assertBadRequest()
            ->assertJsonPath('error.code', -32020);
    }

    public function test_live_catalog_covers_every_business_admin_route(): void
    {
        $catalog = app(AdminOperationCatalog::class)->operations();
        $catalogActions = collect($catalog)->pluck('route_action')->unique()->values();
        $expectedActions = collect(app('router')->getRoutes())
            ->filter(function ($route) {
                if (!$route instanceof Route) {
                    return false;
                }
                $middleware = $route->gatherMiddleware();
                return in_array('admin', $middleware, true)
                    && in_array('log', $middleware, true)
                    && !str_contains($route->uri(), '/mcp/');
            })
            ->map(fn(Route $route) => $route->getActionName())
            ->unique()
            ->values();

        $missing = $expectedActions->diff($catalogActions)->values()->all();
        $this->assertSame([], $missing, 'Admin route actions missing from MCP catalog: ' . implode(', ', $missing));
        $this->assertGreaterThan(80, count($catalog));
        $this->assertSame(
            ['accounts', 'finance', 'infrastructure', 'operations', 'system'],
            collect($catalog)->pluck('domain')->unique()->sort()->values()->all()
        );
        $this->assertFalse(collect($catalog)->contains(fn(array $item) => str_starts_with($item['path'], 'mcp/')));
    }

    public function test_read_and_custom_keys_only_receive_permitted_tools_and_operations(): void
    {
        $readSecret = $this->secretFor('read');
        $tools = $this->mcp($readSecret, 'tools/list')->assertOk()->json('result.tools');
        $this->assertNull(collect($tools)->firstWhere('name', 'xboard_admin_mutate'));

        $customSecret = $this->secretFor('custom', ['finance']);
        $catalog = $this->callTool($customSecret, 'xboard_admin_catalog', ['limit' => 100])
            ->assertOk()
            ->json('result.structuredContent.operations');
        $this->assertNotEmpty($catalog);
        $this->assertSame(['finance'], collect($catalog)->pluck('domain')->unique()->values()->all());

        $this->callTool($customSecret, 'xboard_admin_read', ['operation' => 'notice.fetch.get'])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_mcp_reads_and_mutates_through_existing_admin_routes_with_atomic_versioning(): void
    {
        $secret = $this->secretFor();

        $this->callTool($secret, 'xboard_admin_read', ['operation' => 'notice.fetch.get'])
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 200);

        $mutation = $this->callTool($secret, 'xboard_admin_mutate', [
            'operation' => 'notice.save.post',
            'expected_change_version' => 0,
            'parameters' => ['title' => 'MCP notice', 'content' => 'Created through the existing controller.'],
        ])->assertOk();
        $mutation->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 200)
            ->assertJsonPath('result.structuredContent.change_version', 1);

        $this->assertDatabaseHas('v2_notice', ['title' => 'MCP notice']);
        $this->assertDatabaseHas('v2_change_event', [
            'version' => 1,
            'action' => 'notice.save.post',
            'actor_type' => 'mcp',
        ]);
        $this->assertDatabaseHas('v2_admin_audit_log', [
            'action' => 'notice.save.post',
            'actor_type' => 'mcp',
        ]);

        $this->callTool($secret, 'xboard_admin_mutate', [
            'operation' => 'notice.save.post',
            'expected_change_version' => 0,
            'parameters' => ['title' => 'Must roll back', 'content' => 'Stale version.'],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.status', 409)
            ->assertJsonPath('result.structuredContent.body.data.current_version', 1);

        $this->assertDatabaseMissing('v2_notice', ['title' => 'Must roll back']);
        $this->assertDatabaseCount('v2_change_event', 1);
    }

    public function test_mcp_preserves_nested_json_for_xray_preflight(): void
    {
        $secret = $this->secretFor();
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'MCP Xray test',
            'type' => 'vless',
            'host' => 'localhost',
            'port' => 18080,
            'server_port' => 18080,
            'rate' => 1,
            'protocol_settings' => [
                'tls' => 0,
                'flow' => '',
                'tls_settings' => [],
            ],
        ]));

        $this->callTool($secret, 'xboard_admin_mutate', [
            'operation' => 'server.xray.validate.post',
            'expected_change_version' => 0,
            'parameters' => [
                'node_id' => $node->id,
                'expected_revision' => 0,
                'xray_config' => [
                    'routing' => ['rules' => []],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 200)
            ->assertJsonPath('result.structuredContent.body.data.valid', true);
    }

    public function test_dangerous_mutation_requires_exact_catalog_confirmation(): void
    {
        $secret = $this->secretFor();
        $notice = \App\Models\Notice::query()->create(['title' => 'Delete me', 'content' => 'test']);

        $arguments = [
            'operation' => 'notice.drop.post',
            'expected_change_version' => 0,
            'parameters' => ['id' => $notice->id],
        ];
        $this->callTool($secret, 'xboard_admin_mutate', $arguments)
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseHas('v2_notice', ['id' => $notice->id]);

        $arguments['confirmation'] = 'CONFIRM notice.drop.post';
        $this->callTool($secret, 'xboard_admin_mutate', $arguments)
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertDatabaseMissing('v2_notice', ['id' => $notice->id]);
    }

    public function test_direct_admin_mutation_emits_sync_event_with_client_identity(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $clientId = 'admin-test-tab';

        $this->withHeader('X-Xboard-Admin-Client-Id', $clientId)
            ->postJson($this->routePath(NoticeController::class . '@save'), [
                'title' => 'Admin notice',
                'content' => 'Browser mutation',
            ])->assertOk();

        $this->assertDatabaseHas('v2_change_event', [
            'version' => 1,
            'action' => 'notice.save.post',
            'actor_type' => 'admin',
            'client_id' => $clientId,
        ]);
    }

    public function test_change_stream_returns_a_short_sse_frame_without_holding_a_worker(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $startedAt = microtime(true);

        $response = $this->get($this->routePath(McpController::class . '@events'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString("retry: 1000\n\n", $content);
        $this->assertStringContainsString("event: heartbeat\n", $content);
        $this->assertLessThan(0.5, microtime(true) - $startedAt);
    }

    public function test_disabled_service_invalid_origin_and_expired_key_are_rejected(): void
    {
        $secret = $this->secretFor();
        $this->withHeader('Origin', 'https://attacker.example')
            ->mcp($secret, 'ping')
            ->assertForbidden()
            ->assertJsonPath('error.code', -32021);

        McpKey::query()->update(['expires_at' => time() - 1]);
        $this->mcp($secret, 'ping', [], ['Origin' => ''])->assertUnauthorized();

        $fresh = $this->secretFor();
        app(McpKeyService::class)->setEnabled(false);
        $this->mcp($fresh, 'ping', [], ['Origin' => ''])
            ->assertStatus(503)
            ->assertJsonPath('error.code', -32003);
    }

    private function secretFor(string $scope = 'full', array $domains = []): string
    {
        $created = app(McpKeyService::class)->create($this->makeAdmin(), [
            'name' => 'PHPUnit agent',
            'scope' => $scope,
            'domains' => $domains,
            'client' => 'generic',
            'expires_in_days' => 30,
        ]);

        return $created['secret'];
    }

    private function mcp(string $secret, string $method, array $params = [], array $headers = [])
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer ' . $secret,
            'Accept' => 'application/json',
        ], $headers))->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => (string) Str::uuid(),
            'method' => $method,
            'params' => $params,
        ]);
    }

    private function callTool(string $secret, string $name, array $arguments)
    {
        return $this->mcp($secret, 'tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::query()->create([
            'email' => Str::uuid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'is_admin' => true,
            'is_staff' => false,
        ]);
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
