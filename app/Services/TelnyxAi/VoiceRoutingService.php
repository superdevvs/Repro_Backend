<?php

namespace App\Services\TelnyxAi;

use App\Events\VoiceCallHandoffRequested;
use App\Events\VoiceCallTransferred;
use App\Models\VoiceCall;
use RuntimeException;

class VoiceRoutingService
{
    public function __construct(
        private readonly TelnyxVoiceCallService $calls,
        private readonly VoiceSettingsService $settings,
        private readonly ScheduledVoiceCallService $scheduledCalls,
        private readonly VoiceMemoryService $memory,
        private readonly BusinessScheduleService $schedule,
        private readonly VoiceIntelligenceService $intelligence,
        private readonly VoiceNumberSettingsResolver $numbers,
    ) {}

    public function beginInboundCall(VoiceCall $voiceCall, array $resolved): VoiceCall
    {
        if (! $this->numbers->aiEnabledForCall($voiceCall) || ! filled($voiceCall->assistant_id)) {
            return $this->routeWithoutAi($voiceCall, 'voice_ai_unavailable');
        }
        // Memory Tier 1 — instant context loaded before the greeting.
        try {
            $tier1 = $this->memory->loadTier1($voiceCall, $resolved);
        } catch (\Throwable $e) {
            $tier1 = [];
        }

        // Schedule guidance shapes how Robbie phrases human availability.
        $guidance = $this->schedule->robbieScheduleGuidance();

        $voiceCall->forceFill([
            'intent' => $voiceCall->intent ?: 'routing',
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'routing_started_at' => now()->toIso8601String(),
                'caller_identified' => (bool) ($resolved['identified'] ?? false),
                'schedule_state' => $guidance['state'],
                'schedule_message' => $guidance['message'],
            ]),
        ])->save();

        if (! $this->calls->answer($voiceCall)) {
            throw new RuntimeException('The inbound call could not be answered.');
        }
        $gatherStarted = $this->calls->gatherUsingSpeak($voiceCall);

        if (! $gatherStarted) {
            $this->startAssistant($voiceCall, 'general_support', ['gather_failed' => true], $resolved);
        }

        return $voiceCall->fresh();
    }

    public function routeMenuInput(VoiceCall $voiceCall, ?string $digit, array $resolved = []): VoiceCall
    {
        if ($voiceCall->ended_at) {
            return $voiceCall;
        }
        if (! empty($voiceCall->metadata['non_ai_unavailable_at'])) {
            if (! $this->calls->hangup($voiceCall)) {
                throw new RuntimeException('The unavailable call could not be ended.');
            }

            return $voiceCall->fresh();
        }
        if (! $this->numbers->aiEnabledForCall($voiceCall)) {
            return $this->routeWithoutAi($voiceCall);
        }
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

        // Layer 2: transfer-requested is an always-on enrichment trigger.
        try {
            $this->intelligence->onRealtimeUpdate($voiceCall->fresh(), ['transfer_requested' => true]);
        } catch (\Throwable $e) {
            // non-fatal
        }

        $transferred = $to !== '' && $this->calls->transfer($voiceCall, $to);

        if (! $transferred) {
            return $this->createCallback($voiceCall, 'transfer_failed');
        }

        $voiceCall->forceFill([
            'status' => 'human_handoff',
            'disposition' => 'transfer_requested',
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'transfer_destination' => $to,
                'transfer_command_accepted_at' => now()->toIso8601String(),
            ]),
        ])->save();
        event(new VoiceCallTransferred($voiceCall));

        return $voiceCall->fresh();
    }

    public function createCallback(VoiceCall $voiceCall, string $reason): VoiceCall
    {
        if (! $this->numbers->aiEnabledForCall($voiceCall) && ! $this->scheduledCalls->hasCustomRuleForReason($reason)) {
            return $this->routeWithoutAi($voiceCall, $reason);
        }
        $scheduled = $this->scheduledCalls->createCallbackForCall($voiceCall, $reason);
        if (! $scheduled) {
            return $voiceCall->fresh();
        }

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
        if (! $this->numbers->aiEnabledForCall($voiceCall) || ! filled($voiceCall->assistant_id)) {
            return $this->routeWithoutAi($voiceCall, 'voice_ai_unavailable');
        }
        $voiceCall->forceFill([
            'intent' => $intent,
            'menu_digit' => $extraVariables['menu_digit'] ?? $voiceCall->menu_digit,
            'metadata' => array_merge($voiceCall->metadata ?? [], [
                'assistant_started_for_intent' => $intent,
                'assistant_requested_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $variables = array_merge(
            $this->calls->buildDynamicVariables($voiceCall, $resolved),
            ['call_intent' => $intent],
            array_filter($extraVariables, static fn ($value) => $value !== null)
        );

        if (! $this->calls->startAssistant($voiceCall, $variables)) {
            throw new RuntimeException('The voice assistant could not be started.');
        }

        return $voiceCall->fresh();
    }

    /** Route a disabled line without invoking AI or scheduling an AI callback. */
    public function routeWithoutAi(VoiceCall $voiceCall, string $reason = 'voice_ai_disabled', bool $skipBrowser = false): VoiceCall
    {
        $voiceCall->refresh();
        $metadata = $voiceCall->metadata ?? [];
        if (! empty($metadata['non_ai_route_completed_at'])) {
            return $voiceCall;
        }
        $voiceCall->forceFill([
            'handled_by' => null,
            'needs_follow_up' => true,
            'ai_current_state' => 'disabled',
            'metadata' => array_merge($metadata, [
                'needs_follow_up' => true, 'non_ai_routing_reason' => $reason,
                'voice_routing' => array_merge($metadata['voice_routing'] ?? [], ['enabled' => false]),
            ]),
        ])->save();
        if ($voiceCall->ended_at) {
            return $voiceCall->fresh();
        }
        if (! $skipBrowser && app(\App\Services\Voice\VoiceBrowserCallService::class)->offerInbound($voiceCall)) {
            return $voiceCall->fresh();
        }
        if (! $voiceCall->answered_at && ! $this->calls->answer($voiceCall)) {
            throw new RuntimeException('The inbound call could not be answered.');
        }

        $destination = $this->numbers->normalize((string) ($this->settings->all()['support_handoff_number'] ?? ''));
        $localNumber = $this->numbers->forCall($voiceCall)['phone_number'];
        if ($destination !== '' && $destination !== $localNumber) {
            $voiceCall->forceFill(['metadata' => array_merge($voiceCall->fresh()->metadata ?? [], [
                'transfer_requested_at' => now()->toIso8601String(), 'transfer_to' => $destination,
            ])])->save();
            if ($this->calls->transfer($voiceCall, $destination)) {
                $voiceCall->forceFill([
                    'status' => 'human_handoff', 'disposition' => 'transfer_requested',
                    'metadata' => array_merge($voiceCall->fresh()->metadata ?? [], [
                        'transfer_destination' => $destination,
                        'transfer_command_accepted_at' => now()->toIso8601String(),
                        'non_ai_route_completed_at' => now()->toIso8601String(),
                    ]),
                ])->save();

                return $voiceCall->fresh();
            }
        }

        // A static prompt is followed by hang-up on call.gather.ended; hanging
        // up immediately after command acceptance would cut the message off.
        $voiceCall->forceFill([
            'disposition' => 'staff_unavailable',
            'metadata' => array_merge($voiceCall->fresh()->metadata ?? [], [
                'non_ai_unavailable_at' => now()->toIso8601String(),
            ]),
        ])->save();
        if (! $this->calls->gatherUsingSpeak($voiceCall, 'Our team is unavailable right now. Please contact us by text or email. Thank you for calling.')) {
            if (! $this->calls->hangup($voiceCall)) {
                throw new RuntimeException('The unavailable call could not be ended.');
            }
        }
        $voiceCall->forceFill(['metadata' => array_merge($voiceCall->fresh()->metadata ?? [], [
            'non_ai_route_completed_at' => now()->toIso8601String(),
        ])])->save();

        return $voiceCall->fresh();
    }
}
