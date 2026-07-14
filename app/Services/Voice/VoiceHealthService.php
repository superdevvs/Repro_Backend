<?php

namespace App\Services\Voice;

use App\Models\ScheduledVoiceCall;
use App\Models\TelnyxWebhookEvent;
use App\Models\ToolBridgeInvocation;
use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceCallToolInvocation;
use App\Services\TelnyxAi\TelnyxAssistantSyncService;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceSettingsService;
use Throwable;

class VoiceHealthService
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly TelnyxVoiceCallService $telnyxCalls,
        private readonly TelnyxAssistantSyncService $assistantSync,
    ) {}

    public function summary(): array
    {
        $voiceSettings = $this->settings->all();
        $provider = strtolower((string) config('services.voice.provider', 'telnyx'));
        $latestTelnyx = $this->latestEvent('telnyx');
        $latestVapi = $this->latestEvent('vapi');
        $latestFailed = $this->latestFailedWebhook();
        $latestCommandCall = VoiceCall::query()
            ->whereNotNull('last_telnyx_command_status')
            ->latest('updated_at')
            ->first();
        $latestFailedTool = $this->latestFailedTool();
        $lastProviderEvent = VoiceCallEvent::query()->latest('received_at')->first()
            ?: TelnyxWebhookEvent::query()->where('channel', 'VOICE')->latest('event_received_at')->first();

        $assistant = null;
        $assistantError = null;
        if ($provider === 'telnyx' && config('services.telnyx.api_key') && ($voiceSettings['assistant_id'] ?? null)) {
            try {
                $assistant = $this->assistantSync->inspect();
            } catch (Throwable $exception) {
                $assistantError = $exception->getMessage();
                $assistant = [
                    'status' => 'unreachable',
                    'assistant_id' => $voiceSettings['assistant_id'] ?? null,
                    'missing_tools' => [],
                    'extra_webhook_tools' => [],
                    'canary_route_status' => (bool) config('services.voice.canary_mode', true) ? 'unknown' : 'not_required',
                ];
            }
        }

        $blockers = $provider === 'telnyx'
            ? $this->telnyxCalls->outboundBlockers()
            : $this->vapiBlockers();
        if ($provider === 'telnyx' && $assistantError) {
            $blockers[] = 'The configured Telnyx assistant could not be inspected.';
        } elseif ($provider === 'telnyx' && ($assistant['status'] ?? null) !== 'synced') {
            $blockers[] = 'The Telnyx assistant tools, policy, or recording settings are not synchronized.';
        }
        if (
            $provider === 'telnyx'
            && (bool) config('services.voice.canary_mode', true)
            && ($assistant['canary_route_status'] ?? null) !== 'routed'
        ) {
            $blockers[] = 'The allowlisted canary numbers are not routed to a synchronized assistant version.';
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'provider' => $provider,
            'enabled' => (bool) ($voiceSettings['enabled'] ?? false),
            'can_place_calls' => $blockers === [],
            'readiness_blockers' => $blockers,
            'canary_mode' => (bool) config('services.voice.canary_mode', true),
            'canary_number_count' => count((array) config('services.voice.canary_numbers', [])),
            'webhook_url_configured' => filled($voiceSettings['webhook_url'] ?? null),
            'webhook_url' => $voiceSettings['webhook_url'] ?? null,
            'telnyx_carrier' => [
                'status' => config('services.telnyx.api_key') && config('services.telnyx.voice.connection_id') ? 'connected' : 'not_configured',
                'latest_event_at' => $this->eventReceivedAt($latestTelnyx),
                'latest_event_type' => $latestTelnyx?->event_type,
            ],
            'telnyx_assistant' => [
                'status' => $assistant['status'] ?? 'not_configured',
                'assistant_id' => $voiceSettings['assistant_id'] ?? null,
                'version_id' => $assistant['version_id'] ?? null,
                'canary_version_id' => $assistant['canary_version_id'] ?? null,
                'canary_route_status' => $assistant['canary_route_status'] ?? 'unknown',
                'error' => $assistantError,
            ],
            'assistant_sync' => $assistant,
            'vapi_assistant' => $provider === 'vapi' ? [
                'status' => config('services.vapi.api_key') && config('services.vapi.assistant_id') ? 'online' : 'not_configured',
                'assistant_id' => config('services.vapi.assistant_id'),
                'phone_number_id' => config('services.vapi.phone_number_id'),
                'latest_event_at' => $latestVapi?->received_at?->toIso8601String(),
                'latest_event_type' => $latestVapi?->event_type,
            ] : null,
            'backend_webhooks' => [
                'status' => $this->webhookStatus($latestFailed, $lastProviderEvent),
                'latest_failed_at' => $this->eventReceivedAt($latestFailed),
                'latest_failed_error' => $latestFailed?->processing_error,
            ],
            'last_provider_event_at' => $this->eventReceivedAt($lastProviderEvent),
            'latest_webhook_event' => $lastProviderEvent ? [
                'event_type' => $lastProviderEvent->event_type,
                'provider' => strtolower((string) $lastProviderEvent->provider),
                'received_at' => $this->eventReceivedAt($lastProviderEvent),
                'processed_at' => $lastProviderEvent->processed_at?->toIso8601String(),
                'processing_error' => $lastProviderEvent->processing_error,
            ] : null,
            'latest_failed_webhook' => $latestFailed ? [
                'event_type' => $latestFailed->event_type,
                'provider' => strtolower((string) $latestFailed->provider),
                'received_at' => $this->eventReceivedAt($latestFailed),
                'processing_error' => $latestFailed->processing_error,
            ] : null,
            'latest_command_status' => $latestCommandCall?->last_telnyx_command_status,
            'latest_failed_tool' => $latestFailedTool,
            'scheduler' => [
                'due_scheduled_calls' => ScheduledVoiceCall::query()
                    ->whereIn('status', [
                        ScheduledVoiceCall::STATUS_SCHEDULED,
                        ScheduledVoiceCall::STATUS_DEFERRED,
                        ScheduledVoiceCall::STATUS_FAILED,
                    ])
                    ->where('next_attempt_at', '<=', now())
                    ->count(),
            ],
        ];
    }

    private function latestEvent(string $provider): ?object
    {
        $event = VoiceCallEvent::query()->where('provider', $provider)->latest('received_at')->first();
        if ($event || $provider !== 'telnyx') {
            return $event;
        }

        return TelnyxWebhookEvent::query()->where('provider', 'TELNYX')->where('channel', 'VOICE')->latest('event_received_at')->first();
    }

    private function latestFailedWebhook(): ?object
    {
        $normalized = VoiceCallEvent::query()->whereNotNull('processing_error')->latest('received_at')->first();
        $legacy = TelnyxWebhookEvent::query()->where('channel', 'VOICE')->whereNotNull('processing_error')->latest('event_received_at')->first();
        if (! $normalized) {
            return $legacy;
        }
        if (! $legacy) {
            return $normalized;
        }

        return ($normalized->received_at?->timestamp ?? 0) >= ($legacy->event_received_at?->timestamp ?? 0) ? $normalized : $legacy;
    }

    private function eventReceivedAt(?object $event): ?string
    {
        if (! $event) {
            return null;
        }

        $receivedAt = $event->received_at ?? $event->event_received_at ?? null;

        return $receivedAt?->toIso8601String();
    }

    private function webhookStatus(?object $failed, ?object $latest): string
    {
        if (! $failed) {
            return 'healthy';
        }
        $failedAt = $failed->received_at ?? $failed->event_received_at ?? null;
        $latestAt = $latest->received_at ?? $latest->event_received_at ?? null;

        return $latestAt && $failedAt && $latestAt->greaterThan($failedAt) ? 'healthy' : 'degraded';
    }

    /** @return list<string> */
    private function vapiBlockers(): array
    {
        return array_values(array_filter([
            config('services.vapi.api_key') ? null : 'VAPI_API_KEY is not configured.',
            config('services.vapi.assistant_id') ? null : 'VAPI_ASSISTANT_ID is not configured.',
            config('services.vapi.phone_number_id') ? null : 'VAPI_PHONE_NUMBER_ID is not configured.',
        ]));
    }

    private function latestFailedTool(): ?array
    {
        $newInvocation = VoiceCallToolInvocation::query()
            ->where('status', VoiceCallToolInvocation::STATUS_FAILED)
            ->latest('updated_at')
            ->first();

        if ($newInvocation) {
            return [
                'tool' => $newInvocation->tool_name,
                'status' => $newInvocation->status,
                'error_code' => $newInvocation->error_message,
                'updated_at' => $newInvocation->updated_at?->toIso8601String(),
            ];
        }

        $legacy = ToolBridgeInvocation::query()
            ->where('channel', 'VOICE')
            ->whereNotNull('error_code')
            ->latest('updated_at')
            ->first();

        return $legacy ? [
            'tool' => $legacy->tool,
            'status' => $legacy->status,
            'error_code' => $legacy->error_code,
            'updated_at' => $legacy->updated_at?->toIso8601String(),
        ] : null;
    }
}
