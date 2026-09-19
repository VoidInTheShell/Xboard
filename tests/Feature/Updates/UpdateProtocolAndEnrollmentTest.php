<?php

namespace Tests\Feature\Updates;

use App\Exceptions\ApiException;
use App\Models\ServerEnrollment;
use App\Models\ServerMachine;
use App\Models\UpdateExecutor;
use App\Models\UpdateInstance;
use App\Models\UpdateTask;
use App\Services\Updates\EnrollmentService;
use App\Services\Updates\UpdateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateProtocolAndEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
    }

    public function test_enrollment_is_single_use_and_only_the_hash_is_persisted(): void
    {
        $machine = ServerMachine::create([
            'name' => 'enrollment-machine',
            'token' => 'legacy-machine-token',
            'is_active' => true,
        ]);
        $service = app(EnrollmentService::class);
        $issued = $service->issue($machine, 7);

        $record = ServerEnrollment::query()->firstOrFail();
        $this->assertNotSame($issued['token'], $record->token_hash);
        $this->assertSame(hash('sha256', $issued['token']), $record->token_hash);

        $exchange = $service->exchange([
            'enrollment_token' => $issued['token'],
            'architecture' => 'linux/amd64',
            'installation_method' => 'systemd',
            'node_version' => 'v1.14.0-dev.1234.1',
            'updater_version' => 'v0.3.0-dev.123.1',
            'node_instance_id' => 'machine-1',
        ]);

        $this->assertSame((int) $machine->id, $exchange['machine_id']);
        $this->assertSame('v0.3.0-dev.123.1', $exchange['updater_version']);
        $this->assertNotSame('legacy-machine-token', $exchange['executor_secret']);
        $this->assertNotNull($record->fresh()->used_at);
        $this->assertDatabaseHas('v2_update_executor', [
            'scope' => 'machine:' . $machine->id,
            'protocol' => 2,
            'state_schema' => 1,
            'updater_version' => 'v0.3.0-dev.123.1',
        ]);

        try {
            $service->exchange([
                'enrollment_token' => $issued['token'],
                'architecture' => 'linux/amd64',
                'installation_method' => 'systemd',
                'node_version' => 'v1.14.0-dev.1234.1',
                'updater_version' => 'v0.3.0-dev.123.1',
                'node_instance_id' => 'machine-1',
            ]);
            $this->fail('A used enrollment token must be rejected');
        } catch (ApiException $error) {
            $this->assertSame(401, $error->getCode());
            $this->assertSame('注册失败，请生成新的安装命令。', $error->getMessage());
        }
    }

    public function test_public_http_panel_cannot_issue_an_enrollment_token(): void
    {
        config()->set('app.url', 'http://panel.example.test');
        $machine = ServerMachine::create([
            'name' => 'insecure-panel-machine',
            'token' => 'legacy-machine-token',
            'is_active' => true,
        ]);

        try {
            app(EnrollmentService::class)->issue($machine, 7);
            $this->fail('A public HTTP panel must not issue an enrollment token');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame('机器注册要求面板使用 HTTPS；仅允许本机回环地址使用 HTTP。', $error->getMessage());
        }

        $this->assertDatabaseCount('v2_server_enrollment', 0);
    }

    public function test_public_http_panel_is_rejected_before_exchange_consumes_a_token(): void
    {
        config()->set('app.url', 'https://panel.example.test');
        $machine = ServerMachine::create([
            'name' => 'exchange-panel-machine',
            'token' => 'legacy-machine-token',
            'is_active' => true,
        ]);
        $service = app(EnrollmentService::class);
        $issued = $service->issue($machine, 7);

        config()->set('app.url', 'http://panel.example.test');
        try {
            $service->exchange([
                'enrollment_token' => $issued['token'],
                'architecture' => 'linux/amd64',
                'installation_method' => 'systemd',
                'node_version' => 'v1.14.0-dev.1234.1',
                'updater_version' => 'v0.3.0-dev.123.1',
                'node_instance_id' => 'machine-1',
            ]);
            $this->fail('A public HTTP panel must not exchange an enrollment token');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame('机器注册要求面板使用 HTTPS；仅允许本机回环地址使用 HTTP。', $error->getMessage());
            $this->assertStringNotContainsString($issued['token'], $error->getMessage());
        }

        $this->assertNull(ServerEnrollment::query()->firstOrFail()->used_at);
    }

    public function test_heartbeat_upgrades_executor_to_protocol_two_and_records_runtime_identity(): void
    {
        $executor = $this->executor('node', 'machine:1', 'v0.3.0-dev.123.1');
        $manager = app(UpdateManager::class);

        $manager->heartbeat($executor, [
            'updater_version' => 'v0.3.0-dev.123.1',
            'update_protocol' => 2,
            'updater_state_schema' => 1,
            'architecture' => 'linux/amd64',
            'installation_method' => 'systemd',
            'instances' => [[
                'id' => 'machine-1',
                'component' => 'xboard-node',
                'name' => 'Node machine-1',
                'version' => 'v1.14.0-dev.1234.1',
                'installation_method' => 'systemd',
                'ready' => true,
                'reason' => null,
                'capabilities' => ['panel_contract' => 1],
            ]],
        ]);

        $this->assertDatabaseHas('v2_update_executor', [
            'id' => $executor->id,
            'protocol' => 2,
            'state_schema' => 1,
            'architecture' => 'linux/amd64',
            'installation_method' => 'systemd',
        ]);
        $this->assertDatabaseHas('v2_update_instance', [
            'executor_id' => $executor->id,
            'instance_id' => 'machine-1',
            'version' => 'v1.14.0-dev.1234.1',
        ]);
    }

    public function test_admin_success_requires_same_target_admin_and_updater_versions(): void
    {
        $executor = $this->executor('panel', 'panel', 'v0.3.0-dev.123.1');
        $instance = UpdateInstance::create([
            'executor_id' => $executor->id,
            'instance_id' => 'admin',
            'component' => 'xboard-admin',
            'name' => 'Admin',
            'version' => 'v0.2.0',
            'installation_method' => 'compose',
            'ready' => true,
            'capabilities' => ['panel_contract' => 1],
        ]);
        $task = $this->task($executor, $instance, [
            'status' => 'verifying',
            'handoff_phase' => 'verifying',
            'target_version' => 'v0.3.0-dev.123.1',
            'target_updater_version' => 'v0.3.0-dev.123.1',
        ]);

        $summary = app(UpdateManager::class)->report($executor, [
            'task_id' => $task->id,
            'claim_token' => $task->claim_token,
            'sequence' => 1,
            'status' => 'succeeded',
            'handoff_phase' => 'succeeded',
            'message' => 'ok',
            'result' => [
                'version' => 'v0.3.0-dev.123.1',
                'updater_version' => 'v0.3.0-dev.123.1',
            ],
        ]);

        $this->assertSame('succeeded', $summary['status']);
        $this->assertSame('succeeded', $summary['handoff_phase']);
        $this->assertSame('v0.3.0-dev.123.1', $instance->fresh()->version);
    }

    public function test_admin_handoff_first_report_skips_local_transients_and_rejects_reverse_or_version_mismatch(): void
    {
        $executor = $this->executor('panel', 'panel', 'v0.3.0-dev.123.1');
        $instance = UpdateInstance::create([
            'executor_id' => $executor->id,
            'instance_id' => 'admin',
            'component' => 'xboard-admin',
            'name' => 'Admin',
            'version' => 'v0.2.0',
            'installation_method' => 'compose',
            'ready' => true,
            'capabilities' => ['panel_contract' => 1],
        ]);
        $task = $this->task($executor, $instance, [
            'status' => 'preparing',
            'handoff_phase' => 'prepared',
        ]);

        $summary = app(UpdateManager::class)->report($executor, [
            'task_id' => $task->id,
            'claim_token' => $task->claim_token,
            'sequence' => 1,
            'status' => 'installing',
            'handoff_phase' => 'admin_installing',
            'result' => [],
        ]);

        $this->assertSame('installing', $summary['status']);
        $this->assertSame('admin_installing', $summary['handoff_phase']);

        try {
            app(UpdateManager::class)->report($executor, [
                'task_id' => $task->id,
                'claim_token' => $task->claim_token,
                'sequence' => 2,
                'status' => 'preparing',
                'handoff_phase' => 'prepared',
                'result' => [],
            ]);
            $this->fail('A handoff report must not move back to prepared');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getCode());
        }

        app(UpdateManager::class)->report($executor, [
            'task_id' => $task->id,
            'claim_token' => $task->claim_token,
            'sequence' => 2,
            'status' => 'verifying',
            'handoff_phase' => 'verifying',
            'result' => [],
        ]);

        try {
            app(UpdateManager::class)->report($executor, [
                'task_id' => $task->id,
                'claim_token' => $task->claim_token,
                'sequence' => 3,
                'status' => 'succeeded',
                'handoff_phase' => 'succeeded',
                'result' => [
                    'version' => 'v0.3.0-dev.123.1',
                    'updater_version' => 'v0.2.0',
                ],
            ]);
            $this->fail('Admin success must prove the target Admin and Updater versions match');
        } catch (ApiException $error) {
            $this->assertSame(422, $error->getCode());
        }
    }

    public function test_rollback_failed_locks_executor_and_invalid_handoff_transition_is_rejected(): void
    {
        $executor = $this->executor('panel', 'panel', 'v0.3.0-dev.123.1');
        $instance = UpdateInstance::create([
            'executor_id' => $executor->id,
            'instance_id' => 'admin',
            'component' => 'xboard-admin',
            'name' => 'Admin',
            'version' => 'v0.2.0',
            'installation_method' => 'compose',
            'ready' => true,
            'capabilities' => ['panel_contract' => 1],
        ]);
        $task = $this->task($executor, $instance, [
            'status' => 'preparing',
            'handoff_phase' => 'prepared',
        ]);

        try {
            app(UpdateManager::class)->report($executor, [
                'task_id' => $task->id,
                'claim_token' => $task->claim_token,
                'sequence' => 1,
                'status' => 'installing',
                'handoff_phase' => 'verifying',
                'result' => [],
            ]);
            $this->fail('A handoff phase must not skip the state machine');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getCode());
        }

        $rollback = app(UpdateManager::class)->report($executor, [
            'task_id' => $task->id,
            'claim_token' => $task->claim_token,
            'sequence' => 2,
            'status' => 'rolling_back',
            'handoff_phase' => 'rolling_back',
            'result' => [],
        ]);
        $this->assertSame('rolling_back', $rollback['status']);
        $this->assertSame('rolling_back', $rollback['handoff_phase']);

        try {
            app(UpdateManager::class)->report($executor, [
                'task_id' => $task->id,
                'claim_token' => $task->claim_token,
                'sequence' => 3,
                'status' => 'rollback_failed',
                'handoff_phase' => 'rollback_failed',
                'recovery_step' => '人工恢复原版本并检查健康状态',
                'result' => [
                    'version' => 'v0.3.0-dev.123.1',
                    'updater_version' => 'v0.3.0-dev.123.1',
                ],
            ]);
            $this->fail('A rollback terminal report must prove the previous Admin and Updater versions');
        } catch (ApiException $error) {
            $this->assertSame(422, $error->getCode());
        }

        app(UpdateManager::class)->report($executor, [
            'task_id' => $task->id,
            'claim_token' => $task->claim_token,
            'sequence' => 3,
            'status' => 'rollback_failed',
            'handoff_phase' => 'rollback_failed',
            'recovery_step' => '人工恢复原版本并检查健康状态',
            'result' => [
                'version' => 'v0.2.0',
                'updater_version' => 'v0.2.0',
            ],
        ]);

        $this->assertDatabaseHas('v2_update_executor', ['id' => $executor->id, 'blocked' => 1]);
        $this->assertDatabaseHas('v2_update_task', [
            'id' => $task->id,
            'status' => 'rollback_failed',
            'handoff_phase' => 'rollback_failed',
        ]);
    }

    private function executor(string $kind, string $scope, string $updaterVersion): UpdateExecutor
    {
        return UpdateExecutor::create([
            'id' => (string) Str::uuid(),
            'name' => $kind . '-executor',
            'kind' => $kind,
            'scope' => $scope,
            'machine_id' => $kind === 'node' ? 1 : null,
            'secret_hash' => hash('sha256', Str::random(48)),
            'enabled' => true,
            'blocked' => false,
            'protocol' => 1,
            'state_schema' => 1,
            'updater_version' => $updaterVersion,
            'last_seen_at' => now(),
        ]);
    }

    private function task(UpdateExecutor $executor, UpdateInstance $instance, array $attributes = []): UpdateTask
    {
        return UpdateTask::create(array_merge([
            'id' => (string) Str::uuid(),
            'executor_id' => $executor->id,
            'instance_record_id' => $instance->id,
            'instance_id' => $instance->instance_id,
            'component' => $instance->component,
            'target_name' => 'panel / Admin',
            'target_version' => 'v0.3.0-dev.123.1',
            'target_updater_version' => 'v0.3.0-dev.123.1',
            'channel' => 'dev',
            'status' => 'preparing',
            'handoff_phase' => 'prepared',
            'idempotency_key' => Str::random(32),
            'created_by' => 1,
            'manifest' => ['schema_version' => 2],
            'previous_versions' => [$instance->instance_id => $instance->version],
            'claim_token' => Str::random(48),
            'sequence' => 0,
        ], $attributes));
    }
}
