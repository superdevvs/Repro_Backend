<?php

namespace App\Services\TelnyxAi;

use App\Models\ScheduledVoiceCall;
use App\Models\VoiceCall;
use Carbon\CarbonImmutable;

class ScheduledVoiceCallService
{
    public function __construct(private readonly VoiceSettingsService $settings)
    {
    }

    public function createCallbackForCall(VoiceCall $voiceCall, string $reason, ?CarbonImmutable $preferredAt = null): ScheduledVoiceCall
    {
        $target = $voiceCall->from_phone ?: $voiceCall->to_phone;
        $settings = $this->settings->all();
        $scheduledAt = $preferredAt ?: CarbonImmutable::now()->addMinutes((int) ($settings['callback_retry_delay_minutes'] ?? 60));

        $scheduled = ScheduledVoiceCall::query()->firstOrCreate(
            [
                'original_voice_call_id' => $voiceCall->id,
                'reason' => $reason,
                'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
            ],
            [
                'automation_type' => $this->automationTypeFor($reason),
                'target_phone' => (string) $target,
                'from_phone' => $voiceCall->to_phone,
                'caller_user_id' => $voiceCall->caller_user_id,
                'caller_contact_id' => $voiceCall->caller_contact_id,
                'related_shoot_id' => $voiceCall->related_shoot_id,
                'scheduled_at' => $scheduledAt,
                'next_attempt_at' => $scheduledAt,
                'max_attempts' => (int) ($settings['callback_max_attempts'] ?? 3),
                'quiet_hours' => $settings['quiet_hours'] ?? null,
                'summary' => $voiceCall->summary,
                'metadata' => [
                    'source' => 'voice_call',
                    'voice_call_id' => $voiceCall->id,
                    'transcript_excerpt' => $voiceCall->transcript ? mb_substr($voiceCall->transcript, 0, 500) : null,
                ],
            ]
        );

        $voiceCall->forceFill([
            'callback_status' => $scheduled->status,
            'callback_requested_at' => $voiceCall->callback_requested_at ?: now(),
            'preferred_callback_at' => $scheduled->scheduled_at,
            'scheduled_voice_call_id' => $scheduled->id,
            'disposition' => $voiceCall->disposition ?: 'callback_needed',
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'callback_reason' => $reason,
                'scheduled_voice_call_id' => $scheduled->id,
            ]),
        ])->save();

        return $scheduled;
    }

    public function automationEnabled(string $automationType): bool
    {
        $toggles = $this->settings->all()['automation_toggles'] ?? [];

        return (bool) ($toggles[$automationType] ?? false);
    }

    private function automationTypeFor(string $reason): string
    {
        return match ($reason) {
            'missed_call' => 'missed_call_callback',
            'transfer_failed' => 'failed_transfer_callback',
            default => 'missed_call_callback',
        };
    }
}
