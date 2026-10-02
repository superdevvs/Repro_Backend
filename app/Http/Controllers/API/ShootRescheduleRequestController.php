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

    public function index(Shoot $shoot)
    {
        abort_unless($this->authorization->canViewShootRequests($shoot, auth()->user()), 403, 'Forbidden');
        $requests = $shoot->rescheduleRequests()
            ->with(['requester:id,name,avatar', 'approver:id,name,avatar'])->latest()->get();

        return response()->json([
            'data' => $requests,
        ]);
    }


    /**
     * Office/dashboard queue of reschedule requests (pending + recent decided).
     *
     * Mirrors pending-cancellations / pending-holds: a flat staff-facing list so
     * Dashboard → Requests can surface actionable rows and recent rejected/approved
     * context without opening each shoot Overview.
     *
     * Query:
     *  - default / status=all / include=recent → all pending, then latest decided
     *    (approved|rejected) within {@see self::RECENT_DECIDED_DAYS} days, capped at
     *    {@see self::RECENT_DECIDED_LIMIT}
     *  - status=pending|approved|rejected → that status only (decided still recent-capped)
     */
    private const RECENT_DECIDED_DAYS = 30;

    private const RECENT_DECIDED_LIMIT = 50;

    public function pendingReschedules(Request $request)
    {
        $this->authorizeReviewer($request);

        $user = $request->user();
        $accessibleShootIds = $this->authorization
            ->scopeAccessibleShootRequests(Shoot::query(), $user)
            ->select('shoots.id');

        // Default → pending + recent decided (Overview-like context for Dashboard).
        // ?status=pending|approved|rejected|all narrows. ?include=recent is an
        // alias for the default when status is omitted (explicit status wins).
        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status === '') {
            $status = 'all';
        }

        if (! in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            return response()->json([
                'message' => 'Invalid status filter. Use pending, approved, rejected, or all.',
            ], 422);
        }

        $eager = [
            'requester:id,name,avatar',
            'approver:id,name,avatar',
            'shoot:id,address,city,state,zip,client_id,scheduled_date,time,timezone,status',
            'shoot.client:id,name',
        ];

        $base = ShootRescheduleRequest::query()
            ->whereIn('shoot_id', $accessibleShootIds)
            ->with($eager);

        if ($status === 'pending') {
            $rows = (clone $base)->pending()->latest('id')->get();
        } elseif ($status === 'all') {
            $pending = (clone $base)->pending()->latest('id')->get();
            $decided = $this->recentDecidedQuery(clone $base)->get();
            $rows = $pending->concat($decided)->values();
        } else {
            $rows = $this->recentDecidedQuery(
                (clone $base)->where('status', $status),
                restrictToDecided: false
            )->get();
        }

        $requests = $rows
            ->map(fn (ShootRescheduleRequest $row) => $this->mapOfficeRescheduleRow($row))
            ->values();

        return response()->json([
            'data' => $requests,
        ]);
    }

    /**
     * Recent decided rows for the office list (30 days, max 50).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  bool  $restrictToDecided  when true, limit to approved|rejected
     */
    private function recentDecidedQuery($query, bool $restrictToDecided = true)
    {
        $since = now()->subDays(self::RECENT_DECIDED_DAYS);

        if ($restrictToDecided) {
            $query->whereIn('status', [
                ShootRescheduleRequest::STATUS_APPROVED,
                ShootRescheduleRequest::STATUS_REJECTED,
            ]);
        }

        return $query
            ->where(function ($q) use ($since) {
                $q->where('reviewed_at', '>=', $since)
                    ->orWhere(function ($inner) use ($since) {
                        $inner->whereNull('reviewed_at')
                            ->where('created_at', '>=', $since);
                    });
            })
            ->orderByRaw('COALESCE(reviewed_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(self::RECENT_DECIDED_LIMIT);
    }

    /**
     * Flatten a reschedule row for Dashboard → Requests (same shape for pending + decided).
     */
    private function mapOfficeRescheduleRow(ShootRescheduleRequest $row): array
    {
        $shoot = $row->shoot;
        $address = $shoot?->address;
        $fullAddress = null;
        if ($shoot) {
            $parts = array_filter([
                $shoot->address,
                $shoot->city,
                trim(implode(' ', array_filter([$shoot->state, $shoot->zip]))),
            ]);
            $fullAddress = $parts ? implode(', ', $parts) : null;
        }

        return [
            'id' => $row->id,
            'shoot_id' => $row->shoot_id,
            'status' => $row->status,
            'original_date' => $row->original_date?->toDateString(),
            'original_time' => $row->original_time,
            'requested_date' => $row->requested_date?->toDateString(),
            'requested_time' => $row->requested_time,
            'reason' => $row->reason,
            'review_notes' => $row->review_notes,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'requester' => $row->requester
                ? [
                    'id' => $row->requester->id,
                    'name' => $row->requester->name,
                ]
                : null,
            'approver' => $row->approver
                ? [
                    'id' => $row->approver->id,
                    'name' => $row->approver->name,
                ]
                : null,
            'client' => $shoot?->client
                ? [
                    'id' => $shoot->client->id,
                    'name' => $shoot->client->name,
                ]
                : null,
            'client_name' => $shoot?->client?->name,
            'shoot' => $shoot
                ? [
                    'id' => $shoot->id,
                    'address' => $address,
                    'location' => [
                        'address' => $shoot->address,
                        'city' => $shoot->city,
                        'state' => $shoot->state,
                        'zip' => $shoot->zip,
                        'fullAddress' => $fullAddress,
                    ],
                ]
                : null,
            'address' => $fullAddress ?: $address,
        ];
    }

    public function store(Request $request, Shoot $shoot)
    {
        abort_unless($this->authorization->canSubmitShootRequest($shoot, $request->user()), 403, 'Forbidden');
        $validated = $request->validate([
            'travel_location_confirmed' => 'nullable|boolean',
            'travel_override' => 'nullable|boolean',
            'travel_override_reason' => 'nullable|string|max:500',
            'requested_date' => 'required|date',
            'requested_time' => 'nullable|string|max:25',
            'reason' => 'nullable|string|max:2000',
            'expected_units_revision' => 'nullable|integer|min:0',
            'services' => 'sometimes|array',
            'services.*.id' => 'required|integer|distinct',
            'services.*.duration_minutes' => ['required', 'integer', 'min:0', 'max:300', new \App\Rules\ServiceDuration],
            'service_lines' => 'sometimes|array',
            'service_lines.*.shoot_service_id' => 'required|integer|distinct',
            'service_lines.*.duration_minutes' => ['required', 'integer', 'min:0', 'max:300', new \App\Rules\ServiceDuration],
        ]);

        $user = $request->user();
        $canApplyDirectly = $this->userCanReviewRequests($user);
        if (! $canApplyDirectly && (! empty($validated['services']) || ! empty($validated['service_lines']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['services' => ['Only scheduling staff can change service durations when rescheduling.']]);
        }

        $travelGuard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $candidate = new ShootRescheduleRequest(['requested_date' => $validated['requested_date'],
            'requested_time' => $validated['requested_time'] ?? $shoot->time,
            'units_revision' => $validated['expected_units_revision'] ?? $shoot->units_revision]);
        $travelPrepared = $canApplyDirectly && $travelGuard->enabled() ? $travelGuard->prepare(
            app(\App\Services\Scheduling\RescheduleWritePlan::class)->build($shoot, $candidate, $user, $validated), $shoot, $user
        ) : ['enabled' => false];
        $record = $travelGuard->commit($travelPrepared, fn () => \App\Support\LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $validated, $user, $canApplyDirectly) {
            $shoot = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            $hasUnits = $shoot->units()->exists();
            if ($hasUnits && isset($validated['expected_units_revision']) && (int) $validated['expected_units_revision'] !== (int) $shoot->units_revision) {
                throw \Illuminate\Validation\ValidationException::withMessages(['expected_units_revision' => ['The unit schedule changed. Reload before rescheduling.']]);
            }
            $record = ShootRescheduleRequest::create([
                'shoot_id' => $shoot->id,
                'units_revision' => $hasUnits ? (int) $shoot->units_revision : null,
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
                $this->applyScheduleChanges($shoot, $record, $validated);
            } else {
                $this->logRequestSubmitted($shoot, $record);
            }

            return $record;
        }), 'shoot-reschedule-create'));

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
        abort_unless($this->authorization->canTriageShootRequests($rescheduleRequest->shoot, $request->user()), 403, 'Forbidden');

        $validated = $request->validate([
            'travel_location_confirmed' => 'nullable|boolean',
            'travel_override' => 'nullable|boolean',
            'travel_override_reason' => 'nullable|string|max:500',
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

        $travelGuard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $travelPrepared = $validated['status'] === ShootRescheduleRequest::STATUS_APPROVED && $travelGuard->enabled()
            ? $travelGuard->prepare(app(\App\Services\Scheduling\RescheduleWritePlan::class)->build(
                $rescheduleRequest->shoot, $rescheduleRequest, $request->user(), $validated
            ), $rescheduleRequest->shoot, $request->user()) : ['enabled' => false];
        $applied = false;

        try {
            $applied = $travelGuard->commit($travelPrepared, fn () => \App\Support\LockedWrite::run(fn () => DB::transaction(function () use ($rescheduleRequest, $validated, $request, &$applied) {
                $rescheduleRequest->refresh();
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

                return $applied;

            }), 'shoot-reschedule-review'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {

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

    private function applyScheduleChanges(Shoot $shoot, ShootRescheduleRequest $request, array $durationChanges = []): void
    {
        // Second line of defence for idempotency: whichever path calls this, the
        // change is written at most once per request row.
        if ($request->hasBeenApplied()) {
            return;
        }

        $mailService = app(MailService::class);
        $shoot->loadMissing('services');
        $beforeSnapshot = $mailService->captureShootSnapshot($shoot);

        if ($shoot->units()->exists()) {
            if (! empty($durationChanges['services'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['services' => ['Identify each unit service by its booked service line.']]);
            }
            app(\App\Services\Shoots\MultiUnitRescheduleService::class)->apply($shoot, $request, auth()->user(), $durationChanges['service_lines'] ?? []);
        } else {
            // Match MultiUnitRescheduleService TZ handling: wall-clock in shoot
            // timezone → UTC instant when zoned; legacy unzoned keeps local clock.
            $resolved = app(\App\Services\Shoots\MultiUnitRescheduleService::class)
                ->resolveRequestedWallClock(
                    $shoot,
                    $request->requested_date,
                    $request->requested_time
                );
            if (! empty($durationChanges['service_lines'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['service_lines' => ['Use service durations for this single-property booking.']]);
            }
            $lines = $shoot->serviceItems()->get();
            foreach ($durationChanges['services'] ?? [] as $change) {
                $line = $lines->firstWhere('service_id', (int) $change['id']);
                if (! $line) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['services' => ['A duration can only be changed for a service already on this shoot.']]);
                }
                $line->duration_minutes = (int) $change['duration_minutes'];
            }
            // Lines inherited from the order follow the new appointment; independently
            // scheduled visits keep their own dates and times.
            $oldStart = $shoot->scheduled_at?->format('Y-m-d H:i:s');
            foreach ($lines as $line) {
                if ($line->workflow_status === 'cancelled' || $line->delivery_status === 'cancelled') {
                    continue;
                }
                if ($line->scheduled_at === null || $line->scheduled_at->format('Y-m-d H:i:s') === $oldStart) {
                    $line->scheduled_at = $resolved['scheduled_at'];
                }
            }
            $proposed = clone $shoot;
            $proposed->scheduled_at = $resolved['scheduled_at'];
            $proposed->setRelation('serviceItems', $lines);
            $durations = app(\App\Services\Shoots\ShootDurationResolver::class);
            $support = app(\App\Services\Shoots\ShootMutationSupportService::class);
            $zone = $resolved['has_timezone'] ? $resolved['timezone'] : null;
            $rows = app(\App\Services\Scheduling\WriteSchedulePlan::class)->storedServices($proposed);
            if ($rows === [] && $shoot->photographer_id) {
                $support->assertWithinAvailabilityBounds((int) $shoot->photographer_id, $resolved['local'], $durations->defaultMinutes(), $shoot->id, false, $zone);
            }
            foreach ($durations->windowsForShoot($proposed) as $window) {
                if ($window['start'] && $window['photographer_id']) {
                    $localStart = $zone ? $window['start']->copy()->setTimezone($zone) : $window['start'];
                    $support->assertWithinAvailabilityBounds($window['photographer_id'], $localStart, $window['minutes'], $shoot->id, false, $zone);
                }
            }
            $support->checkServiceItemPhotographerAvailability($rows, $shoot->photographer_id, $shoot->id, $zone, false, $resolved['local']);
            foreach ($lines as $line) {
                if ($line->isDirty()) {
                    $line->save();
                }
            }
            $shoot->unsetRelation('services')->unsetRelation('serviceItems');
            $shoot->scheduled_date = $resolved['scheduled_date'];
            // Preserve requested display string when provided; otherwise use
            // the normalized local H:i from the same parse as scheduled_at.
            $shoot->time = ! empty($request->requested_time)
                ? $request->requested_time
                : $resolved['time'];
            $shoot->scheduled_at = $resolved['scheduled_at'];
            $shoot->save();
        }

        if (! empty($durationChanges['services']) || ! empty($durationChanges['service_lines'])) {
            app(\App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher::class)->dispatchShootSync($shoot->id);
        }

        // Mark applied before notifying: if a notification throws, the shoot has
        // still moved, and a retry must not move it again.
        $request->applied_at = now();
        if ($request->status !== ShootRescheduleRequest::STATUS_APPROVED) {
            $request->status = ShootRescheduleRequest::STATUS_APPROVED;
        }
        $request->save();

        $notify = function () use ($shoot, $request, $mailService, $beforeSnapshot) {
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
            $scheduledContext = array_merge($context, [
                'notify_client' => false,
                'notify_photographer' => $automationService->shouldUseFallback('SHOOT_UPDATED'),
            ]);
            $automationService->handleEvent('SHOOT_SCHEDULED', $scheduledContext);
            $shootUpdatedDispatch = $automationService->handleEvent('SHOOT_UPDATED', $context);

            if ($shoot->client && $automationService->shouldUseFallback('SHOOT_UPDATED', $shootUpdatedDispatch) !== false) {
                $mailService->sendShootUpdatedEmail($shoot->client, $shoot, $changesSummary);
            }

            $this->logRescheduleActivity($shoot, $request);
        };
        if (config('availability.hybrid_travel_enabled')) {
            DB::afterCommit($notify);
        } else {
            $notify();
        }

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
        return $this->authorization->canReviewShootRequests($user);
    }

    private function authorizeReviewer(Request $request): void
    {
        if (! $this->userCanReviewRequests($request->user())) {
            abort(403, 'Only staff can review reschedule requests.');
        }
    }
}
