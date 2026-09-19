<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerEnrollment extends Model
{
    protected $table = 'v2_server_enrollment';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function usable(): bool
    {
        return !$this->used_at && !$this->revoked_at && $this->expires_at?->isFuture();
    }
}
