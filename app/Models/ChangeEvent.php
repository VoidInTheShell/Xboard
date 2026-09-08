<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChangeEvent extends Model
{
    protected $table = 'v2_change_event';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'version' => 'integer',
        'created_at' => 'timestamp',
    ];
}
