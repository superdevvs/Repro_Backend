<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceFollowUpTask extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
