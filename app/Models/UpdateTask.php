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
            'status' => $this->status, 'message' => $this->message, 'created_at' => $this->created_at->toIso8601String(),
            'component' => $this->component, 'instance_id' => $this->instance_id, 'result' => $this->result];
    }
}
