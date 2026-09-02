<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientApp extends Model
{
    protected $table = 'v2_client_app';

    protected $guarded = ['id'];

    protected $casts = [
        'tags' => 'array',
        'quick_import_enabled' => 'boolean',
        'is_builtin' => 'boolean',
    ];

    public function scopes(): HasMany
    {
        return $this->hasMany(ClientAppScope::class, 'client_app_id');
    }
}
