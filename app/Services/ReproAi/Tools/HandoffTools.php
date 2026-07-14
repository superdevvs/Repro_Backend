<?php

namespace App\Services\ReproAi\Tools;

use App\Events\VoiceCallHandoffRequested;
use App\Events\VoiceCallTransferred;
use App\Models\MessageThread;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceSettingsService;

class HandoffTools
{
    public function __construct(
        private readonly ScheduledVoiceCallService $scheduledCalls,
        private readonly TelnyxVoiceCallService $telnyxCalls,
        private readonly VoiceSettingsService $settings,
    ) {}

    public function handoffToStaff(array $params, array $context): array
    {
        $threadId = $context['sms_thread_id'] ?? $params['thread_id'] ?? null;
        $voiceCallId = $context['voice_call_id'] ?? $params['voice_call_id'] ?? null;

        if ($threadId) {
            $thread = MessageThread::query()->find($threadId);
            if ($thread) {
                $pauseMinutes = (int) config('services.telnyx.ai_takeover_pause_minutes', 120);
                $thread->forceFill([
                    'ai_paused_until' => now()->addMinutes(max(1, $pauseMinutes)),
                    'metadata' => array_merge($thread->metadata ?? [], [
                        'handoff_requested_at' => now()->toIso8601String(),
                        'handoff_reason' => $params['reason'] ?? null,
                    ]),
                ])->save();
            }
        }

        if ($voiceCallId) {
            $voiceCall = VoiceCall::query()->find($voiceCallId);
            if ($voiceCall) {
                $scheduled = $this->scheduledCalls->createCallbackForCall(
                    $voiceCall,
                    (string) ($params['reason'] ?? 'ai_handoff_requested')
                );
                $voiceCall->forceFill([
                    'disposition' => 'handoff_to_staff',
                    'callback_status' => $scheduled->status,
                    'scheduled_voice_call_id' => $scheduled->id,
                    'escalation_reason' => $params['reason'] ?? 'ai_handoff_requested',
                    'metadata' => array_merge($voiceCall->metadata ?? [], [
                        'handoff_requested_at' => now()->toIso8601String(),
                        'handoff_reason' => $params['reason'] ?? null,
                        'scheduled_voice_call_id' => $scheduled->id,
                    ]),
                ])->save();
                event(new VoiceCallHandoffRequested($voiceCall));
            }
        }

        return [
            'ok' => true,
            'result' => [
                'handoff_requested' => true,
                'thread_id' => $threadId,
                'voice_call_id' => $voiceCallId,
            ],
        ];
    }

    public function transferToStaff(array $params, array $context): array
    {
        if (strtoupper((string) ($context['channel'] ?? '')) !== 'VOICE') {
            return ['ok' => false, 'error' => 'tool_blocked'];
        }

        $callControlId = (string) ($context['call_control_id'] ?? '');
        $to = (string) ($this->settings->all()['support_handoff_number'] ?? '');

        if ($callControlId === '' || $to === '') {
            return ['ok' => false, 'error' => 'missing_transfer_target'];
        }

        $voiceCall = VoiceCall::query()
            ->where('call_control_id', $callControlId)
            ->first();

        if (! $voiceCall || (int) $voiceCall->id !== (int) ($context['voice_call_id'] ?? 0)) {
            return ['ok' => false, 'error' => 'trusted_call_not_found'];
        }

        $transferOk = $this->telnyxCalls->transfer($voiceCall, $to);
        $transferStatus = $voiceCall->fresh()->last_telnyx_command_status['status'] ?? null;

        if ($voiceCall) {
            if (! $transferOk) {
                $scheduled = $this->scheduledCalls->createCallbackForCall($voiceCall, 'transfer_failed');
                $voiceCall->forceFill([
                    'status' => 'callback_needed',
                    'disposition' => 'callback_needed',
                    'callback_status' => $scheduled->status,
                    'scheduled_voice_call_id' => $scheduled->id,
                    'last_telnyx_command_status' => [
                        'action' => 'transfer',
                        'ok' => false,
                        'status' => $transferStatus,
                        'at' => now()->toIso8601String(),
                    ],
                ])->save();

                return ['ok' => false, 'error' => 'transfer_failed', 'scheduled_voice_call_id' => $scheduled->id];
            }

            $voiceCall->forceFill([
                'status' => 'transferred',
                'disposition' => 'transferred',
                'last_telnyx_command_status' => [
                    'action' => 'transfer',
                    'ok' => true,
                    'status' => $transferStatus,
                    'at' => now()->toIso8601String(),
                ],
                'metadata' => array_merge($voiceCall->metadata ?? [], [
                    'transferred_to' => $to,
                    'transferred_at' => now()->toIso8601String(),
                ]),
            ])->save();
            event(new VoiceCallTransferred($voiceCall));
        }

        return [
            'ok' => true,
            'result' => [
                'transferred' => true,
                'to' => $to,
                'call_control_id' => $callControlId,
            ],
        ];
    }
}
