<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reschedule requests.
 *
 * A1.docx item 4: the client UI said "Request to reschedule" but this controller
 * created every row `approved` and moved the shoot immediately, so a client
 * silently rescheduled their own shoot. Submission and application are now
 * separate steps:
 *
 *  - a request-only actor (client, or anyone without direct reschedule rights)
 *    creates a `pending` row and the shoot is untouched;
 *  - staff who already had direct reschedule rights keep them, and their
 *    submission is approved and applied in one step exactly as before;
 *  - approval applies the change once, guarded by `applied_at`;
 *  - rejection records why and leaves the shoot alone.
 */
class ShootRescheduleRequestController extends Controller
{
    public function __construct(protected ShootAuthorizationSupport $authorization) {}

    /**
     * Roles allowed to reschedule a shoot outright, and to review other
     * people's requests. Mirrors the `role:` middleware on the updateStatus
     * route so the two cannot drift apart.
     */
    private const STAFF_ROLES = ['admin', 'superadmin', 'editing_manager'];

    public function index(Shoot $shoot)
    {
        $this->authorization->ensureShootAccess($shoot, auth()->user());
        $requests = $shoot->rescheduleRequests()
            ->with(['requester:id,name,avatar', 'approver:id,name,avatar'])->latest()->get();

        return response()->json([
            'data' => $requests,
        ]);
    }

