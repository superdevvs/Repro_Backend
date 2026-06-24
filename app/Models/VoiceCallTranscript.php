<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallTranscript extends Model
{
    protected $fillable = [
        'voice_call_id',
        'provider_message_id',
        'speaker',
        'transcript_type',
        'text',
        'confidence',
        'occurred_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'occurred_at' => 'datetime',
    ];

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
