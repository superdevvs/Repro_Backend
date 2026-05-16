<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelnyxWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'channel',
        'telnyx_event_id',
        'event_type',
        'event_received_at',
        'raw_event_json',
        'processed_at',
        'processing_error',
        'related_message_id',
        'related_voice_call_id',
    ];

    protected $casts = [
        'event_received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function relatedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'related_message_id');
    }

    public function relatedVoiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'related_voice_call_id');
    }
}
