<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\ScheduledVoiceCall;
use App\Models\TelnyxWebhookEvent;
use App\Models\ToolBridgeInvocation;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Http\JsonResponse;

class VoiceHealthController extends Controller
{
    public function __invoke(VoiceSettingsService $settings): JsonResponse
    {
        $voiceSettings = $settings->all();
        $latestWebhook = TelnyxWebhookEvent::query()
            ->where('provider', 'TELNYX')
            ->where('channel', 'VOICE')
            ->latest('event_received_at')
            ->first();
        $latestFailedWebhook = TelnyxWebhookEvent::query()
            ->where('provider', 'TELNYX')
            ->where('channel', 'VOICE')
            ->whereNotNull('processing_error')
            ->latest('event_received_at')
            ->first();
        $latestCommandCall = VoiceCall::query()
            ->whereNotNull('last_telnyx_command_status')
            ->latest('updated_at')
            ->first();
        $latestFailedTool = ToolBridgeInvocation::query()
            ->where('channel', 'VOICE')
            ->whereNotNull('error_code')
            ->latest('updated_at')
            ->first();

        return response()->json([
            'enabled' => (bool) ($voiceSettings['enabled'] ?? false),
            'webhook_url_configured' => filled($voiceSettings['webhook_url'] ?? null),
            'webhook_url' => $voiceSettings['webhook_url'] ?? null,
            'latest_webhook_event' => $latestWebhook ? [
                'event_type' => $latestWebhook->event_type,
                'received_at' => $latestWebhook->event_received_at?->toIso8601String(),
                'processed_at' => $latestWebhook->processed_at?->toIso8601String(),
                'processing_error' => $latestWebhook->processing_error,
            ] : null,
            'latest_failed_webhook' => $latestFailedWebhook ? [
                'event_type' => $latestFailedWebhook->event_type,
                'received_at' => $latestFailedWebhook->event_received_at?->toIso8601String(),
                'processing_error' => $latestFailedWebhook->processing_error,
            ] : null,
            'latest_command_status' => $latestCommandCall?->last_telnyx_command_status,
            'latest_failed_tool' => $latestFailedTool ? [
                'tool' => $latestFailedTool->tool,
                'status' => $latestFailedTool->status,
                'error_code' => $latestFailedTool->error_code,
                'updated_at' => $latestFailedTool->updated_at?->toIso8601String(),
            ] : null,
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
        ]);
    }
}
