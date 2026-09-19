<?php
namespace App\Models;

use App\Services\Updates\ReleaseCatalog;
use Illuminate\Database\Eloquent\Model;

class UpdateExecutor extends Model
{
    protected $table = 'v2_update_executor';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['secret_hash'];
    protected $casts = [
        'enabled' => 'boolean',
        'blocked' => 'boolean',
        'protocol' => 'integer',
        'state_schema' => 'integer',
        'last_seen_at' => 'datetime',
    ];
    public function instances() { return $this->hasMany(UpdateInstance::class, 'executor_id'); }
    public function online(): bool
    {
        return $this->enabled && $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    public function protocolReady(): bool
    {
        return (int) $this->protocol === 2 && (int) $this->state_schema === 1
            && ReleaseCatalog::channel($this->updater_version) !== null;
    }
}
