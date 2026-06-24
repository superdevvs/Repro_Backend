<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallEvent extends Model
{
    protected $fillable = [
        'voice_call_id',
        'provider',
        'event_type',
        'normalized_type',
        'raw_payload',
        'received_at',
        'processed_at',
        'idempotency_key',
        'processing_error',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
