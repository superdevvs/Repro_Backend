<?php

namespace App\Http\Controllers\API\Voice;

use App\Events\VoiceCallHandoffRequested;
use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceCallStatsService;
use App\Services\TelnyxAi\VoiceIntelligenceService;
use App\Services\Voice\VoiceCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VoiceCallController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = VoiceCall::query()->with(['callerUser:id,name,email', 'callerContact:id,name,email,phone', 'relatedShoot:id,address,status', 'scheduledCallback']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($direction = $request->query('direction')) {
            $query->where('direction', strtoupper((string) $direction));
        }
        if ($disposition = $request->query('disposition')) {
            $query->where('disposition', $disposition);
        }
        if ($intent = $request->query('intent')) {
            $query->where('intent', $intent);
        }
        if ($callbackStatus = $request->query('callback_status')) {
            $query->where('callback_status', $callbackStatus);
        }
        if ($filter = $request->query('filter')) {
            match ($filter) {
                'missed' => $query->whereIn('disposition', ['missed', 'caller_hangup'])->whereNull('summary'),
                'transferred' => $query->where(function ($q): void {
                    $q->where('status', 'transferred')->orWhere('disposition', 'transferred');
                }),
                'callback_needed' => $query->where(function ($q): void {
                    $q->where('callback_status', 'scheduled')->orWhere('disposition', 'callback_needed');
                }),
                'scheduled' => $query->whereNotNull('scheduled_voice_call_id'),
                'resolved' => $query->whereIn('disposition', ['transferred', 'caller_hangup'])->whereNotNull('summary'),
                'unresolved' => $query->where(function ($q): void {
                    $q->whereIn('disposition', ['handoff_to_staff', 'callback_needed'])->orWhereJsonContains('metadata->needs_follow_up', true);
                }),
                default => null,
            };
        }
        if ($request->boolean('verified')) {
            $query->whereNotNull('verified_at');
        }

        return response()->json($query->latest()->paginate((int) $request->query('per_page', 25)));
    }

    public function show(VoiceCall $call): JsonResponse
    {
        return response()->json($call->load(['callerUser:id,name,email,phone,phonenumber', 'callerContact:id,name,email,phone', 'relatedShoot', 'aiChatSession']));
    }

    public function transcript(VoiceCall $call): JsonResponse
    {
        return response()->json(['transcript' => $call->transcript ?? '']);
    }

    /**
     * Marks the cockpit as opened for this call, firing the (debounced)
     * cockpit_opened intelligence trigger, and returns the latest insights.
     */
    public function cockpitOpened(VoiceCall $call, VoiceIntelligenceService $intelligence): JsonResponse
    {
        $insights = $intelligence->onCockpitOpened($call);

        return response()->json([
            'insights' => $insights,
            'budget_paused' => $intelligence->budgetPaused(),
        ]);
    }

    public function recordingUrl(VoiceCall $call): JsonResponse
    {
        if (!$call->recording_url || !$call->recording_consent_given) {
            return response()->json(['url' => null]);
        }

        if (str_starts_with($call->recording_url, 'http')) {
            return response()->json(['url' => $call->recording_url]);
        }

        return response()->json(['url' => Storage::disk('local')->temporaryUrl($call->recording_url, now()->addMinutes(15))]);
    }

    public function stats(Request $request, VoiceCallStatsService $stats): JsonResponse
    {
        return response()->json($stats->stats((string) $request->query('range', '7d')));
    }

    public function outbound(Request $request, VoiceCallService $service): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'from' => ['nullable', 'string', 'max:32'],
            'assistant_id' => ['nullable', 'string', 'max:255'],
            'assistant_mode' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:128'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'vapi_phone_number_id' => ['nullable', 'string', 'max:255'],
            'dynamic_variables' => ['nullable', 'array'],
            'related_shoot_id' => ['nullable', 'integer', 'exists:shoots,id'],
        ]);

        try {
            $call = $service->startOutbound($data, (int) $request->user()->id);
        } catch (\Throwable $exception) {
            \Log::warning('Vapi outbound dial failed', [
                'error' => $exception->getMessage(),
                'to' => $data['to'] ?? null,
            ]);

            return response()->json([
                'message' => 'Unable to start call.',
                'error' => $exception->getMessage(),
            ], 502);
        }

        return response()->json($call, 201);
    }

    public function hangup(VoiceCall $call, TelnyxVoiceCallService $service): JsonResponse
    {
        try {
            $service->hangup($call);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Unable to hang up call.',
                'error' => $exception->getMessage(),
            ], 502);
        }

        return response()->json($call->fresh());
    }

    public function pageStaff(Request $request, VoiceCall $call, ScheduledVoiceCallService $scheduledCalls): JsonResponse
    {
        $scheduled = $scheduledCalls->createCallbackForCall($call, (string) ($request->input('reason') ?: 'staff_page_requested'));
        $metadata = array_merge($call->metadata ?? [], [
            'handoff_requested_at' => now()->toIso8601String(),
            'handoff_reason' => $request->input('reason'),
            'needs_follow_up' => true,
            'scheduled_voice_call_id' => $scheduled->id,
        ]);

        $call->forceFill([
            'disposition' => 'handoff_to_staff',
            'callback_status' => $scheduled->status,
            'scheduled_voice_call_id' => $scheduled->id,
            'metadata' => $metadata,
        ])->save();

        event(new VoiceCallHandoffRequested($call));

        return response()->json($call->fresh());
    }
}
