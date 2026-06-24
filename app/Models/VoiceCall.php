<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceCall extends Model
{
    protected $fillable = [
        'provider',
        'vapi_call_id',
        'vapi_phone_number_id',
        'direction',
        'status',
        'handled_by',
        'disposition',
        'intent',
        'menu_digit',
        'escalation_reason',
        'callback_status',
        'external_provider_status',
        'provider_event_last_seen_at',
        'vapi_ended_reason',
        'telnyx_failure_code',
        'carrier_failure_reason',
        'ai_current_state',
        'ai_current_speaker',
        'live_transcript_preview',
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
        'recording_provider',
        'recording_consent_given',
        'transcript',
        'summary',
        'sentiment',
        'booking_probability',
        'needs_follow_up',
        'summary_generated_at',
        'metadata',
        'client_state',
        'scheduled_voice_call_id',
        'last_telnyx_command_status',
        'created_by_user_id',
        'started_at',
        'answered_at',
        'ended_at',
        'verified_at',
        'callback_requested_at',
        'preferred_callback_at',
    ];

    protected $casts = [
        'recording_consent_given' => 'boolean',
        'needs_follow_up' => 'boolean',
        'metadata' => 'array',
        'last_telnyx_command_status' => 'array',
        'provider_event_last_seen_at' => 'datetime',
        'summary_generated_at' => 'datetime',
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(VoiceCallEvent::class);
    }

    public function transcriptRows(): HasMany
    {
        return $this->hasMany(VoiceCallTranscript::class);
    }

    public function toolInvocations(): HasMany
    {
        return $this->hasMany(VoiceCallToolInvocation::class);
    }
}
