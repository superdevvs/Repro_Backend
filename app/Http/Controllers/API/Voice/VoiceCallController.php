<?php

namespace App\Http\Controllers\API\Voice;

use App\Events\VoiceCallHandoffRequested;
use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Services\RolePermissionService;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceCallStatsService;
use App\Services\TelnyxAi\VoiceIntelligenceService;
use App\Services\Voice\VoiceCallService;
use App\Services\Voice\VoiceRecordingService;
use App\Services\Voice\VoiceWrapUpService;
use App\Support\LockedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'missed' => $query->where('direction', 'INBOUND')->whereNull('answered_at')
                    ->where(fn ($q) => $q->whereNotNull('ended_at')->orWhereIn('status', ['missed', 'failed'])),
                'transferred' => $query->where(function ($q): void {
                    $q->where('status', 'transferred')->orWhere('disposition', 'transferred');
                }),
                'callback_needed' => $query->where(function ($q): void {
                    $q->where('callback_status', 'scheduled')->orWhere('disposition', 'callback_needed');
                }),
                'scheduled' => $query->whereNotNull('scheduled_voice_call_id'),
                'resolved' => $query->whereIn('disposition', ['transferred', 'caller_hangup'])->whereNotNull('summary'),
                'unresolved', 'needs_attention' => $query->where(function ($q): void {
                    $q->whereIn('disposition', ['handoff_to_staff', 'callback_needed'])
                        ->orWhere('needs_follow_up', true)
                        ->orWhereJsonContains('metadata->needs_follow_up', true);
                }),
                'voicemail' => $query->where(function ($q): void {
                    $q->where('disposition', 'voicemail')
                        ->orWhere('intent', 'voicemail')
                        ->orWhereJsonContains('metadata->voicemail', true);
                }),
                'live' => $query->whereNull('ended_at')->whereIn('status', ['dialing', 'ringing', 'answered', 'in_progress', 'active', 'ai_active', 'tool_running', 'human_handoff']),
                default => null,
            };
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $like = '%'.$search.'%';
                $q->where('from_phone', 'like', $like)
                    ->orWhere('to_phone', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('transcript', 'like', $like)
                    ->orWhere('intent', 'like', $like)
                    ->orWhere('live_transcript_preview', 'like', $like)
                    ->orWhereHas('callerUser', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('callerContact', function ($contact) use ($like): void {
                        $contact->where('name', 'like', $like)->orWhere('phone', 'like', $like);
                    })
                    ->orWhereHas('relatedShoot', fn ($shoot) => $shoot->where('address', 'like', $like));
            });
        }
        if ($request->boolean('verified')) {
            $query->whereNotNull('verified_at');
        }

        return response()->json($query->latest()->paginate(max(1, min(100, (int) $request->query('per_page', 25)))));
    }

    public function show(VoiceCall $call): JsonResponse
    {
        return response()->json($call->load([
            'callerUser:id,name,email,phone,phonenumber',
            'callerContact:id,name,email,phone',
            'relatedShoot.photographer:id,name',
            'aiChatSession',
            'scheduledCallback',
        ]));
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

    public function recordingUrl(VoiceCall $call, VoiceRecordingService $recordings): JsonResponse
    {
        return response()->json(['url' => $recordings->playbackUrl($call)])->header('Cache-Control', 'private, no-store');
    }

    public function stats(Request $request, VoiceCallStatsService $stats): JsonResponse
    {
        return response()->json($stats->stats((string) $request->query('range', '7d')));
    }

    public function insights(Request $request, VoiceCallStatsService $stats): JsonResponse
    {
        return response()->json($stats->insights((string) $request->query('range', '7d')));
    }

    public function wrapUp(Request $request, VoiceCall $call, VoiceWrapUpService $wrapUp): JsonResponse
    {
        if (! $request->has('idempotency_key') && $request->hasHeader('Idempotency-Key')) {
            $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        }
        $data = $request->validate([
            'recap_title' => ['nullable', 'string', 'max:160'],
            'recap_body' => ['nullable', 'string', 'max:4000'],
            'outcome' => ['nullable', 'string', 'max:120'],
            'create_task' => ['sometimes', 'boolean'],
            'task_title' => ['nullable', 'string', 'max:160'],
            'task_due_at' => ['nullable', 'date'],
            'task_status' => ['nullable', 'in:open,completed'],
            'attach_shoot' => ['sometimes', 'boolean'],
            'send_sms' => ['sometimes', 'boolean'],
            'sms_body' => ['nullable', 'string', 'max:1200'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        if (($data['send_sms'] ?? false) && ! app(RolePermissionService::class)->userCan($request->user(), 'messaging-sms', 'view')) {
            return response()->json(['message' => 'You do not have permission to send SMS messages.'], 403);
        }

        return response()->json($wrapUp->save($call, $request->user(), $data));
    }

    public function addNote(Request $request, VoiceCall $call): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        LockedWrite::run(fn () => DB::transaction(function () use ($request, $call, $data): void {
            $current = $call->fresh();
            $metadata = $current->metadata ?? [];
            $notes = is_array($metadata['notes'] ?? null) ? $metadata['notes'] : [];
            $notes[] = [
                'body' => $data['body'],
                'user_id' => $request->user()?->id,
                'user_name' => $request->user()?->name,
                'at' => now()->toIso8601String(),
            ];
            $metadata['notes'] = $notes;
            $current->forceFill(['metadata' => $metadata])->save();
        }), 'voice.call.add-note');

        return response()->json($call->fresh());
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

        $blockers = $service->outboundBlockers(
            (string) $data['to'],
            isset($data['from']) ? (string) $data['from'] : null,
            isset($data['assistant_id']) ? (string) $data['assistant_id'] : null,
        );
        if ($blockers !== []) {
            return response()->json([
                'message' => 'Outbound voice calling is not ready.',
                'error' => $blockers[0],
                'blockers' => $blockers,
            ], 422);
        }

        try {
            $call = $service->startOutbound($data, (int) $request->user()->id);
        } catch (\Throwable $exception) {
            \App\Services\ApiErrorResponder::log($exception, 'warning');

            return response()->json([
                'message' => 'Unable to start call.',
                'error' => \App\Services\ApiErrorResponder::publicMessage($exception),
            ], 502);
        }

        return response()->json($call, 201);
    }

    public function hangup(VoiceCall $call, TelnyxVoiceCallService $service): JsonResponse
    {
        $call->refresh();
        if ($call->ended_at) {
            return response()->json($call);
        }

        try {
            if (! $service->hangup($call)) {
                return response()->json([
                    'message' => 'The provider did not confirm the hang-up. The call may still be active.',
                    'error' => 'hangup_failed',
                ], 502);
            }
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Unable to hang up call.',
                'error' => \App\Services\ApiErrorResponder::publicMessage($exception),
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
