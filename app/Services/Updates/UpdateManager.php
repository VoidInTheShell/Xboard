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
    private const HANDOFF_PHASE_STATUS = [
        'prepared' => ['preparing'],
        'admin_installing' => ['installing'],
        'verifying' => ['verifying'],
        'rolling_back' => ['rolling_back'],
        'succeeded' => ['succeeded'],
        'rolled_back' => ['rolled_back'],
        'rollback_failed' => ['rollback_failed'],
    ];

    private const HANDOFF_TRANSITIONS = [
        // target_booting and target_adopted are local handoff journal phases;
        // the backend's first report starts at admin_installing.
        'prepared' => ['admin_installing', 'rolling_back'],
        'admin_installing' => ['verifying', 'rolling_back'],
        'verifying' => ['succeeded', 'rolling_back'],
        'rolling_back' => ['rolled_back', 'rollback_failed'],
    ];

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
        if (!$executor->protocolReady()) return '更新器协议或状态版本不兼容';
        if (($manifest['compatibility']['panel_contract'] ?? null) !== 1
            || ($manifest['compatibility']['update_protocol'] ?? null) !== ReleaseCatalog::UPDATE_PROTOCOL
            || ($manifest['compatibility']['updater_state_schema'] ?? null) !== ReleaseCatalog::STATE_SCHEMA) {
            return '目标版本协议不兼容';
        }
        if ($executor->kind === 'panel') {
            $components = $executor->instances->keyBy('component');
            $required = ['xboard', 'xboard-admin'];
            foreach ($required as $name) {
                $current = $components->get($name);
                if (!$current || !ReleaseCatalog::channel($current->version)) return '请先登记后端和 Admin 的准确版本';
                if (($current->capabilities['panel_contract'] ?? null) !== 1) return '当前组件组合的兼容性未确认';
            }
            // Theme is optional. When the updater reports one, validate it; never
            // create or upgrade it as a side effect.
            $theme = $components->get('dk_theme');
            if ($theme) {
                if (!ReleaseCatalog::channel($theme->version)) return '请先登记 Theme 的准确版本';
                if (($theme->capabilities['panel_contract'] ?? null) !== 1) return '当前组件组合的兼容性未确认';
            }
            $admin = $components->get('xboard-admin');
            if ($admin && $executor->updater_version !== $admin->version) {
                return '当前 Admin 与 Updater 版本不一致，已暂停更新';
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
                'target_version' => $input['target_version'],
                'target_updater_version' => $instance->component === 'xboard-admin' && $executor->kind === 'panel'
                    ? $input['target_version'] : null,
                'channel' => $input['channel'],
                'handoff_phase' => $instance->component === 'xboard-admin' && $executor->kind === 'panel'
                    ? 'prepared' : null,
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

    private function isPanelAdminTask(UpdateTask $task, UpdateExecutor $executor): bool
    {
        return $executor->kind === 'panel' && $task->component === 'xboard-admin';
    }

    private function handoffTransitionAllowed(?string $from, string $to): bool
    {
        return in_array($to, self::HANDOFF_TRANSITIONS[$from ?? ''] ?? [], true);
    }

    private function handoffTask(UpdateTask $task, UpdateExecutor $executor, array $input): array
    {
        $isPanelAdmin = $this->isPanelAdminTask($task, $executor);
        if (!$isPanelAdmin) {
            if (array_key_exists('handoff_phase', $input)) {
                throw new ApiException('普通更新任务不支持交接阶段。', 422);
            }
            return [];
        }

        $phase = $input['handoff_phase'] ?? null;
        if ($phase === null) {
            // A preparation failure happens before a handoff journal exists.
            // Every other Admin report must carry both state machines.
            if ($input['status'] === 'failed' && $task->handoff_phase === 'prepared') {
                return [];
            }
            throw new ApiException('Admin 更新回执缺少交接阶段。', 422);
        }
        if (!in_array($input['status'], self::HANDOFF_PHASE_STATUS[$phase] ?? [], true)) {
            throw new ApiException('交接阶段与任务状态冲突。', 409);
        }
        if (!$this->handoffTransitionAllowed($task->handoff_phase, $phase)) {
            throw new ApiException('更新交接阶段转换无效。', 409);
        }

        return ['handoff_phase' => $phase];
    }

    private function reportMatchesCurrent(UpdateTask $task, array $input): bool
    {
        $same = $task->status === $input['status']
            && $task->handoff_phase === ($input['handoff_phase'] ?? null)
            && $task->message === ($input['message'] ?? null)
            && $task->recovery_step === ($input['recovery_step'] ?? null);
        if (!$same) {
            return false;
        }

        $current = $task->result;
        $incoming = $input['result'] ?? null;
        if (is_array($current) && is_array($incoming)) {
            $normalize = static function ($value) use (&$normalize) {
                if (!is_array($value)) return $value;
                if (array_is_list($value)) return array_map($normalize, $value);
                ksort($value);
                foreach ($value as $key => $item) $value[$key] = $normalize($item);
                return $value;
            };
            return $normalize($current) === $normalize($incoming);
        }
        return $current === $incoming;
    }

    private function previousVersion(UpdateTask $task): ?string
    {
        $versions = $task->previous_versions;
        $version = is_array($versions) ? ($versions[$task->instance_id] ?? null) : null;
        return is_string($version) && $version !== '' ? $version : null;
    }

    private function validateTerminalResult(UpdateTask $task, UpdateExecutor $executor, array $input): void
    {
        if (!$this->isPanelAdminTask($task, $executor)) {
            if ($input['status'] === 'succeeded' && ($input['result']['version'] ?? '') !== $task->target_version) {
                throw new ApiException('缺少目标准确版本验证。', 422);
            }
            return;
        }

        $expectedVersion = null;
        $expectedUpdater = null;
        if ($input['status'] === 'succeeded') {
            $expectedVersion = $task->target_version;
            $expectedUpdater = $task->target_updater_version;
        } elseif (in_array($input['status'], ['rolled_back', 'rollback_failed'], true)) {
            $expectedVersion = $this->previousVersion($task);
            // Panel Admin and its updater are required to share a release
            // before a task is created, so the frozen Admin version is also
            // the only trusted previous updater version available here.
            $expectedUpdater = $expectedVersion;
        }

        if (!$expectedVersion || !$expectedUpdater
            || ($input['result']['version'] ?? '') !== $expectedVersion
            || ($input['result']['updater_version'] ?? '') !== $expectedUpdater) {
            throw new ApiException('Admin 与 Updater 未以同一准确版本完成。', 422);
        }
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
                'updater_version' => $executor?->updater_version,
                'updater_protocol' => $executor?->protocol,
                'updater_state_schema' => $executor?->state_schema,
                'installation_method' => $executor?->installation_method,
                'instances' => $executor?->instances->map(fn ($item) => [
                    'id' => $item->instance_id, 'name' => $item->name, 'version' => $item->version,
                    'installation_method' => $item->installation_method, 'updater_ready' => $item->ready
                        && !$executor->blocked && $executor->protocolReady(),
                    'reason' => $executor->blocked ? '恢复未完成，请先人工恢复并解除锁定' : $item->reason,
                ])->values()->all() ?? []];
        })->all();
        $tasks = UpdateTask::whereIn('executor_id', $executors->where('kind', $kind)->pluck('id'))
            ->latest()->limit(100)->get()->map(fn ($task) => $task->summary())->all();
        return ['panel' => ['name' => $panel?->name ?? '当前面板',
            'updater_ready' => (bool) ($panel?->online() && !$panel->blocked && $panel->protocolReady()),
            'updater_version' => $panel?->updater_version,
            'update_protocol' => $panel?->protocol,
            'updater_state_schema' => $panel?->state_schema,
            'handoff_status' => $panel?->handoff_phase,
            'reason' => $panel?->blocked ? '恢复未完成，请先人工恢复并解除锁定'
                : (!$panel?->online() ? '请先接入独立宿主机更新器'
                    : (!$panel->protocolReady() ? 'Updater 协议或状态版本不兼容' : null)),
            'components' => $panel?->instances->map(fn ($item) => ['component' => $item->component,
                'name' => $item->name, 'version' => $item->version])->all() ?? []],
            'machines' => $machines, 'tasks' => $tasks];
    }
    public function heartbeat(UpdateExecutor $executor, array $input): void
    {
        DB::transaction(function () use ($executor, $input) {
            $executor = $this->lockExecutor($executor->id);
            $executor->update([
                'last_seen_at' => now(),
                'architecture' => $input['architecture'],
                'updater_version' => $input['updater_version'],
                'protocol' => (int) $input['update_protocol'],
                'state_schema' => (int) $input['updater_state_schema'],
                'installation_method' => $input['installation_method'],
            ]);
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
                if ($task->component === 'xboard-admin' && $executor->kind === 'panel') {
                    $executor->update(['handoff_phase' => 'prepared']);
                }
            }
            return ['task_id' => $task->id, 'instance_id' => $task->instance_id, 'component' => $task->component,
                'target_version' => $task->target_version, 'manifest' => $task->manifest,
                'target_updater_version' => $task->target_updater_version,
                'handoff_phase' => $task->handoff_phase,
                'updater_version' => $executor->updater_version,
                'update_protocol' => $executor->protocol,
                'updater_state_schema' => $executor->state_schema,
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
            if ($input['sequence'] < $task->sequence) return $task->summary();
            if ($input['sequence'] === $task->sequence) {
                if (!$this->reportMatchesCurrent($task, $input)) {
                    throw new ApiException('重复任务回执内容不一致。', 409);
                }
                return $task->summary();
            }
            if (in_array($task->status, UpdateTask::TERMINAL, true)) throw new ApiException('任务已结束。', 409);
            $transitions = [
                'preparing' => ['backing_up', 'installing', 'failed', 'rolling_back', 'rollback_failed'],
                'backing_up' => ['installing', 'failed', 'rolling_back', 'rollback_failed'],
                'installing' => ['verifying', 'rolling_back', 'rollback_failed'],
                'verifying' => ['succeeded', 'rolling_back', 'rollback_failed'],
                'rolling_back' => ['rolled_back', 'rollback_failed'],
            ];
            if (!in_array($input['status'], $transitions[$task->status] ?? [], true)) throw new ApiException('任务状态转换无效。', 409);
            if (in_array($input['status'], ['succeeded', 'rolled_back', 'rollback_failed'], true)) {
                $this->validateTerminalResult($task, $executor, $input);
            }
            $handoff = $this->handoffTask($task, $executor, $input);
            if ($executor->kind === 'panel' && $task->component === 'xboard-admin'
                && in_array($input['status'], ['succeeded', 'rolled_back', 'rollback_failed'], true)
                && (($input['handoff_phase'] ?? null) !== $input['status'])) {
                throw new ApiException('交接终态与任务终态不一致。', 422);
            }
            $task->update(['status' => $input['status'], 'sequence' => $input['sequence'], 'message' => $input['message'] ?? null,
                'result' => $input['result'] ?? null, 'recovery_step' => $input['recovery_step'] ?? null,
                ...$handoff,
                'finished_at' => in_array($input['status'], UpdateTask::TERMINAL, true) ? now() : null]);
            if (isset($handoff['handoff_phase'])) $executor->update(['handoff_phase' => $handoff['handoff_phase']]);
            if ($task->status === 'succeeded') UpdateInstance::whereKey($task->instance_record_id)->update(['version' => $task->target_version]);
            if ($task->status === 'rollback_failed') $executor->update(['blocked' => true]);
            return $task->summary();
        });
    }
}
