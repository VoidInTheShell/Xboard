<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoticeAcknowledgement extends Model
{
    protected $table = 'v2_notice_acknowledgement';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'notice_id' => 'integer',
        'user_id' => 'integer',
        'notice_revision' => 'integer',
        'acknowledged_at' => 'integer',
    ];
}
