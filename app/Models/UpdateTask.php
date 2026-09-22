<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class UpdateTask extends Model
{
    public const TERMINAL = ['succeeded', 'failed', 'rolled_back', 'rollback_failed'];
    protected $table = 'v2_update_task';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['claim_token', 'idempotency_key'];
    protected $casts = ['manifest' => 'array', 'previous_versions' => 'array', 'result' => 'array'];
    public function summary(): array
    {
        return ['task_id' => $this->id, 'target_name' => $this->target_name, 'target_version' => $this->target_version,
            'target_updater_version' => $this->target_updater_version, 'status' => $this->status,
            'handoff_phase' => $this->handoff_phase, 'recovery_step' => $this->recovery_step,
            'message' => $this->message, 'created_at' => $this->created_at->toIso8601String(),
            'component' => $this->component, 'instance_id' => $this->instance_id, 'result' => $this->result,
            'stalled' => $this->stalled()];
    }

    /**
     * 卡住判定：执行中（非排队、非终态）且 15 分钟没有任何状态变化。
     * 面板永不重派执行中的任务，所以长时间无进展只能靠管理员中止；
     * 该标志让前端主动提示而不是让任务无限停留在同一个状态。
     */
    public function stalled(): bool
    {
        if ($this->status === 'queued' || in_array($this->status, self::TERMINAL, true)) {
            return false;
        }
        $lastChange = $this->updated_at ?? $this->started_at ?? $this->created_at;
        return $lastChange !== null && $lastChange->lt(now()->subMinutes(15));
    }
}