    public function store(Request $request, Shoot $shoot)
    {
        abort_unless($this->authorization->canSubmitShootRequest($shoot, $request->user()), 403, 'Forbidden');
        $validated = $request->validate([
            'requested_date' => 'required|date',
            'requested_time' => 'nullable|string|max:25',
            'reason' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        $canApplyDirectly = $this->userCanReviewRequests($user);

        $record = ShootRescheduleRequest::create([
            'shoot_id' => $shoot->id,
            'requested_by' => $user?->id,
            // Snapshot what is currently confirmed, so the requested values are
            // never confused with the live ones.
            'original_date' => $shoot->scheduled_date,
            'original_time' => $shoot->time,
            'requested_date' => $validated['requested_date'],
            'requested_time' => $validated['requested_time'] ?? $shoot->time,
            'reason' => $validated['reason'] ?? null,
            'status' => $canApplyDirectly
                ? ShootRescheduleRequest::STATUS_APPROVED
                : ShootRescheduleRequest::STATUS_PENDING,
            'reviewed_at' => $canApplyDirectly ? now() : null,
            'approved_by' => $canApplyDirectly ? $user?->id : null,
        ]);

        // A pending request must not move the shoot. That was the bug.
        if ($canApplyDirectly) {
            $this->applyScheduleChanges($shoot, $record);
        } else {
            $this->logRequestSubmitted($shoot, $record);
        }

        return response()->json([
            'message' => $canApplyDirectly
                ? 'Shoot rescheduled successfully.'
                : 'Reschedule request submitted for review.',
            'applied' => $canApplyDirectly,
            'data' => $record->fresh(['requester:id,name,avatar', 'approver:id,name,avatar']),
        ], 201);
    }

    public function updateStatus(Request $request, ShootRescheduleRequest $rescheduleRequest)
    {
        $this->authorizeReviewer($request);
        $this->authorization->ensureShootAccess($rescheduleRequest->shoot, $request->user());

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'review_notes' => 'nullable|string|max:2000',
        ]);

        // Idempotency: an already-applied request is a no-op rather than an
        // error, so a double-click or a retried request cannot move the shoot
        // twice or re-send notifications.
        if (
            $validated['status'] === ShootRescheduleRequest::STATUS_APPROVED
            && $rescheduleRequest->hasBeenApplied()
        ) {
            return response()->json([
                'message' => 'Reschedule request was already approved.',
                'applied' => false,
                'already_applied' => true,
                'data' => $rescheduleRequest->fresh(['shoot', 'requester', 'approver']),
            ]);
        }

        // A decided request is final. Re-deciding it the other way would either
        // un-apply a change that already happened or apply a stale date.
        if (! $rescheduleRequest->isPending()) {
            return response()->json([
                'message' => 'This reschedule request has already been reviewed.',
                'status' => $rescheduleRequest->status,
            ], 409);
        }

        $applied = false;

        DB::beginTransaction();

        try {
            $rescheduleRequest->status = $validated['status'];
            $rescheduleRequest->reviewed_at = now();
            $rescheduleRequest->approved_by = $request->user()->id;
            $rescheduleRequest->review_notes = $validated['review_notes'] ?? null;
            $rescheduleRequest->save();

            if ($validated['status'] === ShootRescheduleRequest::STATUS_APPROVED) {
                $shoot = $rescheduleRequest->shoot;

                if (! $shoot) {
                    throw new \RuntimeException('Reschedule request is not linked to a shoot.');
                }

                $this->applyScheduleChanges($shoot, $rescheduleRequest);
                $applied = true;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Unable to update reschedule request.',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }

        return response()->json([
            'message' => $applied
                ? 'Reschedule request approved and applied.'
                : 'Reschedule request rejected. The shoot was left unchanged.',
            'applied' => $applied,
            'data' => $rescheduleRequest->fresh(['shoot', 'requester', 'approver']),
        ]);
    }

    private function applyScheduleChanges(Shoot $shoot, ShootRescheduleRequest $request): void
    {
        // Second line of defence for idempotency: whichever path calls this, the
        // change is written at most once per request row.
        if ($request->hasBeenApplied()) {
            return;
        }

        $mailService = app(MailService::class);
        $shoot->loadMissing('services');
        $beforeSnapshot = $mailService->captureShootSnapshot($shoot);

        $shoot->scheduled_date = $request->requested_date;
        if (!empty($request->requested_time)) {
            $shoot->time = $request->requested_time;
        }

        $timeStr = $request->requested_time ?? $shoot->time ?? '10:00';
        $timeParsed = date_parse($timeStr);
        $hours = $timeParsed['hour'] ?? 10;
        $minutes = $timeParsed['minute'] ?? 0;

        $scheduledAt = \Carbon\Carbon::parse($request->requested_date)
            ->setTime($hours, $minutes, 0);
        $shoot->scheduled_at = $scheduledAt;

        $shoot->save();

        // Mark applied before notifying: if a notification throws, the shoot has
        // still moved, and a retry must not move it again.
        $request->applied_at = now();
        if ($request->status !== ShootRescheduleRequest::STATUS_APPROVED) {
            $request->status = ShootRescheduleRequest::STATUS_APPROVED;
        }
        $request->save();

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service', 'services']);
        $automationService = app(AutomationService::class);
        $context = $automationService->buildShootContext($shoot);
        if ($shoot->rep) {
            $context['rep'] = $shoot->rep;
        }
        $context['scheduled_at'] = $shoot->scheduled_at?->toISOString();

        $shootChangeSummary = $mailService->buildShootChangeSummary($beforeSnapshot, $shoot);
        $changesSummary = $shootChangeSummary['summary'];
        $context['shoot_changes'] = $changesSummary;
        $context['shoot_changes_html'] = $shootChangeSummary['html'];
        $automationService->handleEvent('SHOOT_SCHEDULED', $context);
        $shootUpdatedDispatch = $automationService->handleEvent('SHOOT_UPDATED', $context);

        if ($shoot->client && $automationService->shouldUseFallback('SHOOT_UPDATED', $shootUpdatedDispatch) !== false) {
            $mailService->sendShootUpdatedEmail($shoot->client, $shoot, $changesSummary);
        }

        $this->logRescheduleActivity($shoot, $request);
    }

    /**
     * Record that a request was raised, so staff see it in the activity trail
     * even though nothing about the shoot changed yet.
     */
    private function logRequestSubmitted(Shoot $shoot, ShootRescheduleRequest $request): void
    {
        try {
            $requesterName = $request->requester?->name ?? 'A client';
            $requestedDate = \Carbon\Carbon::parse($request->requested_date)->format('M j, Y');

            \App\Models\ShootActivityLog::create([
                'shoot_id' => $shoot->id,
                'user_id' => $request->requested_by,
                'action' => 'reschedule_requested',
                'description' => "{$requesterName} requested a reschedule to {$requestedDate} (awaiting review)",
                'metadata' => [
                    'reschedule_request_id' => $request->id,
                    'original_date' => $request->original_date,
                    'original_time' => $request->original_time,
                    'requested_date' => $request->requested_date,
                    'requested_time' => $request->requested_time,
                    'reason' => $request->reason,
                    'status' => $request->status,
                ],
            ]);
        } catch (\Throwable $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');
        }
    }

    private function logRescheduleActivity(Shoot $shoot, ShootRescheduleRequest $request): void
    {
        try {
            $requester = $request->requester;
            $requesterName = $requester ? $requester->name : 'System';

            $originalDate = $request->original_date
                ? \Carbon\Carbon::parse($request->original_date)->format('M j, Y')
                : 'Unknown';
            $newDate = \Carbon\Carbon::parse($request->requested_date)->format('M j, Y');
            $newTime = $request->requested_time ?? 'same time';

            \App\Models\ShootActivityLog::create([
                'shoot_id' => $shoot->id,
                'user_id' => $request->requested_by,
                'action' => 'rescheduled',
                'description' => "{$requesterName} rescheduled shoot from {$originalDate} to {$newDate} at {$newTime}",
                'metadata' => [
                    'reschedule_request_id' => $request->id,
                    'original_date' => $request->original_date,
                    'new_date' => $request->requested_date,
                    'new_time' => $request->requested_time,
                    'reason' => $request->reason,
                ],
            ]);
        } catch (\Throwable $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');
        }
    }

    /**
     * Whether this user may reschedule directly and review others' requests.
     *
     * Unchanged for staff: admin and superadmin behaved this way before. The
     * route middleware for review already admitted `editing_manager`, while this
     * check did not, so an editing manager received a 403 from an endpoint they
     * were routed to. Aligned here rather than leaving the two disagreeing.
     */
    private function userCanReviewRequests(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));

        if (in_array($role, self::STAFF_ROLES, true)) {
            return true;
        }

        // Some staff carry their privileges as secondary roles.
        $secondary = $user->secondary_roles;
        if (is_array($secondary)) {
            foreach ($secondary as $secondaryRole) {
                if (in_array(strtolower((string) $secondaryRole), self::STAFF_ROLES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function authorizeReviewer(Request $request): void
    {
        if (!$this->userCanReviewRequests($request->user())) {
            abort(403, 'Only staff can review reschedule requests.');
        }
    }
}
