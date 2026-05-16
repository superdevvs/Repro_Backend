<?php

namespace App\Services\TelnyxAi;

use App\Events\VoiceCallHandoffRequested;
use App\Events\VoiceCallTransferred;
use App\Models\VoiceCall;

class VoiceRoutingService
{
    public function __construct(
        private readonly TelnyxVoiceCallService $calls,
        private readonly VoiceSettingsService $settings,
        private readonly ScheduledVoiceCallService $scheduledCalls,
    ) {
    }

    public function beginInboundCall(VoiceCall $voiceCall, array $resolved): VoiceCall
    {
        $voiceCall->forceFill([
            'intent' => $voiceCall->intent ?: 'routing',
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'routing_started_at' => now()->toIso8601String(),
                'caller_identified' => (bool) ($resolved['identified'] ?? false),
            ]),
        ])->save();

        $this->calls->answer($voiceCall);
        $gatherStarted = $this->calls->gatherUsingSpeak($voiceCall);

        if (!$gatherStarted) {
            $this->startAssistant($voiceCall, 'general_support', ['gather_failed' => true], $resolved);
        }

        return $voiceCall->fresh();
    }

    public function routeMenuInput(VoiceCall $voiceCall, ?string $digit, array $resolved = []): VoiceCall
    {
        $digit = trim((string) $digit);

        return match ($digit) {
            '1' => $this->startAssistant($voiceCall, 'booking_or_reschedule', ['menu_digit' => '1'], $resolved),
            '2' => $this->startAssistant($voiceCall, 'shoot_status', ['menu_digit' => '2'], $resolved),
            '3' => $this->startAssistant($voiceCall, 'billing_payment', ['menu_digit' => '3'], $resolved),
            '0' => $this->transferToStaff($voiceCall, 'caller_pressed_0'),
            default => $this->startAssistant($voiceCall, 'general_support', ['menu_digit' => $digit !== '' ? $digit : null], $resolved),
        };
    }

    public function transferToStaff(VoiceCall $voiceCall, string $reason): VoiceCall
    {
        $to = (string) ($this->settings->all()['support_handoff_number'] ?? '');
        $voiceCall->forceFill([
            'intent' => 'human_transfer',
            'menu_digit' => $voiceCall->menu_digit ?: '0',
            'escalation_reason' => $reason,
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'transfer_requested_at' => now()->toIso8601String(),
                'transfer_reason' => $reason,
                'transfer_to' => $to,
            ]),
        ])->save();

        $transferred = $to !== '' && $this->calls->transfer($voiceCall, $to);

        if (!$transferred) {
            return $this->createCallback($voiceCall, 'transfer_failed');
        }

        $voiceCall->forceFill([
            'status' => 'transferred',
            'disposition' => 'transferred',
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'transferred_to' => $to,
                'transferred_at' => now()->toIso8601String(),
            ]),
        ])->save();
        event(new VoiceCallTransferred($voiceCall));

        return $voiceCall->fresh();
    }

    public function createCallback(VoiceCall $voiceCall, string $reason): VoiceCall
    {
        $scheduled = $this->scheduledCalls->createCallbackForCall($voiceCall, $reason);

        $voiceCall->refresh()->forceFill([
            'status' => in_array($voiceCall->status, ['completed', 'transferred'], true) ? $voiceCall->status : 'callback_needed',
            'disposition' => 'callback_needed',
            'escalation_reason' => $reason,
            'callback_status' => $scheduled->status,
            'scheduled_voice_call_id' => $scheduled->id,
        ])->save();

        event(new VoiceCallHandoffRequested($voiceCall->fresh()));

        return $voiceCall->fresh();
    }

    private function startAssistant(VoiceCall $voiceCall, string $intent, array $extraVariables = [], array $resolved = []): VoiceCall
    {
        $voiceCall->forceFill([
            'intent' => $intent,
            'menu_digit' => $extraVariables['menu_digit'] ?? $voiceCall->menu_digit,
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'assistant_started_for_intent' => $intent,
                'assistant_started_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $variables = array_merge(
            $this->calls->buildDynamicVariables($voiceCall, $resolved),
            ['call_intent' => $intent],
            array_filter($extraVariables, static fn ($value) => $value !== null)
        );

        $this->calls->startAssistant($voiceCall, $variables);

        return $voiceCall->fresh();
    }
}
