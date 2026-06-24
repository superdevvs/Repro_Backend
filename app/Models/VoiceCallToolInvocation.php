<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallToolInvocation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DENIED = 'denied';

    protected $fillable = [
        'voice_call_id',
        'tool_name',
        'provider_tool_call_id',
        'status',
        'input_payload',
        'output_payload',
        'requires_confirmation',
        'error_message',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'requires_confirmation' => 'boolean',
    ];

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
