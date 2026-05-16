<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceCall extends Model
{
    protected $fillable = [
        'direction',
        'status',
        'disposition',
        'intent',
        'menu_digit',
        'escalation_reason',
        'callback_status',
        'from_phone',
        'to_phone',
        'caller_user_id',
        'caller_contact_id',
        'assistant_id',
        'call_control_id',
        'telnyx_conversation_id',
        'ai_chat_session_id',
        'related_shoot_id',
        'duration_seconds',
        'recording_url',
        'recording_consent_given',
        'transcript',
        'summary',
        'metadata',
        'client_state',
        'scheduled_voice_call_id',
        'last_telnyx_command_status',
        'created_by_user_id',
        'started_at',
        'ended_at',
        'verified_at',
        'callback_requested_at',
        'preferred_callback_at',
    ];

    protected $casts = [
        'recording_consent_given' => 'boolean',
        'metadata' => 'array',
        'last_telnyx_command_status' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'verified_at' => 'datetime',
        'callback_requested_at' => 'datetime',
        'preferred_callback_at' => 'datetime',
    ];

    public function callerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_user_id');
    }

    public function callerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'caller_contact_id');
    }

    public function aiChatSession(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'ai_chat_session_id');
    }

    public function relatedShoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class, 'related_shoot_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scheduledCallback(): BelongsTo
    {
        return $this->belongsTo(ScheduledVoiceCall::class, 'scheduled_voice_call_id');
    }

    public function scheduledCalls(): HasMany
    {
        return $this->hasMany(ScheduledVoiceCall::class, 'original_voice_call_id');
    }
}
