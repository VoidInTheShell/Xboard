<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpKey extends Model
{
    protected $table = 'v2_mcp_key';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['token_hash'];
    protected $casts = [
        'domains' => 'array',
        'expires_at' => 'timestamp',
        'revoked_at' => 'timestamp',
        'last_used_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || (int) $this->expires_at > time();
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }
        if ($this->expires_at !== null && (int) $this->expires_at <= time()) {
            return 'expired';
        }

        return 'active';
    }
}
