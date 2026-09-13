<?php

namespace Tests\Feature\Usage;

use App\Http\Controllers\V1\User\UsageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Usage\UsageAccessService;
use App\Services\Usage\UsageIngestService;
use App\Services\Usage\UsageQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['usage.enabled' => true, 'cache.default' => 'array']);
        app(\App\Support\Setting::class)->save([]);
    }

    private function user(array $extra = []): User
    {
        return User::create($extra + [
            'email' => Str::random(12) . '@example.com', 'password' => password_hash('test-only-password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'group_id' => 1,
            'transfer_enable' => 107374182400, 'u' => 0, 'd' => 0,
        ]);
    }

    private function node(): Server
    {
        $machine = ServerMachine::create(['name' => 'usage-test', 'token' => Str::random(32), 'is_active' => true]);
        return Server::create(['name' => 'node-test', 'type' => 'vless', 'host' => 'example.com',
            'port' => 443, 'server_port' => 443, 'group_ids' => [1], 'rate' => 2,
            'enabled' => true, 'show' => true, 'machine_id' => $machine->id]);
    }

    private function report(int $uid, int $sequence, int $up, int $down): array
    {
        return ['epoch' => '1234567890abcdef', 'sequence' => $sequence, 'sampled_at' => time(),
            'core' => 'xray', 'counters' => [['user_id' => $uid, 'up' => $up, 'down' => $down]],
            'devices' => [['user_id' => $uid, 'ip' => '192.0.2.10']]];
    }

    public function test_security_review_is_persistent_and_cannot_review_another_users_signal(): void
    {
        $user = $this->user(); $other = $this->user(); $node = $this->node();
        app(UsageIngestService::class)->ingest('node', $node->id, $this->report($other->id, 1, 0, 0), $node);
        $foreignSignal = 'connection:' . DB::table('v2_usage_source')->value('id');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/usage/review', ['signal' => $foreignSignal])->assertNotFound();
        $this->postJson('/api/v1/user/usage/review', ['signal' => 'arbitrary-signal'])->assertStatus(422);
        app(UsageAccessService::class)->record(\Illuminate\Http\Request::create('/login', 'POST'), $user->id, 'panel', '登录', '失败');
        $signal = 'panel:' . DB::table('v2_usage_event')->value('id');
        $this->postJson('/api/v1/user/usage/review', ['signal' => $signal])->assertOk();
        $this->getJson('/api/v1/user/usage/snapshot')->assertOk()->assertJsonPath('data.reviewed.0', $signal)->assertJsonPath('data.security.failed', 1);
    }

    public function test_combined_online_history_deduplicates_shared_ip_across_nodes(): void
    {
        $user = $this->user(); $first = $this->node(); $second = $this->node();
        $second->update(['machine_id' => $first->machine_id]);
        $service = app(UsageIngestService::class);
        $service->ingest('node', $first->id, $this->report($user->id, 1, 0, 0), $first);
        $service->ingest('node', $second->id, $this->report($user->id, 1, 0, 0), $second);
        $this->artisan('usage:maintain')->assertSuccessful();
        $history = DB::table('v2_usage_online_scope_history')->where(['user_id' => $user->id, 'machine_id' => $first->machine_id, 'node_id' => 0])->first();
        $this->assertSame(1, (int) $history->devices);
        $snapshot = app(UsageQueryService::class)->snapshot(['user_id' => $user->id, 'node_id' => $first->id], time() - 3600, time(), true, $user->id);
        $this->assertSame(1, (int) $snapshot['onlineHistory'][0]->devices);
        $empty = $this->report($user->id, 2, 0, 0); $empty['devices'] = [];
        $service->ingest('node', $first->id, $empty, $first);
        \Illuminate\Support\Facades\Cache::forget('usage:online-counts');
        $counts = app(UsageQueryService::class)->onlineCounts();
        $this->assertSame(0, $counts['nodes'][$first->id]['devices']);
    }

    public function test_mcp_catalog_exposes_usage_queries_and_audited_settings_mutation(): void
    {
        $operations = collect(app(\App\Services\AdminOperationCatalog::class)->operations())->keyBy('path');
        $this->assertTrue($operations['usage/ip']['read_only']);
        $this->assertTrue($operations['usage/settings']['read_only']);
        $this->assertFalse($operations['usage/settings/save']['read_only']);
        $this->assertSame('infrastructure', $operations['usage/settings/save']['domain']);
    }

    public function test_mcp_usage_permissions_and_settings_persistence(): void
    {
        $admin = $this->user(['is_admin' => true]);
        $keys = app(\App\Services\McpKeyService::class);
        $create = fn($scope) => $keys->create($admin, ['name' => 'Usage test', 'scope' => $scope,
            'domains' => ['infrastructure'], 'client' => 'generic', 'expires_in_days' => 30])['secret'];
        $call = fn($secret, $tool, $args) => $this->withHeaders(['Authorization' => 'Bearer ' . $secret])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => (string) Str::uuid(), 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $args]]);
        $read = $create('read');
        $call($read, 'xboard_admin_read', ['operation' => 'usage.ip.get', 'parameters' => []])
            ->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.status', 200);
        $args = ['operation' => 'usage.settings.save.post', 'expected_change_version' => 0,
            'parameters' => ['enabled' => true, 'history_days' => 120, 'access_days' => 30, 'identity_days' => 180]];
        $call($read, 'xboard_admin_mutate', $args)->assertOk()->assertJsonPath('result.isError', true);
        $call($create('full'), 'xboard_admin_mutate', $args)->assertOk()
            ->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.change_version', 1);
        $this->assertEquals(120, \App\Services\Usage\UsageSettings::get('history_days'));
        $this->assertDatabaseHas('v2_change_event', ['actor_type' => 'mcp', 'action' => 'usage.settings.save.post']);
    }

    public function test_missing_node_reports_do_not_create_zero_online_history(): void
    {
        $this->node();
        \Illuminate\Support\Facades\Cache::forget('usage:online-counts');
        $this->artisan('usage:maintain')->assertSuccessful();
        $this->assertSame(0, DB::table('v2_usage_online_history')->count());
    }

    public function test_large_ip_history_is_aggregated_and_response_is_bounded(): void
    {
        $user = $this->user(); $node = $this->node();
        $bucket = intdiv(time(), 3600) * 3600;
        $batch = [];
        for ($i = 0; $i < 12000; $i++) {
            $source = $i % 3000;
            $batch[] = ['user_id' => $user->id, 'node_id' => $node->id, 'machine_id' => $node->machine_id,
                'ip' => '2001:db8::' . dechex($source + 1), 'family' => 6, 'bucket' => $bucket - intdiv($i, 3000) * 3600,
                'up' => 10, 'down' => 20, 'measured' => 1, 'missing' => 0, 'first_seen' => $bucket, 'last_seen' => $bucket];
            if (count($batch) === 50) { DB::table('v2_usage_ip_traffic')->insert($batch); $batch = []; }
        }
        Sanctum::actingAs($user);
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/user/usage/ip?per_page=10')->assertOk()
            ->assertJsonPath('data.total_rows', 3000)->assertJsonCount(10, 'data.rows')->assertJsonCount(6, 'data.sources');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog(); DB::flushQueryLog();
        $this->assertLessThan(40, $queries, 'Query count must not grow per source');
        $this->assertLessThan(16000, strlen($response->getContent()), 'Historical raw rows must not be returned');
        $this->assertEqualsWithDelta(360000 / 1073741824, $response->json('data.summary.total'), 1e-12);
    }

    public function test_ingestion_is_idempotent_and_does_not_change_billing(): void
    {
        $user = $this->user();
        $node = $this->node();
        $service = app(UsageIngestService::class);
        $service->ingest('node', $node->id, $this->report($user->id, 1, 100, 200), $node);
        $second = $this->report($user->id, 2, 150, 280);
        $this->assertTrue($service->ingest('node', $node->id, $second, $node));
        $this->assertFalse($service->ingest('node', $node->id, $second, $node));
        $row = DB::table('v2_usage_traffic')->where('layer', 'proxy')->first();
        $this->assertSame(50, (int) $row->up);
        $this->assertSame(80, (int) $row->down);
        $this->assertSame(100, (int) $row->billed_up);
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(1, DB::table('v2_usage_source')->count());
        $this->assertSame(1, DB::table('v2_usage_identity')->count());
    }

    public function test_ip_cumulative_ledger_survives_reporter_epochs_and_keeps_users_separate(): void
    {
        $user = $this->user(); $other = $this->user(); $node = $this->node();
        $service = app(UsageIngestService::class);
        $r = $this->report($user->id, 1, 0, 0);
        $r['sampled_at'] = time() - 10;
        $r['devices'][0] += ['generation' => str_repeat('a', 32), 'up' => 100, 'down' => 300, 'online' => false];
        $service->ingest('node', $node->id, $r, $node);
        $this->assertFalse($service->ingest('node', $node->id, $r, $node));
        $r['epoch'] = 'another-epoch-12345'; $r['sampled_at']++;
        $r['devices'][0]['up'] = 150;
        $service->ingest('node', $node->id, $r, $node);
        $r['sequence']++; $r['devices'][0]['generation'] = str_repeat('b', 32);
        $r['devices'][0]['up'] = 20; $r['devices'][0]['down'] = 30;
        $r['devices'][] = ['user_id' => $other->id, 'ip' => '192.0.2.10', 'online' => false,
            'generation' => str_repeat('c', 32), 'up' => 40, 'down' => 50];
        $service->ingest('node', $node->id, $r, $node);
        $this->assertSame(170, (int) DB::table('v2_usage_ip_traffic')->where('user_id', $user->id)->sum('up'));
        $this->assertSame(330, (int) DB::table('v2_usage_ip_traffic')->where('user_id', $user->id)->sum('down'));
        $this->assertSame(40, (int) DB::table('v2_usage_ip_traffic')->where('user_id', $other->id)->sum('up'));
        $this->assertSame(0, DB::table('v2_usage_online')->count());
        $this->assertSame(0, (int) $user->fresh()->u);
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/user/usage/ip?user_id=' . $other->id)->assertOk();
        $response->assertJsonPath('data.total_rows', 1)->assertJsonPath('data.rows.0.userId', (string) $user->id);
        $this->assertEqualsWithDelta(500 / 1073741824, $response->json('data.summary.total'), 1e-12);
        $this->getJson('/api/v1/user/usage/ip?detail=1&ip=192.0.2.10&user_id=' . $other->id)
            ->assertOk()->assertJsonPath('data.rows.0.userId', (string) $user->id);
    }

    public function test_ip_query_filters_coverage_before_totals_and_paginates_in_sql(): void
    {
        $user = $this->user(); $node = $this->node(); $second = $this->node();
        $at = time(); $bucket = intdiv($at, 3600) * 3600;
        foreach ([$node, $second] as $i => $n) DB::table('v2_usage_ip_traffic')->insert([
            'user_id' => $user->id, 'ip' => '192.0.2.10', 'family' => 4, 'node_id' => $n->id, 'machine_id' => $n->machine_id,
            'bucket' => $bucket, 'up' => 1073741824 * ($i + 1), 'down' => 0, 'measured' => 1, 'missing' => $i,
            'first_seen' => $at - 10, 'last_seen' => $at,
        ]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/usage/ip?per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.total_rows', 2)->assertJsonPath('data.page', 2)->assertJsonPath('data.summary.total', 3);
        $this->getJson('/api/v1/user/usage/ip?coverage=complete')->assertOk()
            ->assertJsonPath('data.total_rows', 1)->assertJsonPath('data.summary.total', 1);
        $this->getJson('/api/v1/user/usage/ip?grouping=source')->assertOk()
            ->assertJsonPath('data.total_rows', 1)->assertJsonCount(2, 'data.rows.0.nodeIds')->assertJsonPath('data.summary.sources', 1);
        $this->getJson('/api/v1/user/usage/ip?grouping=source&coverage=complete')->assertOk()->assertJsonPath('data.total_rows', 0);
        $this->getJson('/api/v1/user/usage/ip?family=v6')->assertOk()->assertJsonPath('data.total_rows', 0);
        $this->getJson('/api/v1/user/usage/ip?search=%25')->assertOk()->assertJsonPath('data.total_rows', 0);
        $this->getJson('/api/v1/user/usage/ip?sort=drop')->assertStatus(422);
        $this->getJson('/api/v1/user/usage/ip?per_page=201')->assertStatus(422);
    }

    public function test_ip_counter_regression_and_mapped_duplicates_roll_back_whole_report(): void
    {
        $user = $this->user(); $node = $this->node();
        $auth = ['machine_id' => $node->machine_id, 'token' => ServerMachine::find($node->machine_id)->token, 'node_id' => $node->id];
        $r = $this->report($user->id, 1, 0, 0);
        $r['devices'][0] += ['generation' => str_repeat('a', 32), 'up' => 100, 'down' => 300];
        $this->postJson('/api/v2/server/usage', $auth + $r)->assertOk();
        $r['sequence']++; $r['devices'][0]['up'] = 1;
        $this->postJson('/api/v2/server/usage', $auth + $r)->assertStatus(422);
        $this->assertSame(1, (int) DB::table('v2_usage_stream')->value('sequence'));
        $r['devices'][0]['up'] = 110;
        $r['devices'][] = array_replace($r['devices'][0], ['ip' => '::ffff:192.0.2.10']);
        $this->postJson('/api/v2/server/usage', $auth + $r)->assertStatus(422);
        $this->assertSame(100, (int) DB::table('v2_usage_ip_traffic')->sum('up'));
    }

    public function test_empty_snapshot_clears_online_but_preserves_historical_ip(): void
    {
        $user = $this->user(); $node = $this->node(); $service = app(UsageIngestService::class);
        $r = $this->report($user->id, 1, 0, 0);
        $service->ingest('node', $node->id, $r, $node);
        $r['sequence'] = 2; $r['devices'] = [];
        $service->ingest('node', $node->id, $r, $node);
        $this->assertSame(0, DB::table('v2_usage_online')->count());
        $this->assertSame(1, DB::table('v2_usage_identity')->count());
    }

    public function test_short_connection_is_retained_without_reviving_online_or_repeating_first_visit(): void
    {
        $user = $this->user(); $node = $this->node(); $service = app(UsageIngestService::class);
        $report = $this->report($user->id, 1, 0, 0);
        $first = time() - 60; $last = time() - 30;
        $report['devices'][0] += ['online' => false, 'first_seen' => $first, 'last_seen' => $last];
        $service->ingest('node', $node->id, $report, $node);
        $report['sequence'] = 2;
        $report['devices'][0]['ip'] = '::ffff:192.0.2.10';
        $service->ingest('node', $node->id, $report, $node);
        $this->assertSame(0, DB::table('v2_usage_online')->count());
        $this->assertSame(1, DB::table('v2_usage_source')->count());
        $source = DB::table('v2_usage_source')->first();
        $this->assertSame($first, (int) $source->first_seen);
        $this->assertSame($last, (int) $source->last_seen);
        $this->assertSame('192.0.2.10', $source->ip);
    }

    public function test_node_token_is_scoped_and_malformed_report_is_rejected(): void
    {
        $user = $this->user(); $node = $this->node();
        $auth = ['machine_id' => $node->machine_id, 'token' => $node->machine->token ?? ServerMachine::find($node->machine_id)->token, 'node_id' => $node->id];
        $report = $this->report($user->id, 1, 0, 0);
        $this->postJson('/api/v2/server/usage', $auth + $report)->assertOk();
        $report['sequence'] = 2; $report['counters'][0]['up'] = -1;
        $this->postJson('/api/v2/server/usage', $auth + $report)->assertStatus(422);
        $auth['node_id'] = 999999;
        $this->postJson('/api/v2/server/usage', $auth + $report)->assertStatus(400);
    }

    public function test_ingestion_limits_and_machine_token_isolation(): void
    {
        $user = $this->user(); $node = $this->node(); $other = $this->node();
        $auth = ['machine_id' => $node->machine_id, 'token' => ServerMachine::find($node->machine_id)->token, 'node_id' => $node->id];
        $report = $this->report($user->id, 1, 0, 0);
        $this->postJson('/api/v2/server/usage', array_replace($auth, ['node_id' => $other->id]) + $report)->assertStatus(400);
        $this->postJson('/api/v2/server/usage', $auth + $report + ['padding' => str_repeat('x', 1048576)])->assertStatus(413);
        $key = 'usage:ingest:node:' . $node->id;
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        for ($i = 0; $i < 12; $i++) \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        $this->postJson('/api/v2/server/usage', $auth + $report)->assertStatus(429);
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        config(['usage.enabled' => false]);
        $this->postJson('/api/v2/server/usage', $auth + $report)->assertStatus(503);
        $this->assertSame(0, DB::table('v2_usage_head')->count());
    }

    public function test_user_cannot_query_other_users_or_admin_resources(): void
    {
        $owner = $this->user(); $other = $this->user(); $node = $this->node();
        app(UsageIngestService::class)->ingest('node', $node->id, $this->report($other->id, 1, 0, 0), $node);
        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/v1/user/usage/snapshot?user_id=' . $other->id)->assertOk();
        $this->assertSame([], $response->json('data.devices'));
        $this->assertStringNotContainsString($other->email, $response->getContent());
        $this->getJson('/api/v1/user/usage/snapshot?from=0')->assertStatus(422);
        $this->getJson('/api/v1/user/usage/events?per_page=10000')->assertStatus(422);
        $adminRoute = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn($route) => $route->getActionName() === \App\Http\Controllers\V2\Admin\UsageController::class . '@online');
        $this->assertNotNull($adminRoute);
        $this->getJson('/' . $adminRoute->uri())->assertStatus(403);
    }

    public function test_rankings_mask_only_user_email_and_count_retained_ips(): void
    {
        $user = $this->user(['email' => 'ranking.person@example.com']); $node = $this->node();
        app(UsageIngestService::class)->ingest('node', $node->id, $this->report($user->id, 1, 0, 0), $node);
        $service = app(UsageQueryService::class);
        $public = $service->leaderboard('devices', time() - 86400, time(), false, false, $user->id, '', 10);
        $admin = $service->leaderboard('devices', time() - 86400, time(), false, true, $user->id, '', 10);
        $this->assertStringContainsString('***', $public['rows'][0]['label']);
        $this->assertSame($user->email, $admin['rows'][0]['label']);
        $this->assertEquals(1, $public['rows'][0]['value']);
        $this->assertStringNotContainsString('192.0.2.10', json_encode($public));
    }

    public function test_ua_and_browser_hints_do_not_create_node_device_identities(): void
    {
        $user = $this->user();
        $request = \Illuminate\Http\Request::create('/usage?private=omitted');
        $request->headers->set('User-Agent', 'Clash/1.0');
        $request->headers->set('Sec-CH-UA-Platform', '"Windows"');
        $service = app(UsageAccessService::class);
        $service->record($request, $user->id, 'subscription', '拉取订阅', '成功');
        $service->record($request, $user->id, 'panel', '访问面板', '成功', '/usage?private=omitted');
        $service->record($request, $user->id, 'panel', '访问面板', '成功', '/usage?private=omitted');
        $events = DB::table('v2_usage_event')->orderBy('id')->get();
        $this->assertSame(3, $events->count());
        $this->assertSame('unknown', $events[0]->platform);
        $this->assertSame('Windows', $events[1]->platform);
        $this->assertSame('/usage', $events[1]->path);
        $this->assertSame(0, DB::table('v2_usage_identity')->count());
        $this->assertSame('Clash', UsageAccessService::client($events[0]->user_agent));
    }

    public function test_public_rank_search_cannot_probe_hidden_email_and_searches_before_limit(): void
    {
        $node = $this->node(); $service = app(UsageIngestService::class);
        $a = $this->user(['email' => 'aa.secret.person@example.com']);
        $b = $this->user(['email' => 'zz.visible.person@example.com']);
        $service->ingest('node', $node->id, $this->report($a->id, 1, 0, 0), $node);
        $service->ingest('node', $node->id, $this->report($b->id, 2, 0, 0), $node);
        $query = app(UsageQueryService::class);
        $public = $query->leaderboard('devices', time() - 3600, time(), false, false, $a->id, 'secret', 1);
        $this->assertSame([], $public['rows']);
        $visible = $query->leaderboard('devices', time() - 3600, time(), false, false, $a->id, 'zz***', 1);
        $this->assertSame((string) $b->id, $visible['rows'][0]['id']);
        $admin = $query->leaderboard('devices', time() - 3600, time(), false, true, $a->id, 'secret', 1);
        $this->assertSame($a->email, $admin['rows'][0]['label']);
    }

    public function test_browser_platform_and_month_end_boundaries(): void
    {
        $this->assertSame('Android', UsageAccessService::platform('Mozilla Linux Android 15'));
        $this->assertSame('macOS', UsageAccessService::platform('Mozilla', '"macOS"'));
        $this->assertSame('unknown', UsageAccessService::platform('Clash/1.0'));
        $policy = \App\Http\Controllers\V2\Admin\UsageController::defaultPolicy();
        $policy['resetDay'] = 31; $policy['zone'] = 'UTC';
        [$start, $end] = \App\Http\Controllers\V2\Admin\UsageController::cycle($policy, CarbonImmutable::parse('2028-02-29 12:00:00', 'UTC'));
        $this->assertSame('2028-02-29', gmdate('Y-m-d', $start));
        $this->assertSame('2028-03-31', gmdate('Y-m-d', $end));
    }
}
