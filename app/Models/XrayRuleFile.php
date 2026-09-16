<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XrayRuleFile extends Model
{
    protected $table = 'v2_xray_rule_file';
    protected $guarded = ['id'];
    protected $casts = [
        'server_id' => 'integer',
        'auto_update' => 'boolean',
        'update_interval_hours' => 'integer',
        'built_in' => 'boolean',
        'read_only' => 'boolean',
        'download_revision' => 'integer',
        'size' => 'integer',
        'file_updated_at' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
