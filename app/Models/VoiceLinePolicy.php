<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceLinePolicy extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['recipient_ids' => 'array', 'weekly_hours' => 'array'];
}
