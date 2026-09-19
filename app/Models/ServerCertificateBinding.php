<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ServerCertificateBinding extends Model
{
    protected $table = 'v2_server_certificate_binding';

    protected $guarded = ['id'];

    protected $casts = [
        'certificate_id' => 'string',
        'server_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(ServerCertificate::class, 'certificate_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
