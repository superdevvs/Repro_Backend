<?php

namespace App\Services\Voice;

use App\Models\ScheduledVoiceCall;
use App\Models\TelnyxWebhookEvent;
use App\Models\ToolBridgeInvocation;
use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceCallToolInvocation;
use App\Services\TelnyxAi\VoiceSettingsService;

class VoiceHealthService
{
    public function __construct(private readonly VoiceSettingsService $settings)
    {
    }

    public function summary(): array
    {
        $voiceSettings = $this->settings->all();
        $latestTelnyx = $this->latestEvent('telnyx');
        $latestVapi = $this->latestEvent('vapi');
        $latestFailed = VoiceCallEvent::query()
            ->whereNotNull('processing_error')
            ->latest('received_at')
            ->first();
        $latestCommandCall = VoiceCall::query()
            ->whereNotNull('last_telnyx_command_status')
            ->latest('updated_at')
            ->first();
        $latestFailedTool = $this->latestFailedTool();
        $lastProviderEvent = VoiceCallEvent::query()->latest('received_at')->first()
            ?: TelnyxWebhookEvent::query()->where('channel', 'VOICE')->latest('event_received_at')->first();

        return [
            'enabled' => (bool) ($voiceSettings['enabled'] ?? false),
            'webhook_url_configured' => filled(config('services.vapi.webhook_url')) || filled($voiceSettings['webhook_url'] ?? null),
            'webhook_url' => config('services.vapi.webhook_url') ?: ($voiceSettings['webhook_url'] ?? null),
            'telnyx_carrier' => [
                'status' => config('services.telnyx.api_key') ? 'connected' : 'not_configured',
                'latest_event_at' => $this->eventReceivedAt($latestTelnyx),
                'latest_event_type' => $latestTelnyx?->event_type,
            ],
            'vapi_assistant' => [
                'status' => config('services.vapi.api_key') && config('services.vapi.assistant_id') ? 'online' : 'not_configured',
                'assistant_id' => config('services.vapi.assistant_id'),
                'phone_number_id' => config('services.vapi.phone_number_id'),
                'latest_event_at' => $latestVapi?->received_at?->toIso8601String(),
                'latest_event_type' => $latestVapi?->event_type,
            ],
            'backend_webhooks' => [
                'status' => $latestFailed ? 'degraded' : 'healthy',
                'latest_failed_at' => $latestFailed?->received_at?->toIso8601String(),
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
                'provider' => $latestFailed->provider,
                'received_at' => $latestFailed->received_at?->toIso8601String(),
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

    private function eventReceivedAt(?object $event): ?string
    {
        if (!$event) {
            return null;
        }

        $receivedAt = $event->received_at ?? $event->event_received_at ?? null;

        return $receivedAt?->toIso8601String();
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
