<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAssistantAction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['proposal', 'result', 'request_hash', 'scope_hash', 'target_version'];

    protected $casts = [
        'proposal' => 'encrypted:array', 'result' => 'encrypted:array',
        'expires_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];
}
