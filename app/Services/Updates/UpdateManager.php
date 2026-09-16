<?php
namespace App\Services\Updates;

use App\Exceptions\ApiException;
use App\Models\ServerMachine;
use App\Models\UpdateExecutor;
use App\Models\UpdateInstance;
use App\Models\UpdateTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateManager
{
    public function __construct(private readonly ReleaseCatalog $catalog) {}
    private function lockExecutor(string $id): UpdateExecutor
    {
        // SQLite ignores SELECT FOR UPDATE. Acquire its writer lock before any reads.
        if (DB::connection()->getDriverName() === 'sqlite') {
            UpdateExecutor::whereKey($id)->update(['enabled' => DB::raw('enabled')]);
        }
        return UpdateExecutor::whereKey($id)->lockForUpdate()->firstOrFail();
    }
    public function instance(array $input): UpdateInstance
    {
        $query = UpdateInstance::query()->where('component', $input['component']);
        if ($input['target_kind'] === 'node') {
            $query->where('instance_id', $input['instance_id'] ?? '')
                ->whereHas('executor', fn ($q) => $q->where('kind', 'node')->where('machine_id', $input['machine_id'] ?? 0));
        } else {
            $query->whereHas('executor', fn ($q) => $q->where('kind', 'panel'));
        }
        return $query->first() ?? throw new ApiException('更新器尚未登记此安装实例。', 422);
    }
    private function enabled(UpdateExecutor $executor): bool
    {
        return $executor->enabled && ($executor->kind === 'panel'
            || ServerMachine::whereKey($executor->machine_id)->where('is_active', true)->exists());
    }
    public function compatibility(UpdateInstance $instance, array $manifest): ?string
    {
        $executor = $instance->executor;
        if ($executor->blocked) return '上次恢复未完成，请先人工恢复并解除更新器锁定';
        if (!$this->enabled($executor) || !$executor->online()) return '更新器离线或已禁用';
        if (!$instance->ready) return $instance->reason ?: '更新器未就绪';
        if (!in_array($executor->architecture, $manifest['platforms'] ?? [], true)) return '目标版本不支持当前架构';
        if ($executor->protocol !== 1 && (int) $executor->protocol !== 1) return '更新器协议不兼容';
        if (($manifest['compatibility']['panel_contract'] ?? null) !== 1) return '面板协议不兼容';
        if ($executor->kind === 'panel') {
            $components = $executor->instances->keyBy('component');
            foreach (['xboard', 'xboard-admin', 'dk_theme'] as $name) {
                $current = $components->get($name);
                if (!$current || !ReleaseCatalog::channel($current->version)) return '请先登记三个面板组件的准确版本';
                if (($current->capabilities['panel_contract'] ?? null) !== 1) return '当前组件组合的兼容性未确认';
            }
            if ($instance->component === 'xboard' && !($instance->capabilities['database_recovery'] ?? false)) {
                return '后端更新需先配置数据库备份、迁移及恢复能力';
            }
        }
        return null;
    }
    public function releases(array $input): array
    {
        $instance = $this->instance($input);
        return array_map(function ($item) use ($instance) {
            $reason = $this->compatibility($instance, $item['manifest']);
            unset($item['manifest']);
            return $item + ['compatible' => $reason === null, 'reason' => $reason];
        }, $this->catalog->releases($input['component'], $input['channel']));
    }
    public function create(array $input, int $userId): UpdateTask
    {
        $instance = $this->instance($input);
        $old = UpdateTask::where('idempotency_key', $input['idempotency_key'])->first();
        if ($old) return $this->sameRequest($old, $instance, $input, $userId);
        $release = $this->catalog->exact($input['component'], $input['target_version']);
        if ($release['channel'] !== $input['channel']) throw new ApiException('目标分支与版本不一致。', 422);
        return DB::transaction(function () use ($instance, $input, $userId, $release) {
            $executor = $this->lockExecutor($instance->executor_id);
            $old = UpdateTask::where('idempotency_key', $input['idempotency_key'])->first();
            if ($old) return $this->sameRequest($old, $instance, $input, $userId);
            $instance->refresh()->setRelation('executor', $executor);
            if ($reason = $this->compatibility($instance, $release['manifest'])) throw new ApiException($reason, 409);
            if ($instance->version === $input['target_version']) throw new ApiException('实例已是目标版本。', 409);
            if (UpdateTask::where('instance_record_id', $instance->id)->whereNotIn('status', UpdateTask::TERMINAL)->exists()) {
                throw new ApiException('该实例已有未完成的升级任务。', 409);
            }
            return UpdateTask::create(['id' => (string) Str::uuid(), 'executor_id' => $executor->id,
                'instance_record_id' => $instance->id, 'instance_id' => $instance->instance_id,
                'component' => $instance->component, 'target_name' => $executor->name . ' / ' . $instance->name,
                'target_version' => $input['target_version'], 'channel' => $input['channel'],
                'idempotency_key' => $input['idempotency_key'], 'created_by' => $userId,
                'manifest' => $release['manifest'], 'previous_versions' => $executor->instances->pluck('version', 'instance_id')->all(),
                'status' => 'queued']);
        });
    }
    private function sameRequest(UpdateTask $task, UpdateInstance $instance, array $input, int $userId): UpdateTask
    {
        if ((int) $task->created_by !== $userId || (int) $task->instance_record_id !== $instance->id
            || $task->target_version !== $input['target_version'] || $task->channel !== $input['channel']) {
            throw new ApiException('幂等键已经用于其他更新请求。', 409);
        }
        return $task;
    }
    public function overview(string $kind): array
    {
        $executors = UpdateExecutor::with('instances')->get();
        $panel = $executors->firstWhere('kind', 'panel');
        $machines = ServerMachine::orderBy('id')->get()->map(function ($machine) use ($executors) {
            $executor = $executors->firstWhere('machine_id', $machine->id);
            return ['id' => (string) $machine->id, 'name' => $machine->name,
                'online' => (bool) ($machine->is_active && $executor?->online()),
                'architecture' => $executor?->architecture ?? '',
                'instances' => $executor?->instances->map(fn ($item) => [
                    'id' => $item->instance_id, 'name' => $item->name, 'version' => $item->version,
                    'installation_method' => $item->installation_method, 'updater_ready' => $item->ready && !$executor->blocked,
                    'reason' => $executor->blocked ? '恢复未完成，请先人工恢复并解除锁定' : $item->reason,
                ])->values()->all() ?? []];
        })->all();
        $tasks = UpdateTask::whereIn('executor_id', $executors->where('kind', $kind)->pluck('id'))
            ->latest()->limit(100)->get()->map(fn ($task) => $task->summary())->all();
        return ['panel' => ['name' => $panel?->name ?? '当前面板', 'updater_ready' => (bool) ($panel?->online() && !$panel->blocked),
            'reason' => $panel?->blocked ? '恢复未完成，请先人工恢复并解除锁定' : ($panel?->online() ? null : '请先接入独立宿主机更新器'),
            'components' => $panel?->instances->map(fn ($item) => ['component' => $item->component,
                'name' => $item->name, 'version' => $item->version])->all() ?? []],
            'machines' => $machines, 'tasks' => $tasks];
    }
    public function heartbeat(UpdateExecutor $executor, array $input): void
    {
        DB::transaction(function () use ($executor, $input) {
            $executor = $this->lockExecutor($executor->id);
            $executor->update(['last_seen_at' => now(), 'architecture' => $input['architecture'], 'protocol' => 1]);
            $allowed = $executor->kind === 'panel' ? ['xboard', 'xboard-admin', 'dk_theme'] : ['xboard-node'];
            $ids = [];
            $components = [];
            foreach ($input['instances'] as $item) {
                if (!in_array($item['component'], $allowed, true) || ($executor->kind === 'panel' && in_array($item['component'], $components, true))) {
                    throw new ApiException('更新器登记的组件不符合其授权范围。', 422);
                }
                if ($item['version'] !== null && !ReleaseCatalog::channel($item['version'])) throw new ApiException('上报版本不是准确发布版本。', 422);
                $ids[] = $item['id']; $components[] = $item['component'];
                UpdateInstance::updateOrCreate(['executor_id' => $executor->id, 'instance_id' => $item['id']],
                    ['component' => $item['component'], 'name' => $item['name'], 'version' => $item['version'],
                        'installation_method' => $item['installation_method'], 'ready' => $item['ready'],
                        'reason' => $item['reason'] ?? null, 'capabilities' => $item['capabilities'] ?? []]);
            }
            UpdateInstance::where('executor_id', $executor->id)->whereNotIn('instance_id', $ids)
                ->update(['ready' => false, 'reason' => '更新器已不再管理此实例']);
        });
    }
    public function claim(UpdateExecutor $executor): ?array
    {
        return DB::transaction(function () use ($executor) {
            $executor = $this->lockExecutor($executor->id);
            if (!$this->enabled($executor)) throw new ApiException('更新器已禁用。', 403);
            if ($executor->blocked) return null;
            // Never expire/reassign an executing task: a lost heartbeat may be a panel self-update.
            $task = UpdateTask::where('executor_id', $executor->id)->whereNotIn('status', UpdateTask::TERMINAL)
                ->where('status', '!=', 'queued')->orderBy('created_at')->first();
            $reclaimed = $task !== null;
            if (!$task) {
                $task = UpdateTask::where('executor_id', $executor->id)->where('status', 'queued')->oldest()->first();
                if (!$task) return null;
                $instance = UpdateInstance::findOrFail($task->instance_record_id);
                $instance->setRelation('executor', $executor);
                if ($reason = $this->compatibility($instance, $task->manifest)) {
                    $task->update(['status' => 'failed', 'message' => $reason, 'finished_at' => now()]);
                    return null;
                }
                // Freeze the actual combination at execution, after older queued component updates.
                $task->update(['status' => 'preparing', 'claim_token' => Str::random(48), 'started_at' => now(),
                    'previous_versions' => $executor->instances->pluck('version', 'instance_id')->all()]);
            }
            return ['task_id' => $task->id, 'instance_id' => $task->instance_id, 'component' => $task->component,
                'target_version' => $task->target_version, 'manifest' => $task->manifest,
                'previous_versions' => $task->previous_versions, 'claim_token' => $task->claim_token,
                'sequence' => $task->sequence, 'status' => $task->status, 'reclaimed' => $reclaimed];
        });
    }
    public function report(UpdateExecutor $executor, array $input): array
    {
        return DB::transaction(function () use ($executor, $input) {
            $executor = $this->lockExecutor($executor->id);
            $task = UpdateTask::whereKey($input['task_id'])->where('executor_id', $executor->id)->lockForUpdate()->first();
            if (!$task || !$task->claim_token || !hash_equals($task->claim_token, $input['claim_token'])) throw new ApiException('无效任务回执。', 403);
            if ($input['sequence'] <= $task->sequence) return $task->summary();
            if (in_array($task->status, UpdateTask::TERMINAL, true)) throw new ApiException('任务已结束。', 409);
            $transitions = [
                'preparing' => ['backing_up', 'installing', 'failed', 'rolling_back', 'rollback_failed'],
                'backing_up' => ['installing', 'failed', 'rolling_back', 'rollback_failed'],
                'installing' => ['verifying', 'rolling_back', 'rollback_failed'],
                'verifying' => ['succeeded', 'rolling_back', 'rollback_failed'],
                'rolling_back' => ['rolled_back', 'rollback_failed'],
            ];
            if (!in_array($input['status'], $transitions[$task->status] ?? [], true)) throw new ApiException('任务状态转换无效。', 409);
            if ($input['status'] === 'succeeded' && ($input['result']['version'] ?? '') !== $task->target_version) throw new ApiException('缺少目标准确版本验证。', 422);
            $task->update(['status' => $input['status'], 'sequence' => $input['sequence'], 'message' => $input['message'] ?? null,
                'result' => $input['result'] ?? null, 'finished_at' => in_array($input['status'], UpdateTask::TERMINAL, true) ? now() : null]);
            if ($task->status === 'succeeded') UpdateInstance::whereKey($task->instance_record_id)->update(['version' => $task->target_version]);
            if ($task->status === 'rollback_failed') $executor->update(['blocked' => true]);
            return $task->summary();
        });
    }
}
