<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoiceAutomationRule extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['creation_key', 'creation_request_hash'];

    protected $casts = [
        'enabled' => 'boolean', 'conditions' => 'array', 'quiet_hours' => 'array', 'action_config' => 'array',
        'delay_minutes' => 'integer', 'max_attempts' => 'integer', 'retry_delay_minutes' => 'integer',
    ];
}
