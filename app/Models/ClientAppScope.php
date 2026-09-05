<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClientAppScope extends Model
{
    protected $table = 'v2_client_app_scope';

    protected $guarded = ['id'];

    protected $casts = [
        'client_app_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientApp::class, 'client_app_id');
    }

    public function recommendation(): HasOne
    {
        return $this->hasOne(ClientAppRecommendation::class, 'client_app_scope_id');
    }
}
