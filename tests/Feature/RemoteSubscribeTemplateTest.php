<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\RemoteSubscribeTemplateController;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Services\AdminOperationCatalog;
use App\Services\ChangeEventService;
use App\Services\McpKeyService;
use App\Services\RemoteSubscribeTemplateService;
use App\Services\RemoteTemplateDownloader;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RemoteSubscribeTemplateTest extends TestCase
{

    private User $admin;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        // Independent connections avoid SQLite WAL locks across application reboots.
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        // Do not roll back unrelated legacy migrations during teardown.
        $this->artisan('migrate:fresh', ['--force' => true]);
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
        $this->app->forgetInstance(Setting::class);
        $this->admin = User::create(['email' => 'remote@example.test', 'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]);
        Sanctum::actingAs($this->admin);
        $route = collect(app('router')->getRoutes())->first(fn($route) => $route->getActionName() === RemoteSubscribeTemplateController::class . '@fetch');
        $this->base = '/' . substr($route->uri(), 0, -strlen('fetch'));
    }

    public function test_upgrade_preserves_raw_templates_and_snapshots_all_six_formats(): void
    {
        $migration = require database_path('migrations/2026_10_08_100000_add_remote_subscribe_templates.php');
        $migration->down();
        DB::table('v2_subscribe_templates')->where('name', 'clash')->update(['content' => 'old custom content']);
        DB::table('v2_subscribe_templates')->where('name', 'surge')->update(['content' => null]);
        $before = DB::table('v2_subscribe_templates')->pluck('content', 'name')->all();
        $migration->up();
        $this->assertSame($before, DB::table('v2_subscribe_templates')->pluck('content', 'name')->all());
        $this->assertDatabaseCount('v2_subscribe_template_history', 6);
        $this->assertDatabaseHas('v2_subscribe_template_history', ['name' => 'clash', 'content' => 'old custom content']);
        $this->assertSame(0, SubscribeTemplate::where('auto_update', true)->count());
        $this->assertStringContainsString('[General]', app(RemoteSubscribeTemplateService::class)->historyContent('surge', (int) SubscribeTemplate::where('name', 'surge')->value('current_history_id')));
    }

    public function test_save_fetches_outside_transaction_activates_and_redacts_audit_url(): void
    {
        $old = SubscribeTemplate::getContent('singbox');
        $this->download($this->content(), function () { $this->assertSame(0, DB::transactionLevel()); });
        $result = $this->postJson($this->base . 'save', $this->input())->assertOk()->json('data');
        $this->assertTrue($result['changed']);
        $this->assertSame($this->content(), SubscribeTemplate::getContent('singbox'));
        $this->assertSame('2', $result['settings']['revision']);
        $this->assertNotNull($result['settings']['next_update_at']);
        $this->assertDatabaseHas('v2_subscribe_template_history', ['name' => 'singbox', 'content' => $old]);
        $stored = DB::table('v2_subscribe_templates')->where('name', 'singbox')->value('remote_url');
        $this->assertStringNotContainsString('signed-secret', $stored);
        $logs = DB::table('v2_admin_audit_log')->pluck('request_data')->implode(' ');
        $this->assertStringNotContainsString('signed-secret', $logs);
        $this->assertStringContainsString('[REDACTED]', $logs);
        $this->assertDatabaseHas('v2_change_event', ['action' => 'subscribe_template.remote.save.post']);
    }

    public function test_identical_download_updates_check_time_without_duplicate_history(): void
    {
        $this->download(SubscribeTemplate::getContent('singbox'));
        $result = $this->postJson($this->base . 'save', $this->input())->assertOk()->json('data');
        $this->assertFalse($result['changed']);
        $this->assertDatabaseCount('v2_subscribe_template_history', 6);
        $this->assertNotNull($result['settings']['last_checked_at']);
    }

    public function test_invalid_download_keeps_current_template_source_and_history(): void
    {
        $before = SubscribeTemplate::getContent('singbox');
        $this->download('<html>gateway error</html>');
        $this->postJson($this->base . 'save', $this->input())->assertStatus(422);
        $this->assertSame($before, SubscribeTemplate::getContent('singbox'));
        $this->assertSame('', $this->settings()['url']);
        $this->assertDatabaseCount('v2_subscribe_template_history', 6);
        $this->assertSame(0, app(ChangeEventService::class)->currentVersion());
    }

    public function test_download_cannot_overwrite_manual_edit_made_while_waiting(): void
    {
        $manual = $this->content('manual');
        $this->download($this->content('stale'), fn() => SubscribeTemplate::setContent('singbox', $manual));
        $this->postJson($this->base . 'save', $this->input())->assertStatus(409);
        $this->assertSame($manual, SubscribeTemplate::getContent('singbox'));
        $this->assertSame('', $this->settings()['url']);
    }

    public function test_pause_does_not_download_and_invalidates_an_inflight_update(): void
    {
        $this->download($this->content());
        $this->postJson($this->base . 'save', $this->input())->assertOk();
        $service = app(RemoteSubscribeTemplateService::class);
        $pending = $service->prepare('singbox', $this->settings()['revision']);
        $this->downloadFailure();
        $this->postJson($this->base . 'pause', ['name' => 'singbox', 'expected_revision' => $this->settings()['revision']])
            ->assertOk()->assertJsonPath('data.auto_update', false)->assertJsonPath('data.next_update_at', null);
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(409);
        $service->applyPrepared($pending);
    }

    public function test_scheduler_updates_due_templates_and_records_system_change(): void
    {
        $this->download($this->content());
        $this->postJson($this->base . 'save', $this->input())->assertOk();
        $this->download($this->content('scheduled'));
        $this->travel(7)->hours();
        $this->artisan('subscribe-template:refresh')->assertSuccessful();
        $this->assertSame($this->content('scheduled'), SubscribeTemplate::getContent('singbox'));
        $this->assertDatabaseHas('v2_subscribe_template_history', ['source' => 'automatic']);
        $this->assertDatabaseHas('v2_change_event', ['actor_type' => 'system', 'action' => 'subscribe_template.remote.automatic']);
        $this->assertTrue(SubscribeTemplate::where('name', 'singbox')->first()->next_update_at->isFuture());
        $this->travelBack();
    }

    public function test_scheduler_failure_preserves_content_records_error_and_reschedules(): void
    {
        $this->download($this->content());
        $this->postJson($this->base . 'save', $this->input())->assertOk();
        $this->downloadFailure();
        $this->travel(7)->hours();
        $this->artisan('subscribe-template:refresh')->assertFailed();
        $this->assertSame($this->content(), SubscribeTemplate::getContent('singbox'));
        $this->assertSame('远程模板连接失败。', $this->settings()['last_error']);
        $this->assertTrue(SubscribeTemplate::where('name', 'singbox')->first()->next_update_at->isFuture());
        $this->travelBack();
    }

    public function test_history_restore_delete_and_format_isolation(): void
    {
        $service = app(RemoteSubscribeTemplateService::class);
        $initial = SubscribeTemplate::getContent('singbox');
        $old = $service->history('singbox', 1)['items'][0]['id'];
        $other = $service->history('clash', 1)['items'][0]['id'];
        $this->download($this->content());
        $this->postJson($this->base . 'save', $this->input())->assertOk();
        $current = $service->history('singbox', 1)['items'][0]['id'];
        $this->getJson($this->base . 'history-detail?name=singbox&id=' . $other)->assertNotFound();
        $this->postJson($this->base . 'drop', ['name' => 'singbox', 'id' => $current, 'expected_revision' => $this->settings()['revision']])->assertStatus(422);
        $this->postJson($this->base . 'restore', ['name' => 'singbox', 'id' => $old, 'expected_revision' => $this->settings()['revision']])
            ->assertOk()->assertJsonPath('data.auto_update', false);
        $this->assertSame($initial, SubscribeTemplate::getContent('singbox'));
        $this->assertSame('restore', $service->history('singbox', 1)['items'][0]['source']);
        $this->postJson($this->base . 'drop', ['name' => 'singbox', 'id' => $current, 'expected_revision' => $this->settings()['revision']])->assertOk();
        $this->getJson($this->base . 'history-detail?name=singbox&id=' . $current)->assertNotFound();
    }

    public function test_manual_config_save_is_versioned_and_rollback_does_not_invalidate_cache(): void
    {
        $initial = SubscribeTemplate::getContent('singbox');
        DB::beginTransaction();
        SubscribeTemplate::setContent('singbox', $this->content('rolled-back'));
        $this->assertSame($initial, Cache::store('redis')->get('subscribe_template:singbox'));
        DB::rollBack();
        $this->assertSame($initial, SubscribeTemplate::getContent('singbox'));
        $this->assertDatabaseCount('v2_subscribe_template_history', 6);
        $path = preg_replace('#subscribe-template/remote/$#', 'config/save', $this->base);
        $this->postJson($path, ['subscribe_template_singbox' => $this->content('manual')])->assertOk();
        $this->assertSame($this->content('manual'), SubscribeTemplate::getContent('singbox'));
        $this->assertSame('2', $this->settings()['revision']);
        $this->assertDatabaseHas('v2_subscribe_template_history', ['source' => 'manual', 'content' => $this->content('manual')]);
    }

    public function test_history_paginates_and_rejects_stale_or_invalid_requests(): void
    {
        for ($i = 0; $i < 12; $i++) SubscribeTemplate::setContent('singbox', $this->content((string) $i));
        $this->getJson($this->base . 'history?name=singbox&page=2')->assertOk()->assertJsonPath('data.total', 13)->assertJsonCount(3, 'data.items');
        $this->postJson($this->base . 'pause', ['name' => 'singbox', 'expected_revision' => '1'])->assertStatus(409);
        $this->postJson($this->base . 'save', [...$this->input(), 'interval_hours' => 0])->assertStatus(422);
        $this->getJson($this->base . 'fetch?name=unknown')->assertStatus(422);
        $this->downloadFailure();
        $this->artisan('subscribe-template:refresh')->assertSuccessful(); // Automatic updates default off.
    }

    public function test_mcp_exposes_all_operations_and_enforces_scope_and_revisions(): void
    {
        $catalog = collect(app(AdminOperationCatalog::class)->operations())->keyBy('id');
        foreach (['fetch.get', 'history.get', 'history_detail.get', 'save.post', 'refresh.post', 'pause.post', 'restore.post', 'drop.post'] as $suffix) {
            $entry = $catalog['subscribe_template.remote.' . $suffix];
            $this->assertSame('system', $entry['domain']);
            $this->assertSame(str_ends_with($suffix, '.get'), $entry['read_only']);
            $this->assertNotEmpty($entry['request_fields']);
        }
        $key = app(McpKeyService::class)->create($this->admin, ['name' => 'test', 'scope' => 'full']);
        $this->download($this->content());
        $operation = 'subscribe_template.remote.save.post';
        $args = ['operation' => $operation, 'expected_change_version' => 0, 'parameters' => $this->input(), 'confirmation' => 'CONFIRM ' . $operation];
        $this->mcp($key['secret'], 'xboard_admin_mutate', $args)->assertOk()->assertJsonPath('result.isError', false);
        $this->mcp($key['secret'], 'xboard_admin_mutate', $args)->assertOk()->assertJsonPath('result.isError', true);
        $this->mcp($key['secret'], 'xboard_admin_read', ['operation' => 'subscribe_template.remote.fetch.get', 'parameters' => ['name' => 'singbox']])
            ->assertOk()->assertJsonPath('result.structuredContent.body.data.revision', '2');
        foreach ([['scope' => 'read'], ['scope' => 'custom', 'domains' => ['accounts']]] as $scope) {
            $restricted = app(McpKeyService::class)->create($this->admin, ['name' => 'restricted', ...$scope]);
            $this->mcp($restricted['secret'], 'xboard_admin_mutate', $args)->assertOk()->assertJsonPath('result.isError', true);
        }
    }

    public function test_non_admin_cannot_read_or_fetch_remote_templates(): void
    {
        $user = User::create(['email' => 'ordinary@example.test', 'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => false]);
        Sanctum::actingAs($user);
        $mock = \Mockery::mock(RemoteTemplateDownloader::class);
        $mock->shouldNotReceive('download');
        $this->app->instance(RemoteTemplateDownloader::class, $mock);
        foreach (app('router')->getRoutes() as $route) $route->flushController();
        $this->getJson($this->base . 'fetch?name=singbox')->assertForbidden();
        $this->postJson($this->base . 'save', $this->input())->assertForbidden();
    }

    public function test_all_built_in_templates_validate_and_bad_content_is_rejected(): void
    {
        $service = app(RemoteSubscribeTemplateService::class);
        foreach (RemoteSubscribeTemplateService::NAMES as $name) $service->validateContent($name, SubscribeTemplate::defaultContent($name));
        foreach ([['singbox', '{}'], ['clash', 'a: b'], ['surge', '[General]'], ['singbox', str_repeat('x', 2 * 1024 * 1024 + 1)], ['stash', "\xff"], ['singbox', '']] as [$name, $content]) {
            try { $service->validateContent($name, $content); $this->fail('Invalid template accepted'); }
            catch (ApiException $e) { $this->assertSame(422, $e->getCode()); }
        }
    }

    private function mcp(string $secret, string $tool, array $arguments)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $secret])->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
    }

    private function settings(): array { return app(RemoteSubscribeTemplateService::class)->settings('singbox'); }
    private function input(): array { return ['name' => 'singbox', 'url' => 'https://templates.example.test/config.json?token=signed-secret', 'auto_update' => true, 'interval_hours' => 6, 'expected_revision' => '1']; }
    private function content(string $tag = 'remote'): string { return json_encode(['outbounds' => [['type' => 'direct', 'tag' => $tag]]]); }
    private function download(string $content, ?callable $callback = null): void
    {
        $this->app->forgetInstance(RemoteSubscribeTemplateService::class);
        $mock = \Mockery::mock(RemoteTemplateDownloader::class);
        $mock->shouldReceive('download')->andReturnUsing(function () use ($content, $callback) { if ($callback) $callback(); return $content; });
        $this->app->instance(RemoteTemplateDownloader::class, $mock);
        foreach (app('router')->getRoutes() as $route) $route->flushController();
    }
    private function downloadFailure(): void
    {
        $this->app->forgetInstance(RemoteSubscribeTemplateService::class);
        $mock = \Mockery::mock(RemoteTemplateDownloader::class);
        $mock->shouldReceive('download')->andThrow(new ApiException('远程模板连接失败。', 422));
        $this->app->instance(RemoteTemplateDownloader::class, $mock);
        foreach (app('router')->getRoutes() as $route) $route->flushController();
    }
}
