<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class ServerCertificate extends Model
{
    public const SOURCE_ACME_HTTP = 'acme_http';
    public const SOURCE_ACME_DNS = 'acme_dns';
    public const SOURCE_PATH = 'path';
    public const SOURCE_CONTENT = 'content';
    public const SOURCE_SELF_SIGNED = 'self_signed';

    public const SOURCES = [
        self::SOURCE_ACME_HTTP,
        self::SOURCE_ACME_DNS,
        self::SOURCE_PATH,
        self::SOURCE_CONTENT,
        self::SOURCE_SELF_SIGNED,
    ];

    protected $table = 'v2_server_certificate';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $casts = [
        'domains' => 'array',
        'auto_renew' => 'boolean',
        'revision' => 'integer',
        'dns_credentials' => 'encrypted',
        'certificate_content' => 'encrypted',
        'private_key_content' => 'encrypted',
        'not_before_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_renewed_at' => 'datetime',
        'next_renewal_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'dns_credentials',
        'certificate_content',
        'private_key_content',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(ServerCertificateBinding::class, 'certificate_id');
    }
}
