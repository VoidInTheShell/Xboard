<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAppRecommendation extends Model
{
    protected $table = 'v2_client_app_recommendation';

    protected $guarded = ['id'];

    protected $casts = [
        'client_app_scope_id' => 'integer',
        'is_manual' => 'boolean',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(ClientAppScope::class, 'client_app_scope_id');
    }
}
