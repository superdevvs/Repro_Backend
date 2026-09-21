<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceWrapUpOperation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'request_payload' => 'array',
        'response_json' => 'array',
    ];
}
