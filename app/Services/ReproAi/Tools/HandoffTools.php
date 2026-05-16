<?php

namespace App\Services\ReproAi\Tools;

use App\Events\VoiceCallHandoffRequested;
use App\Events\VoiceCallTransferred;
use App\Models\MessageThread;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use Illuminate\Support\Facades\Http;

class HandoffTools
{
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
                $scheduled = app(ScheduledVoiceCallService::class)->createCallbackForCall(
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

        $callControlId = (string) ($context['call_control_id'] ?? $params['call_control_id'] ?? '');
        $to = (string) ($params['to'] ?? config('services.telnyx.voice.support_handoff_number', ''));

        if ($callControlId === '' || $to === '') {
            return ['ok' => false, 'error' => 'missing_transfer_target'];
        }

        $apiKey = (string) config('services.telnyx.api_key', '');
        $base = rtrim((string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2'), '/');

        $transferOk = true;
        $transferStatus = null;
        if ($apiKey !== '') {
            $response = Http::withToken($apiKey)->post("{$base}/calls/{$callControlId}/actions/transfer", [
                'to' => $to,
            ]);
            $transferOk = $response->successful();
            $transferStatus = $response->status();
        }

        $voiceCall = VoiceCall::query()
            ->where('call_control_id', $callControlId)
            ->orWhere('id', $context['voice_call_id'] ?? $params['voice_call_id'] ?? null)
            ->first();

        if ($voiceCall) {
            if (!$transferOk) {
                $scheduled = app(ScheduledVoiceCallService::class)->createCallbackForCall($voiceCall, 'transfer_failed');
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
