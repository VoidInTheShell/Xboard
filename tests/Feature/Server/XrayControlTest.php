<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\XrayController;
use App\Http\Controllers\V2\Admin\Server\RuleFileController;
use App\Models\Outbound;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\User;
use App\Services\ServerService;
use App\Services\XrayConfigService;
use App\Support\Setting;
use App\WebSocket\NodeWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class XrayControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
    }

    private function admin(bool $admin = true): void
    {
        Sanctum::actingAs(User::create([
            'email' => Str::uuid() . '@example.com', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin,
        ]));
    }

    private function node(): Server
    {
        return Server::withoutEvents(fn () => Server::create([
            'name' => 'Xray test', 'type' => 'vless', 'host' => 'localhost', 'port' => 18080,
            'server_port' => 18080, 'rate' => 1, 'protocol_settings' => [
                'tls' => 0, 'flow' => '', 'tls_settings' => [],
            ],
        ]));
    }

    private function path(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === XrayController::class . '@' . $action) return '/' . $route->uri();
        }
        $this->fail('Route missing: ' . $action);
    }

    private function ruleFilePath(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === RuleFileController::class . '@' . $action) return '/' . $route->uri();
        }
        $this->fail('Rule file route missing: ' . $action);
    }

    public function test_default_rules_are_visible_but_only_enabled_runtime_rules_are_sent(): void
    {
        $node = $this->node();
        $config = XrayConfigService::defaultNodeConfig();
        $config->routing->rules[] = (object) [
            'type' => 'field',
            'outboundTag' => 'direct',
        ];
        $node->xray_config = $config;
        $node->saveQuietly();

        $snapshot = XrayConfigService::snapshot($node->fresh());
        // Legacy panels stored a matchless direct row as the default route.
        // The first outbound now provides that fallback and the invalid row is
        // omitted from both the editable projection and the runtime payload.
        $this->assertCount(3, $snapshot['effective_config']->routing->rules);
        $this->assertSame('api', $snapshot['effective_config']->routing->rules[0]->outboundTag);
        $this->assertSame('direct', $snapshot['default_outbound_tag']);
        $this->assertSame('direct', $snapshot['effective_config']->outbounds[0]->tag);

        $node = $node->fresh();
        $config = $node->xray_config;
        $config->routing->rules[0]->enabled = false;
        $config->routing->rules[] = $config->routing->rules[0];
        $node->xray_config = $config;
        $canonical = XrayConfigService::effective($node);
        $this->assertTrue($canonical->routing->rules[0]->enabled);
        $this->assertSame(['api'], $canonical->routing->rules[0]->inboundTag);
        $this->assertCount(3, $canonical->routing->rules);

        $config = $node->xray_config;
        $config->routing->rules[1]->enabled = false;
        $node->xray_config = $config;
        $runtime = XrayConfigService::runtime($node);
        $this->assertCount(1, $runtime->routing->rules);
        $this->assertSame('geosite:cn', $runtime->routing->rules[0]->domain[0]);
        $this->assertObjectNotHasProperty('enabled', $runtime->routing->rules[0]);
    }

    public function test_quick_import_supports_subscription_vless_and_proxy_pool_links(): void
    {
        $this->admin();
        $uuid = (string) Str::uuid();
        $subscription = implode("\n", [
            "vless://{$uuid}@edge.example.com:443?security=tls&type=ws&path=%2Fws#Edge-VLESS",
            'socks5://user:passwd@203.0.113.10:1080#Pool-SOCKS',
            'http://proxy:secret@198.51.100.20:8080#Pool-HTTP',
            '203.0.113.11:3128:pool-user:pool-pass',
        ]);
        Http::fake(['https://1.1.1.1/sub' => Http::response(base64_encode($subscription), 200)]);

        $data = $this->postJson($this->path('importOutbounds'), [
            'source' => 'https://1.1.1.1/sub',
        ])->assertOk()->json('data');
        $this->assertCount(4, $data['imported'], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $data['skipped']);
        $this->assertSame(['vless', 'socks', 'http', 'http'], array_column($data['imported'], 'protocol'));
        $this->assertCount(4, Outbound::query()->get());

        $this->postJson($this->path('importOutbounds'), [
            'source' => 'http://127.0.0.1/private-subscription',
        ])->assertStatus(422)->assertJsonValidationErrors('source');
    }

    public function test_rule_file_endpoints_seed_manage_and_report_files(): void
    {
        $this->admin();
        $node = $this->node();
        $node->xray_config = (object) [];
        $node->saveQuietly();

        $files = $this->getJson($this->ruleFilePath('fetch') . '?node_id=' . $node->id)
            ->assertOk()->json('data.files');
        $this->assertSame(['geoip.dat', 'geosite.dat'], array_column($files, 'name'));
        $this->assertTrue($files[0]['built_in']);

        $created = $this->postJson($this->ruleFilePath('save'), [
            'node_id' => $node->id,
            'name' => 'custom-geosite.dat',
            'source' => 'remote',
            'url' => 'https://1.1.1.1/custom-geosite.dat',
            'auto_update' => true,
            'update_interval_hours' => 12,
        ])->assertOk()->json('data');
        $this->assertFalse($created['built_in']);

        $wire = ServerService::buildNodeConfig($node->fresh());
        $this->assertCount(3, $wire['rule_files']);
        $this->assertSame('custom-geosite.dat', $wire['rule_files'][2]['name']);

        \App\Models\XrayRuleFile::query()->whereKey($created['id'])->update([
            'status' => 'failed', 'error' => 'previous failure',
        ]);
        ServerService::updateMetrics($node->fresh(), ['rule_files' => [[
            'id' => $created['id'], 'size' => 2048, 'updated_at' => 1_725_000_000,
            'status' => 'ready',
        ]]]);
        $updated = $this->getJson($this->ruleFilePath('fetch') . '?node_id=' . $node->id)
            ->assertOk()->json('data.files.2');
        $this->assertSame(2048, $updated['size']);
        $this->assertNotNull($updated['updated_at']);
        $this->assertNull($updated['error']);

        $this->postJson($this->ruleFilePath('download'), [
            'node_id' => $node->id, 'id' => $created['id'],
        ])->assertOk();
        $this->postJson($this->ruleFilePath('drop'), [
            'node_id' => $node->id, 'id' => $created['id'],
        ])->assertOk();
        $this->assertDatabaseMissing('v2_xray_rule_file', ['id' => $created['id']]);
    }

    public function test_native_configuration_round_trips_and_reaches_node_with_revision(): void
    {
        $this->admin();
        $node = $this->node();
        $config = json_decode('{"dns":{"servers":["1.1.1.1"]},"stats":{},"inbounds":[{"sniffing":{"enabled":true}}],"outbounds":[{"tag":"edge","protocol":"freedom","settings":{}}],"routing":{"rules":[{"type":"field","domain":["example.com"],"outboundTag":"edge"}]}}');
        $result = $this->postJson($this->path('save'), ['node_id' => $node->id, 'xray_config' => $config, 'expected_revision' => 0])
            ->assertOk()->assertJsonPath('data.config_revision', 1)->json('data');
        $wire = ServerService::buildNodeConfig($node->fresh());
        $this->assertSame('xray', $wire['kernel_type']);
        $this->assertSame(1, $wire['config_revision']);
        $this->assertSame($result['config_hash'], $wire['config_hash']);
        $this->assertSame('direct', $wire['xray_config']->outbounds[0]->tag);
        $this->assertSame('edge', $wire['xray_config']->outbounds[1]->tag);
        $this->assertSame('block', $wire['xray_config']->outbounds[2]->tag);
        $this->assertIsInt($result['config_revision']);
        $this->assertSame('vless-in', $result['managed_inbound']['tag']);
        $this->assertSame('0.0.0.0', $result['managed_inbound']['listen']);
        $this->postJson($this->path('save'), ['node_id' => $node->id, 'xray_config' => $config, 'expected_revision' => 0])->assertStatus(422);
    }

    public function test_vless_parameter_generation_is_admin_only_paired_and_not_cached(): void
    {
        $this->admin(false);
        $this->postJson($this->path('generateVlessEncryption'))->assertForbidden();
        $this->admin();
        $first = $this->postJson($this->path('generateVlessEncryption'))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $second = $this->postJson($this->path('generateVlessEncryption'))->assertOk()->json('data');
        $this->assertSame(['decryption', 'encryption'], array_keys($first));
        $this->assertMatchesRegularExpression('/^mlkem768x25519plus\.native\.600s\.[A-Za-z0-9_-]{43}$/', $first['decryption']);
        $this->assertMatchesRegularExpression('/^mlkem768x25519plus\.native\.0rtt\.[A-Za-z0-9_-]{43}$/', $first['encryption']);
        $decode = static fn (string $value): string => base64_decode(strtr(explode('.', $value)[3], '-_', '+/'), true);
        $this->assertSame(sodium_crypto_scalarmult_base($decode($first['decryption'])), $decode($first['encryption']));
        $this->assertNotSame($first['decryption'], $second['decryption']);
        $this->assertNotSame($first['encryption'], $second['encryption']);
        $this->assertNotSame(explode('.', $first['decryption'])[3], explode('.', $first['encryption'])[3]);
    }

    public function test_native_credentials_are_not_copied_to_audit_data(): void
    {
        $safe = \App\Http\Middleware\RequestLog::redactRequestData([
            'node_id' => 7,
            'xray_config' => ['inbounds' => [['settings' => ['decryption' => 'private-value']]]],
            'cert_config' => ['key_content' => 'private-pem'],
            'config' => ['settings' => ['password' => 'proxy-password']],
            'other' => ['name' => 'keep', 'nested' => ['api_key' => 'private-token']],
        ]);
        $this->assertSame(7, $safe['node_id']);
        $this->assertSame('[REDACTED]', $safe['xray_config']);
        $this->assertSame('[REDACTED]', $safe['cert_config']);
        $this->assertSame('[REDACTED]', $safe['config']);
        $this->assertSame('keep', $safe['other']['name']);
        $this->assertStringNotContainsString('private-', json_encode($safe));
    }

    public function test_product_fields_and_managed_users_are_rejected_without_saving(): void
    {
        $this->admin();
        $node = $this->node();
        foreach (['{"api":{}}', '{"subSortIndex":1}', '{"inbounds":[{"settings":{"clients":[]}}]}', '{"inbounds":[{"tag":"other"}]}'] as $json) {
            $this->postJson($this->path('save'), ['node_id' => $node->id, 'xray_config' => json_decode($json)])->assertStatus(422);
        }
        $this->assertNull($node->fresh()->xray_config);
    }

    public function test_route_reference_and_outbound_cycles_are_rejected(): void
    {
        $this->admin();
        $node = $this->node();
        foreach ([
            '{"routing":{"rules":[{"outboundTag":"blocked"}]}}',
            '{"outbounds":[{"tag":"a","protocol":"freedom","proxySettings":{"tag":"b"}},{"tag":"b","protocol":"freedom","proxySettings":{"tag":"a"}}]}',
            '{"outbounds":[{"tag":"a","protocol":"freedom","proxySettings":{"tag":"direct"},"streamSettings":{"sockopt":{"dialerProxy":"block"}}}]}',
        ] as $json) {
            $this->postJson($this->path('save'), ['node_id' => $node->id, 'xray_config' => json_decode($json)])->assertStatus(422);
        }
    }

    public function test_bound_candidate_updates_are_resolved_and_revisioned(): void
    {
        $this->admin();
        $node = $this->node();
        $candidate = $this->postJson($this->path('saveOutbound'), [
            'name' => 'Shared exit', 'config' => json_decode('{"tag":"candidate","protocol":"freedom","settings":{}}'),
        ])->assertOk()->json('data');
        $this->postJson($this->path('save'), [
            'node_id' => $node->id, 'xray_config' => (object) [],
            'outbound_bindings' => [['outbound_id' => $candidate['id'], 'tag' => 'local-edge']],
        ])->assertOk()->assertJsonPath('data.effective_config.outbounds.0.tag', 'direct');
        $this->postJson($this->path('saveOutbound'), [
            'id' => $candidate['id'], 'name' => 'Shared exit', 'config' => json_decode('{"tag":"candidate","protocol":"freedom","settings":{"domainStrategy":"UseIP"}}'),
        ])->assertOk();
        $this->assertSame(2, $node->fresh()->config_revision);
        $this->assertSame('UseIP', XrayConfigService::effective($node->fresh())->outbounds[1]->settings->domainStrategy);
        $this->postJson($this->path('dropOutbound'), ['id' => $candidate['id']])->assertStatus(422);
    }

    public function test_live_source_runtime_changes_revision_and_refresh_bound_dependents(): void
    {
        $this->admin();
        $source = $this->node();
        $target = $this->node();
        $candidate = $this->postJson($this->path('saveOutbound'), [
            'name' => 'Live source exit',
            'source_type' => 'server',
            'source_node_id' => $source->id,
            'resolution_mode' => 'live',
            'service_credential' => ['uuid' => (string) Str::uuid()],
        ])->assertOk()->json('data');

        $this->postJson($this->path('bindings'), [
            'node_id' => $target->id,
            'outbound_bindings' => [['outbound_id' => $candidate['id'], 'tag' => 'live-edge']],
            'expected_revision' => 0,
        ])->assertOk();
        $targetRevision = (int) $target->fresh()->config_revision;

        // The normal node editor changes the public endpoint without knowing
        // about the Xray control API.  The observer must advance the source
        // revision once and refresh each live-bound target once.
        $source->update(['host' => 'source-new.example.test', 'port' => 18081, 'server_port' => 18081]);

        $this->assertSame(1, (int) $source->fresh()->config_revision);
        $this->assertSame($targetRevision + 1, (int) $target->fresh()->config_revision);
        $effective = XrayConfigService::effective($target->fresh());
        $this->assertSame('source-new.example.test', $effective->outbounds[1]->settings->vnext[0]->address);
        $this->assertSame(18081, $effective->outbounds[1]->settings->vnext[0]->port);
    }

    public function test_route_definition_changes_advance_affected_node_revision(): void
    {
        $route = ServerRoute::create([
            'remarks' => 'Xray route', 'match' => ['domain' => ['example.com']],
            'action' => 'direct', 'action_value' => null,
        ]);
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'Route node', 'type' => 'vless', 'host' => 'localhost',
            'port' => 18080, 'server_port' => 18080, 'rate' => 1,
            'route_ids' => [$route->id], 'protocol_settings' => [
                'tls' => 0, 'flow' => '', 'tls_settings' => [],
            ],
        ]));

        $route->update(['remarks' => 'Updated Xray route']);

        $this->assertSame(1, (int) $node->fresh()->config_revision);
    }

    public function test_node_push_keeps_native_xray_objects_for_single_and_machine_events(): void
    {
        $native = json_decode('{"policy":{"levels":{"0":{"handshake":8}}},"metrics":{"tag":"Metrics"}}');
        foreach ([
            [
                'node_id' => 7,
                'event' => 'sync.config',
                'data' => ['config' => ['xray_config' => $native]],
            ],
            [
                'machine_id' => 3,
                'event' => 'sync.config',
                'data' => ['nodes' => [['id' => 7, 'xray_config' => $native]]],
            ],
        ] as $message) {
            $decoded = NodeWorker::decodePushPayload(json_encode($message, JSON_THROW_ON_ERROR));
            $this->assertIsArray($decoded);
            $entry = array_key_exists('config', $decoded['data'])
                ? $decoded['data']['config']['xray_config']
                : $decoded['data']['nodes'][0]['xray_config'];
            $this->assertInstanceOf(\stdClass::class, $entry);
            $this->assertInstanceOf(\stdClass::class, $entry->policy);
            $this->assertInstanceOf(\stdClass::class, $entry->policy->levels);
            $this->assertInstanceOf(\stdClass::class, $entry->policy->levels->{'0'});
        }
    }

    public function test_machine_defaults_merge_objects_and_replace_arrays_per_instance(): void
    {
        $this->admin();
        $node = $this->node();
        $machine = ServerMachine::create(['name' => 'test host', 'token' => 'machine-test']);
        Server::withoutEvents(fn () => $node->update(['machine_id' => $machine->id]));
        $this->postJson($this->path('machine'), [
            'machine_id' => $machine->id, 'xray_config' => json_decode('{"dns":{"queryStrategy":"UseIP","servers":["1.1.1.1"]}}'),
        ])->assertOk();
        $this->postJson($this->path('save'), [
            'node_id' => $node->id, 'xray_config' => json_decode('{"dns":{"servers":["8.8.8.8"]}}'),
        ])->assertOk()->assertJsonPath('data.effective_config.dns.queryStrategy', 'UseIP')
            ->assertJsonPath('data.effective_config.dns.servers', ['8.8.8.8']);
    }

    public function test_machine_default_sections_can_be_removed_with_null_tombstones(): void
    {
        $this->admin();
        $node = $this->node();
        $machine = ServerMachine::create(['name' => 'tombstone host', 'token' => 'machine-tombstone']);
        Server::withoutEvents(fn () => $node->update(['machine_id' => $machine->id]));
        $this->postJson($this->path('machine'), [
            'machine_id' => $machine->id,
            'xray_config' => json_decode('{"dns":{"servers":["1.1.1.1"]},"metrics":{"tag":"Metrics"}}'),
        ])->assertOk();
        $result = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => json_decode('{"dns":null,"metrics":null}'),
        ])->assertOk()->json('data');
        $this->assertArrayHasKey('dns', (array) $node->fresh()->xray_config);
        $this->assertNull($node->fresh()->xray_config->dns);
        $this->assertArrayNotHasKey('dns', (array) $result['effective_config']);
        $this->assertArrayNotHasKey('metrics', (array) $result['effective_config']);
        $this->assertArrayNotHasKey('dns', (array) ServerService::buildNodeConfig($node->fresh())['xray_config']);
    }

    public function test_apply_report_is_exposed_separately_from_desired_configuration(): void
    {
        $this->admin();
        $node = $this->node();
        ServerService::updateMetrics($node, ['config_apply' => [
            'desired_revision' => 2, 'applied_revision' => 1, 'status' => 'rejected',
            'error' => 'invalid DNS config', 'error_path' => 'xray_config.policy.levels.0',
            'error_reason' => 'invalid_value',
            'error_message' => '配置未应用，请检查该字段',
            'applied_hash' => 'previous', 'credentials' => 'must-not-copy',
        ]]);
        $this->assertSame('rejected', $node->fresh()->xray_apply->status);
        $this->getJson($this->path('fetch') . '?node_id=' . $node->id)->assertOk()
            ->assertJsonPath('data.application.status', 'rejected')
            ->assertJsonPath('data.application.applied_revision', 1)
            ->assertJsonPath('data.application.error_path', 'xray_config.policy.levels.0')
            ->assertJsonPath('data.application.error_reason', 'invalid_value')
            ->assertJsonPath('data.application.error_message', '配置未应用，请检查该字段')
            ->assertJsonMissingPath('data.application.credentials');

        // The snapshot must keep the allow-list even if a legacy row already
        // contains an unexpected report key.
        $node->forceFill(['xray_apply' => (object) [
            'status' => 'applied', 'credentials' => 'must-not-leak',
        ]])->saveQuietly();
        $this->getJson($this->path('fetch') . '?node_id=' . $node->id)->assertOk()
            ->assertJsonPath('data.application.status', 'applied')
            ->assertJsonMissingPath('data.application.credentials');
    }

    public function test_independent_native_inbounds_are_retained_and_subscription_uses_effective_transport(): void
    {
        $this->admin();
        $node = $this->node();
        $config = json_decode('{"inbounds":[{"streamSettings":{"network":"ws","wsSettings":{"path":"/native","headers":{"Host":"edge.example"}},"security":"tls","tlsSettings":{"serverName":"edge.example","allowInsecure":true}}},{"protocol":"dokodemo-door","tag":"local-tunnel","listen":"127.0.0.1","port":30081,"settings":{"address":"127.0.0.1","port":80,"network":"tcp"}}]}');
        $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => $config,
            'outbound_bindings' => [],
        ])->assertOk()
            ->assertJsonPath('data.effective_inbound.streamSettings.network', 'ws')
            ->assertJsonPath('data.effective_inbound.streamSettings.wsSettings.path', '/native')
            ->assertJsonPath('data.effective_config.inbounds.1.protocol', 'dokodemo-door');

        $fresh = $node->fresh();
        $projected = XrayConfigService::projectedProtocolSettings($fresh);
        $this->assertSame('ws', $projected['network']);
        $this->assertSame('/native', data_get($projected, 'network_settings.path'));
        $this->assertSame('edge.example', data_get($projected, 'network_settings.headers.Host'));
        $this->assertSame(1, $projected['tls']);
        $this->assertSame('edge.example', data_get($projected, 'tls_settings.server_name'));

        XrayConfigService::applySubscriptionProjection($fresh);
        $published = $fresh->toArray();
        $published['password'] = (string) Str::uuid();
        $link = \App\Protocols\General::buildVless($published['password'], $published);
        $this->assertStringContainsString('type=ws', $link);
        $this->assertStringContainsString('path=%2Fnative', $link);
        $this->assertStringContainsString('security=tls', $link);
    }

    public function test_hysteria_and_shadowsocks_managed_baselines_match_node_builders(): void
    {
        $this->admin();
        $hysteria = Server::withoutEvents(fn () => Server::create([
            'name' => 'Hysteria baseline', 'type' => 'hysteria', 'host' => 'h.example.test',
            'port' => 8443, 'server_port' => 8443, 'rate' => 1,
            'cert_config' => ['cert_mode' => 'content'],
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => ['up' => 10, 'down' => 20],
                'tls' => ['server_name' => 'h.example.test'],
            ],
        ]));
        $hInbound = XrayConfigService::managedInbound($hysteria);
        $this->assertSame('hysteria', $hInbound->streamSettings->network);
        $this->assertSame(2, $hInbound->streamSettings->hysteriaSettings->version);
        $this->assertSame('tls', $hInbound->streamSettings->security);
        $this->assertSame('10 mbps', $hInbound->streamSettings->finalMask->quicParams->brutalUp);

        $shadowsocks = Server::withoutEvents(fn () => Server::create([
            'name' => 'SS baseline', 'type' => 'shadowsocks', 'host' => 'ss.example.test',
            'port' => 8388, 'server_port' => 8388, 'rate' => 1,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ]));
        $ssInbound = XrayConfigService::managedInbound($shadowsocks);
        $this->assertSame('tcp,udp', $ssInbound->settings->network);
        $this->assertTrue($ssInbound->streamSettings->sockopt->reusePort);
        $this->assertArrayNotHasKey('network', get_object_vars($ssInbound->streamSettings));
    }

    public function test_normal_user_cannot_read_or_write_xray_configuration(): void
    {
        $this->admin(false);
        $node = $this->node();
        $this->getJson($this->path('fetch') . '?node_id=' . $node->id)->assertForbidden();
        $this->postJson($this->path('save'), ['node_id' => $node->id, 'xray_config' => (object) []])->assertForbidden();
        $this->getJson($this->path('outbounds'))->assertForbidden();
    }

    public function test_native_and_legacy_outbounds_cannot_compete(): void
    {
        $this->admin();
        $node = $this->node();
        Server::withoutEvents(fn () => $node->update(['custom_outbounds' => [['tag' => 'legacy', 'protocol' => 'socks', 'settings' => []]]]));
        $this->postJson($this->path('save'), ['node_id' => $node->id, 'xray_config' => json_decode('{"outbounds":[]}')])->assertStatus(422);
    }

    public function test_bindings_endpoint_and_copy_preserve_server_candidate_credentials(): void
    {
        $this->admin();
        $source = $this->node();
        $target = $this->node();
        $credential = ['uuid' => (string) Str::uuid()];
        $candidate = $this->postJson($this->path('saveOutbound'), [
            'name' => 'Source exit',
            'source_type' => 'server',
            'source_node_id' => $source->id,
            'service_credential' => $credential,
            'resolution_mode' => 'pinned',
        ])->assertOk()->json('data');
        $this->assertTrue($candidate['config_redacted']);
        $this->assertSame('***', $candidate['config']['settings']['vnext'][0]['users'][0]['id']);
        $this->assertArrayNotHasKey('service_credential', $candidate);

        $copy = $this->postJson($this->path('saveOutbound'), [
            'copy_from_id' => $candidate['id'], 'name' => 'Copied exit',
        ])->assertOk()->json('data');
        $this->assertNotSame($candidate['id'], $copy['id']);
        $this->assertSame($candidate['source_node_id'], $copy['source_node_id']);

        $saved = $this->postJson($this->path('bindings'), [
            'node_id' => $target->id,
            'outbound_bindings' => [['outbound_id' => $copy['id'], 'tag' => 'remote-edge']],
            'expected_revision' => 0,
        ])->assertOk()->json('data');
        $this->assertSame('direct', $saved['effective_config']['outbounds'][0]['tag']);
        $this->getJson($this->path('bindings') . '?node_id=' . $target->id)
            ->assertOk()->assertJsonPath('data.default_outbound_tag', 'direct');
        $selected = $this->postJson($this->path('defaultOutbound'), [
            'node_id' => $target->id,
            'default_outbound_tag' => 'remote-edge',
            'expected_revision' => $saved['config_revision'],
        ])->assertOk()->json('data');
        $this->assertSame('remote-edge', $selected['default_outbound_tag']);
        $this->assertSame('remote-edge', $selected['effective_config']['outbounds'][0]['tag']);
        $this->getJson($this->path('snapshot') . '?id=' . $copy['id'])
            ->assertOk()->assertJsonPath('data.source_snapshot.source_node_id', $source->id);
    }

    public function test_hysteria_is_managed_but_unsupported_source_credentials_are_explicit(): void
    {
        $this->admin();
        $source = Server::withoutEvents(fn () => Server::create([
            'name' => 'Hysteria source', 'type' => 'hysteria', 'host' => 'hysteria.example.test',
            'port' => '443', 'server_port' => 443, 'rate' => 1,
            'protocol_settings' => ['version' => 2, 'tls' => ['server_name' => 'hysteria.example.test']],
        ]));
        $candidate = $this->postJson($this->path('saveOutbound'), [
            'name' => 'Hysteria exit', 'source_type' => 'server', 'source_node_id' => $source->id,
            'service_credential' => ['auth' => 'credential'],
        ]);
        $candidate->assertStatus(422)->assertJsonValidationErrors('xray_config');
    }

    public function test_allocate_and_non_empty_global_transport_are_rejected_without_saving(): void
    {
        $this->admin();
        $node = $this->node();

        foreach ([
            ['inbounds' => [(object) ['allocate' => (object) ['strategy' => 'always']]]],
            ['inbounds' => [(object) ['streamSettings' => (object) [], 'settings' => (object) []]], 'transport' => (object) ['tcpSettings' => (object) []]],
        ] as $config) {
            $this->postJson($this->path('save'), [
                'node_id' => $node->id,
                'xray_config' => $config,
            ])->assertStatus(422);
        }

        $this->assertNull($node->fresh()->xray_config);
    }

    public function test_managed_inbound_structural_nulls_are_rejected_but_decryption_tombstone_is_allowed(): void
    {
        $this->admin();
        $node = $this->node();

        foreach ([
            ['inbounds' => [(object) ['settings' => null]]],
            ['inbounds' => [(object) ['streamSettings' => null]]],
            ['inbounds' => [(object) ['sniffing' => null]]],
            ['inbounds' => [(object) ['settings' => (object) ['flow' => null]]]],
            ['inbounds' => [(object) ['streamSettings' => (object) ['tlsSettings' => null]]]],
        ] as $config) {
            $this->postJson($this->path('save'), [
                'node_id' => $node->id,
                'xray_config' => $config,
            ])->assertStatus(422)->assertJsonValidationErrors('xray_config');
            $this->assertNull($node->fresh()->xray_config);
        }

        $allowed = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => [
                'inbounds' => [[
                    'settings' => ['decryption' => null],
                ]],
            ],
        ])->assertOk()->json('data');
        $this->assertArrayNotHasKey('decryption', $allowed['effective_inbound']['settings']);
    }

    public function test_optional_managed_transport_leaf_null_is_accepted_and_clears_nested_value(): void
    {
        $this->admin();
        $node = $this->node();

        $saved = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => [
                'inbounds' => [[
                    'streamSettings' => [
                        'sockopt' => ['mark' => null],
                    ],
                ]],
            ],
        ])->assertOk()->json('data');

        $this->assertNull($saved['xray_config']['inbounds'][0]['streamSettings']['sockopt']['mark']);

        // Nested object tombstones remove an existing value during the
        // effective merge; they do not pin or preserve the old value.
        $merged = XrayConfigService::merge(
            (object) ['streamSettings' => (object) ['sockopt' => (object) ['mark' => 123]]],
            (object) ['streamSettings' => (object) ['sockopt' => (object) ['mark' => null]]],
        );
        $this->assertArrayNotHasKey('mark', get_object_vars($merged->streamSettings->sockopt));
    }

    public function test_vless_native_flow_is_projected_and_encryption_requires_public_client_value(): void
    {
        $this->admin();
        $node = $this->node();
        $decryption = 'server-secret-must-not-leak';

        $missingClient = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => json_decode(json_encode([
                'inbounds' => [(object) [
                    'settings' => (object) ['decryption' => $decryption, 'flow' => 'xtls-rprx-vision'],
                ]],
            ])),
        ]);
        $missingClient->assertStatus(422)
            ->assertJsonValidationErrors('xray_config')
            ->assertJsonMissing(['server-secret-must-not-leak']);
        $this->assertNull($node->fresh()->xray_config);

        $saved = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => json_decode(json_encode([
                'inbounds' => [(object) [
                    'settings' => (object) ['decryption' => $decryption, 'flow' => 'xtls-rprx-vision'],
                ]],
            ])),
            'client_settings' => [
                'flow' => 'xtls-rprx-vision',
                'encryption' => ['enabled' => true, 'encryption' => 'public-client-value'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame('xtls-rprx-vision', $saved['effective_inbound']['settings']['flow']);
        $this->assertSame($decryption, $saved['effective_inbound']['settings']['decryption']);
        $this->assertSame('xtls-rprx-vision', $saved['client_settings']['flow']);
        $this->assertTrue($saved['client_settings']['encryption']['enabled']);
        $this->assertSame('public-client-value', $saved['client_settings']['encryption']['encryption']);
        $this->assertArrayNotHasKey('decryption', $saved['client_settings']['encryption']);
        $published = XrayConfigService::projectedProtocolSettings($node->fresh());
        $this->assertSame('xtls-rprx-vision', $published['flow']);
        $this->assertTrue($published['encryption']['enabled']);
        $this->assertSame('public-client-value', $published['encryption']['encryption']);
        $this->assertArrayNotHasKey('decryption', $published['encryption']);

        // Native listener values are projections, not a rewrite of the
        // legacy baseline.  Removing the native override must therefore
        // restore the original plain VLESS listener instead of inheriting the
        // previous private decryption value.
        $legacyAfterNative = $node->fresh()->protocol_settings;
        $this->assertSame('', $legacyAfterNative['flow']);
        $this->assertNull(data_get($legacyAfterNative, 'encryption.decryption'));
        $restored = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'expected_revision' => $saved['config_revision'],
            'xray_config' => (object) [],
        ])->assertOk()->json('data');
        $this->assertSame('', $restored['effective_inbound']['settings']['flow']);
        $this->assertSame('none', $restored['effective_inbound']['settings']['decryption']);
        $this->assertFalse($restored['client_settings']['encryption']['enabled']);
        $this->assertNull($restored['client_settings']['encryption']['encryption']);
        $this->assertNull(data_get($node->fresh()->protocol_settings, 'encryption.decryption'));

        $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'expected_revision' => $restored['config_revision'],
            'xray_config' => json_decode(json_encode([
                'inbounds' => [(object) [
                    'settings' => (object) ['decryption' => 'another-private-value', 'flow' => 'xtls-rprx-vision'],
                ]],
            ])),
        ])->assertStatus(422)->assertJsonMissing(['another-private-value']);

        $updated = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'expected_revision' => $restored['config_revision'],
            'xray_config' => json_decode(json_encode([
                'inbounds' => [(object) [
                    'settings' => (object) ['decryption' => 'another-private-value', 'flow' => 'xtls-rprx-vision'],
                ]],
            ])),
            'client_settings' => [
                'flow' => 'xtls-rprx-vision',
                'encryption' => ['enabled' => true, 'encryption' => 'new-public-client-value'],
            ],
        ])->assertOk()->json('data');
        $this->assertSame('another-private-value', $updated['effective_inbound']['settings']['decryption']);
        $this->assertSame('new-public-client-value', $updated['client_settings']['encryption']['encryption']);

        $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => (object) [],
            'client_settings' => ['encryption' => ['decryption' => 'forbidden']],
        ])->assertStatus(422)->assertJsonMissing(['forbidden']);
    }

    public function test_legacy_vless_baseline_survives_native_sections_and_override_lifecycle(): void
    {
        $this->admin();
        $node = $this->node();
        $legacySettings = [
            'tls' => 0,
            'flow' => 'xtls-rprx-vision',
            'tls_settings' => [],
            'encryption' => [
                'enabled' => true,
                'encryption' => 'legacy-public-value',
                'decryption' => 'legacy-private-value',
            ],
        ];
        $node->forceFill([
            'protocol_settings' => $legacySettings,
        ])->saveQuietly();

        // Selecting native control for an unrelated top-level section must
        // retain the complete legacy listener baseline.
        $dnsOnly = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => json_decode('{"dns":{"servers":["1.1.1.1"]}}'),
        ])->assertOk()->json('data');
        $this->assertSame('xtls-rprx-vision', $dnsOnly['effective_inbound']['settings']['flow']);
        $this->assertSame('legacy-private-value', $dnsOnly['effective_inbound']['settings']['decryption']);
        $this->assertTrue($dnsOnly['client_settings']['encryption']['enabled']);
        $this->assertSame('legacy-public-value', $dnsOnly['client_settings']['encryption']['encryption']);
        $legacyAfterDns = $node->fresh()->protocol_settings;
        $this->assertSame('xtls-rprx-vision', $legacyAfterDns['flow']);
        $this->assertSame('legacy-public-value', $legacyAfterDns['encryption']['encryption']);
        $this->assertSame('legacy-private-value', $legacyAfterDns['encryption']['decryption']);

        // A native listener override may use a different complete pair, but
        // the public half must be submitted atomically with its new private
        // counterpart.
        $overridden = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'expected_revision' => $dnsOnly['config_revision'],
            'xray_config' => json_decode(json_encode([
                'inbounds' => [(object) [
                    'settings' => (object) [
                        'flow' => 'native-vision',
                        'decryption' => 'native-private-value',
                    ],
                ]],
            ])),
            'client_settings' => [
                'flow' => 'native-vision',
                'encryption' => [
                    'enabled' => true,
                    'encryption' => 'native-public-value',
                ],
            ],
        ])->assertOk()->json('data');
        $this->assertSame('native-vision', $overridden['effective_inbound']['settings']['flow']);
        $this->assertSame('native-private-value', $overridden['effective_inbound']['settings']['decryption']);
        $this->assertSame('native-public-value', $overridden['client_settings']['encryption']['encryption']);
        $legacyAfterOverride = $node->fresh()->protocol_settings;
        $this->assertSame('xtls-rprx-vision', $legacyAfterOverride['flow']);
        $this->assertSame('legacy-public-value', $legacyAfterOverride['encryption']['encryption']);
        $this->assertSame('legacy-private-value', $legacyAfterOverride['encryption']['decryption']);

        // Removing the native listener override restores both halves of the
        // original legacy pair rather than retaining the native public value.
        $restored = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'expected_revision' => $overridden['config_revision'],
            'xray_config' => (object) [],
        ])->assertOk()->json('data');
        $this->assertSame('xtls-rprx-vision', $restored['effective_inbound']['settings']['flow']);
        $this->assertSame('legacy-private-value', $restored['effective_inbound']['settings']['decryption']);
        $this->assertTrue($restored['client_settings']['encryption']['enabled']);
        $this->assertSame('legacy-public-value', $restored['client_settings']['encryption']['encryption']);
        $legacyAfterRestore = $node->fresh()->protocol_settings;
        $this->assertSame('xtls-rprx-vision', $legacyAfterRestore['flow']);
        $this->assertSame('legacy-public-value', $legacyAfterRestore['encryption']['encryption']);
        $this->assertSame('legacy-private-value', $legacyAfterRestore['encryption']['decryption']);

        // A native null is an explicit disable tombstone.  Removing that
        // tombstone later still restores the original legacy pair.
        $disabled = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'expected_revision' => $restored['config_revision'],
            'xray_config' => json_decode(json_encode([
                'inbounds' => [(object) [
                    'settings' => (object) [
                        'flow' => 'xtls-rprx-vision',
                        'decryption' => null,
                    ],
                ]],
            ])),
        ])->assertOk()->json('data');
        $this->assertArrayNotHasKey('decryption', $disabled['effective_inbound']['settings']);
        $this->assertFalse($disabled['client_settings']['encryption']['enabled']);

        $restoredAgain = $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'expected_revision' => $disabled['config_revision'],
            'xray_config' => (object) [],
        ])->assertOk()->json('data');
        $this->assertSame('legacy-private-value', $restoredAgain['effective_inbound']['settings']['decryption']);
        $this->assertSame('legacy-public-value', $restoredAgain['client_settings']['encryption']['encryption']);
    }

    public function test_vless_generated_encryption_pair_must_match_when_profile_is_recognized(): void
    {
        $this->admin();
        $node = $this->node();
        $first = $this->postJson($this->path('generateVlessEncryption'))->assertOk()->json('data');
        $second = $this->postJson($this->path('generateVlessEncryption'))->assertOk()->json('data');
        $config = json_decode(json_encode([
            'inbounds' => [['settings' => [
                'decryption' => $first['decryption'], 'flow' => '',
            ]]],
        ]));

        $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => $config,
            'client_settings' => [
                'encryption' => ['enabled' => true, 'encryption' => $second['encryption']],
            ],
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['client_settings.encryption.encryption']]);
        $this->assertNull($node->fresh()->xray_config);

        $this->postJson($this->path('save'), [
            'node_id' => $node->id,
            'xray_config' => $config,
            'client_settings' => [
                'encryption' => ['enabled' => true, 'encryption' => $first['encryption']],
            ],
        ])->assertOk();
    }

    public function test_manual_protocol_change_cannot_reuse_masked_credentials(): void
    {
        $this->admin();
        $candidate = $this->postJson($this->path('saveOutbound'), [
            'name' => 'Manual VLESS',
            'config' => json_decode(json_encode([
                'tag' => 'manual-vless', 'protocol' => 'vless',
                'settings' => ['vnext' => [['address' => 'edge.example', 'port' => 443, 'users' => [['id' => 'real-vless-id', 'encryption' => 'none']]]]],
            ])),
        ])->assertOk()->json('data');

        $changed = $this->postJson($this->path('saveOutbound'), [
            'id' => $candidate['id'],
            'name' => 'Manual VMess',
            'config' => json_decode(json_encode([
                'tag' => 'manual-vmess', 'protocol' => 'vmess',
                'settings' => ['vnext' => [['address' => 'edge.example', 'port' => 443, 'users' => [['id' => '***', 'security' => 'auto']]]]],
            ])),
        ]);
        $changed->assertStatus(422)->assertJsonValidationErrors('xray_config');
        $this->assertSame('vless', $candidateModel = Outbound::findOrFail($candidate['id'])->config->protocol);

        $this->postJson($this->path('saveOutbound'), [
            'id' => $candidate['id'],
            'name' => 'Manual VMess',
            'config' => json_decode(json_encode([
                'tag' => 'manual-vmess', 'protocol' => 'vmess',
                'settings' => ['vnext' => [['address' => 'edge.example', 'port' => 443, 'users' => [['id' => 'new-vmess-id', 'security' => 'auto']]]]],
            ])),
        ])->assertOk()->assertJsonPath('data.config.settings.vnext.0.users.0.id', '***');
        $this->assertSame('vmess', Outbound::findOrFail($candidate['id'])->config->protocol);
    }

    public function test_source_config_patch_keeps_sparse_extras_without_freezing_live_endpoint(): void
    {
        $this->admin();
        $source = $this->node();
        $target = $this->node();
        $credential = ['uuid' => (string) Str::uuid()];
        $candidate = $this->postJson($this->path('saveOutbound'), [
            'name' => 'Live patched source',
            'source_type' => 'server', 'source_node_id' => $source->id,
            'resolution_mode' => 'live', 'service_credential' => $credential,
            'config_patch' => ['streamSettings' => ['network' => 'ws', 'wsSettings' => ['path' => '/kept']]],
        ])->assertOk()->json('data');

        $this->assertSame('/kept', $candidate['config_override']['streamSettings']['wsSettings']['path']);
        $this->assertArrayNotHasKey('settings', $candidate['config_override']);
        $this->postJson($this->path('saveOutbound'), [
            'id' => $candidate['id'], 'name' => 'Live patched source',
            'source_type' => 'server', 'source_node_id' => $source->id,
            'resolution_mode' => 'live',
            'config_patch' => ['mux' => ['enabled' => true]],
        ])->assertOk()->assertJsonPath('data.config_override.streamSettings.wsSettings.path', '/kept')
            ->assertJsonPath('data.config_override.mux.enabled', true);

        $this->postJson($this->path('bindings'), [
            'node_id' => $target->id, 'outbound_bindings' => [['outbound_id' => $candidate['id']]],
        ])->assertOk();
        $source->update(['host' => 'source-live.example.test', 'port' => 18081, 'server_port' => 18081]);
        $effective = XrayConfigService::effective($target->fresh());
        $this->assertSame('source-live.example.test', $effective->outbounds[1]->settings->vnext[0]->address);
        $this->assertSame('/kept', $effective->outbounds[1]->streamSettings->wsSettings->path);
        $this->assertTrue($effective->outbounds[1]->mux->enabled);

        $this->postJson($this->path('saveOutbound'), [
            'id' => $candidate['id'], 'name' => 'Live patched source',
            'source_type' => 'server', 'source_node_id' => $source->id,
            'resolution_mode' => 'live',
            'config_patch' => ['streamSettings' => null],
        ])->assertOk()->assertJsonMissingPath('data.config_override.streamSettings');
    }

    public function test_machine_active_transition_publishes_empty_and_restored_node_sets(): void
    {
        $this->admin();
        $machine = ServerMachine::create(['name' => 'toggle machine', 'token' => 'toggle-token', 'is_active' => true]);
        $node = $this->node();
        Server::withoutEvents(fn () => $node->update(['machine_id' => $machine->id]));

        $published = [];
        Redis::shouldReceive('publish')->twice()->with('node:push', \Mockery::on(function (string $message) use (&$published): bool {
            $published[] = json_decode($message, true);
            return true;
        }));
        $this->postJson($this->machinePath(), [
            'id' => $machine->id, 'name' => $machine->name, 'is_active' => false,
        ])->assertOk();
        $this->postJson($this->machinePath(), [
            'id' => $machine->id, 'name' => $machine->name, 'is_active' => true,
        ])->assertOk();

        $this->assertSame([], $published[0]['data']['nodes']);
        $this->assertSame($node->id, $published[1]['data']['nodes'][0]['id']);
    }

    public function test_available_servers_match_numeric_and_string_group_ids(): void
    {
        $user = User::create([
            'email' => Str::uuid() . '@example.com', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'group_id' => 1,
        ]);
        $numeric = Server::withoutEvents(fn () => Server::create([
            'name' => 'Numeric group', 'type' => 'vless', 'host' => 'numeric.example',
            'port' => 443, 'server_port' => 443, 'group_ids' => [1], 'show' => true, 'rate' => 1,
            'protocol_settings' => ['tls' => 0, 'flow' => '', 'tls_settings' => []],
        ]));
        $string = Server::withoutEvents(fn () => Server::create([
            'name' => 'String group', 'type' => 'vless', 'host' => 'string.example',
            'port' => 444, 'server_port' => 444, 'group_ids' => ['1'], 'show' => true, 'rate' => 1,
            'protocol_settings' => ['tls' => 0, 'flow' => '', 'tls_settings' => []],
        ]));
        // Preserve one legacy row exactly as it existed before group_ids
        // writes were canonicalized to JSON strings.
        DB::table('v2_server')->where('id', $numeric->id)->update([
            'group_ids' => json_encode([1]),
        ]);

        $available = collect(ServerService::getAvailableServers($user));
        $this->assertEqualsCanonicalizing([$numeric->id, $string->id], $available->pluck('id')->all());
        $this->assertSame([1], $numeric->fresh()->group_ids);
        $this->assertSame(['1'], $string->fresh()->group_ids);
    }

    public function test_xray_validate_reports_actionable_paths_without_persisting(): void
    {
        $this->admin();
        $node = $this->node();

        $missingListen = $this->postJson($this->path('preflight'), [
            'node_id' => $node->id,
            'xray_config' => json_decode(json_encode([
                'inbounds' => [
                    ['streamSettings' => (object) []],
                    ['protocol' => 'dokodemo-door', 'tag' => 'local-tunnel', 'port' => 30081],
                ],
            ])),
        ]);
        $missingListen->assertStatus(422)
            ->assertJsonStructure(['errors' => ['xray_config.inbounds.1.listen']]);
        $this->assertNull($node->fresh()->xray_config);
        $this->assertSame(0, (int) ($node->fresh()->config_revision ?? 0));

        $missingPort = $this->postJson($this->path('preflight'), [
            'node_id' => $node->id,
            'xray_config' => json_decode(json_encode([
                'inbounds' => [
                    ['streamSettings' => (object) []],
                    ['protocol' => 'dokodemo-door', 'tag' => 'local-tunnel', 'listen' => '127.0.0.1'],
                ],
            ])),
        ]);
        $missingPort->assertStatus(422)
            ->assertJsonStructure(['errors' => ['xray_config.inbounds.1.port']]);
        $this->assertNull($node->fresh()->xray_config);
    }

    public function test_xray_validate_accepts_partial_managed_override_after_merge(): void
    {
        $this->admin();
        $node = $this->node();
        $this->postJson($this->path('preflight'), [
            'node_id' => $node->id,
            'xray_config' => json_decode('{"dns":{"servers":["1.1.1.1"]}}'),
        ])->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.config_revision', 0);
        $this->assertNull($node->fresh()->xray_config);
    }

    public function test_xray_validate_rejects_hysteria_tls_without_certificate_and_save_is_atomic(): void
    {
        $this->admin();
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'Hysteria validation', 'type' => 'hysteria', 'host' => 'hy.example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
            'protocol_settings' => ['version' => 2, 'tls' => null, 'bandwidth' => null, 'obfs' => null],
        ]));
        $config = json_decode(json_encode([
            'inbounds' => [['streamSettings' => ['security' => 'tls', 'tlsSettings' => []]]],
        ]));

        $this->postJson($this->path('preflight'), [
            'node_id' => $node->id, 'xray_config' => $config,
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cert_config.cert_mode']]);
        $this->assertNull($node->fresh()->xray_config);

        $this->postJson($this->path('save'), [
            'node_id' => $node->id, 'xray_config' => $config,
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cert_config.cert_mode']]);
        $fresh = $node->fresh();
        $this->assertNull($fresh->xray_config);
        $this->assertSame(0, (int) ($fresh->config_revision ?? 0));
    }

    public function test_certificate_preflight_requires_complete_file_and_content_pairs(): void
    {
        $this->admin();
        $node = $this->node();

        $this->postJson($this->path('preflight'), [
            'node_id' => $node->id,
            'cert_config' => ['cert_mode' => 'file', 'cert_file' => '/etc/xray/cert.pem'],
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cert_config.key_file']]);

        $this->postJson($this->path('preflight'), [
            'node_id' => $node->id,
            'cert_config' => [
                'cert_mode' => 'content',
                'cert_content' => "-----BEGIN CERTIFICATE-----\ncert\n-----END CERTIFICATE-----",
            ],
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cert_config.key_content']]);
        $this->assertNull($node->fresh()->cert_config);
    }

    public function test_hysteria_node_config_handles_legacy_null_optional_objects(): void
    {
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'Hysteria null config', 'type' => 'hysteria', 'host' => 'hy.example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
            'protocol_settings' => ['version' => 2, 'tls' => null, 'bandwidth' => null, 'obfs' => null],
        ]));

        $config = ServerService::buildNodeConfig($node);
        $this->assertSame('hysteria', $config['protocol']);
        $this->assertSame(2, $config['version']);
        $this->assertNull($config['server_name']);
        $this->assertNull($config['tls_settings']);
        $this->assertSame(0, $config['up_mbps']);
        $this->assertSame(0, $config['down_mbps']);
        $this->assertNull($config['obfs']);
    }

    public function test_machine_and_outbound_preflight_are_read_only(): void
    {
        $this->admin();
        $machine = ServerMachine::create([
            'name' => 'preflight machine', 'token' => 'preflight-token', 'is_active' => true,
        ]);
        $node = $this->node();
        Server::withoutEvents(fn () => $node->update(['machine_id' => $machine->id]));

        $this->postJson($this->path('preflight'), [
            'machine_id' => $machine->id,
            'xray_config' => json_decode('{"dns":{"servers":["9.9.9.9"]}}'),
        ])->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.affected_nodes.0.node_id', $node->id);
        $this->assertNull($machine->fresh()->xray_config);
        $this->assertSame(0, (int) ($node->fresh()->config_revision ?? 0));

        $manual = json_decode(json_encode([
            'tag' => 'preflight-exit', 'protocol' => 'vless',
            'settings' => ['vnext' => [[
                'address' => 'edge.example.test', 'port' => 443,
                'users' => [['id' => (string) Str::uuid(), 'encryption' => 'none']],
            ]]],
        ]));
        $this->postJson($this->path('validateOutbound'), [
            'name' => 'preflight outbound', 'config' => $manual,
        ])->assertOk()->assertJsonPath('data.valid', true);
        $this->assertSame(0, Outbound::query()->count());

        $this->postJson($this->path('validateOutbound'), [
            'name' => 'invalid outbound',
            'config' => json_decode('{"tag":"invalid","protocol":"not-supported","settings":{}}'),
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['config.protocol']]);
        $this->assertSame(0, Outbound::query()->count());

        $candidate = $this->postJson($this->path('saveOutbound'), [
            'name' => 'preflight outbound', 'config' => $manual,
        ])->assertOk()->json('data');
        $this->postJson($this->path('bindings'), [
            'node_id' => $node->id,
            'outbound_bindings' => [['outbound_id' => $candidate['id']]],
        ])->assertOk();
        $revision = (int) $node->fresh()->config_revision;
        $this->postJson($this->path('validateOutbound'), [
            'id' => $candidate['id'], 'name' => 'preflight outbound', 'config' => $manual,
        ])->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.bound_nodes.0.node_id', $node->id);
        $this->assertSame($revision, (int) $node->fresh()->config_revision);
    }

    public function test_legacy_server_save_validates_certificate_inputs_before_insert(): void
    {
        $this->admin();
        $payload = [
            'type' => 'hysteria', 'name' => 'invalid legacy hysteria',
            'host' => 'hy.example.test', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'protocol_settings' => [
                'version' => 2, 'tls' => ['server_name' => 'hy.example.test'],
            ],
            'cert_config' => ['cert_mode' => 'file', 'cert_file' => '/etc/xray/cert.pem'],
        ];
        $this->postJson($this->manageSavePath(), $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cert_config.key_file']]);
        $this->assertSame(0, Server::query()->where('name', 'invalid legacy hysteria')->count());
    }

    public function test_enabling_an_invalid_runtime_is_rejected_without_changing_state(): void
    {
        $this->admin();
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'Disabled invalid Hysteria', 'type' => 'hysteria',
            'host' => 'hy.example.test', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'enabled' => false,
            'protocol_settings' => ['version' => 2, 'tls' => null],
        ]));

        $this->postJson($this->manageUpdatePath(), [
            'id' => $node->id, 'enabled' => true,
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cert_config.cert_mode']]);

        $this->assertFalse((bool) $node->fresh()->enabled);
        $this->assertSame(0, (int) ($node->fresh()->config_revision ?? 0));
    }

    private function machinePath(): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === \App\Http\Controllers\V2\Admin\Server\MachineController::class . '@save') {
                return '/' . $route->uri();
            }
        }
        $this->fail('Route missing: machine save');
    }

    private function manageSavePath(): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === \App\Http\Controllers\V2\Admin\Server\ManageController::class . '@save') {
                return '/' . $route->uri();
            }
        }
        $this->fail('Route missing: server save');
    }

    private function manageUpdatePath(): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === \App\Http\Controllers\V2\Admin\Server\ManageController::class . '@update') {
                return '/' . $route->uri();
            }
        }
        $this->fail('Route missing: server update');
    }
}
