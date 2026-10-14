<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceVoicemail extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['recording_path'];
    protected $casts = [
        'recipient_ids' => 'array', 'recording_started_at' => 'datetime', 'saved_at' => 'datetime',
        'read_at' => 'datetime', 'resolved_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
