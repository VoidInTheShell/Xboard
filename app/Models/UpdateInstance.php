<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class UpdateInstance extends Model
{
    protected $table = 'v2_update_instance';
    protected $guarded = [];
    protected $casts = ['ready' => 'boolean', 'capabilities' => 'array'];
    public function executor() { return $this->belongsTo(UpdateExecutor::class, 'executor_id'); }
}
