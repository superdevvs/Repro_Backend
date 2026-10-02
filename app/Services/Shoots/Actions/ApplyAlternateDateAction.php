<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copies a shoot's stored alternate date/time onto its live schedule.
 *
 * This is an internal schedule update: it never creates a ShootRescheduleRequest, never
 * invokes AutomationService / MailService, and fires no notification flow. The
 * 'apply_alternate_date' action is intentionally NOT broadcastable (see ShootActivityLogger),
 * so the internal-update guarantees (Req 6) hold by construction. The schedule write, the
 * optional service-pivot write, and the single activity-log entry all run inside one
 * DB::transaction so no partial apply is observable.
 */
final class ApplyAlternateDateAction
{
    public function __construct(
        protected ShootMutationSupportService $support,
        protected ShootActivityLogger $activityLogger,
    ) {}

    /**
     * @param  'main'|'all_services'  $scope
     */
    public function execute(Shoot $shoot, string $scope, User $actor, ?int $expectedUnitsRevision = null, array $travelOptions = []): Shoot
    {
        if ($shoot->units()->exists()) {
            return $this->applyUnitAlternate($shoot, $actor, $expectedUnitsRevision, $travelOptions);
        }
        // Req 5.3 / 9.4 — reject when no stored alternate; make NO schedule changes.
        // Guard runs BEFORE the transaction so nothing is mutated when rejected.
        if (empty($shoot->alternate_scheduled_date)) {
            throw ValidationException::withMessages([
                'alternate' => ['This shoot has no alternate date to apply.'],
            ]);
        }

        $planner = app(\App\Services\Scheduling\WriteSchedulePlan::class);
        $services = $planner->storedServices($shoot);
        if ($scope === 'all_services') {
            foreach ($services as &$line) {
                $line['scheduled_at'] = $shoot->alternate_time ? $shoot->alternate_scheduled_at?->toIso8601String() : null;
            }
            unset($line);
        }
        $guard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $prepared = $guard->prepare($planner->services($travelOptions, $services,
            $shoot->alternate_time ? $shoot->alternate_scheduled_at : null,
            $shoot->photographer_id, $shoot->timezone, 'alternate'), $shoot, $actor);
        return $guard->commit($prepared, fn () => DB::transaction(function () use ($shoot, $scope, $actor) {
            $shoot->loadMissing('services');

            // Snapshot the stored alternate (retained unchanged — Req 5.9 / 9.6).
            $altDate = $shoot->alternate_scheduled_date?->toDateString();
            $altTime = $shoot->alternate_time;            // plain string or null
            $altAt = $shoot->alternate_scheduled_at;      // Carbon|null (derived)

            // Req 5.4 — set the main schedule from the stored alternate. Keep scheduled_at
            // consistent with date+time using the null-time rule: null time => null scheduled_at.
            $shoot->scheduled_date = $altDate;
            $shoot->time = $altTime;
            $shoot->scheduled_at = $altTime ? $altAt : null;
            $shoot->save();

            // Req 5.5 — push the alternate onto every selected service pivot via the existing
            // pivot-write path. Passing current pivot values back means attachServices changes
            // only scheduled_at and preserves price/quantity/photographer_id/editor_id.
            // For scope=main, no payload is built so pivots are untouched (Req 3.3 / 5.4).
            if ($scope === 'all_services') {
                $servicesPayload = $shoot->services->map(fn ($service) => [
                    'id' => (int) $service->id,
                    'price' => $service->pivot?->price,
                    'quantity' => $service->pivot?->quantity ?? 1,
                    'photographer_id' => $service->pivot?->photographer_id,
                    'editor_id' => $service->pivot?->editor_id,
                    'scheduled_at' => $altTime ? $altAt?->format('Y-m-d H:i:s') : null,
                ])->all();

                $this->support->attachServices($shoot, $servicesPayload);
            }

            // Req 5.6 / 5.7 / 9.5 — exactly one activity log entry capturing actor + scope.
            // 'apply_alternate_date' is NOT in $broadcastableActions, so no broadcast/notify.
            $this->activityLogger->log(
                $shoot,
                'apply_alternate_date',
                [
                    'scope' => $scope,
                    'by' => $actor->name,
                    'applied_scheduled_at' => $altTime ? $altAt?->toIso8601String() : null,
                ],
                $actor
            );

            // Return a fresh shoot with the relations the resource needs loaded.
            return $shoot->fresh(['client', 'rep', 'photographer', 'services'])
                ?? $shoot->load(['client', 'rep', 'photographer', 'services']);
        }));
    }

    private function applyUnitAlternate(Shoot $shoot, User $actor, ?int $expectedUnitsRevision, array $travelOptions): Shoot
    {
        $guard = app(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $prepared = ['enabled' => false];
        if ($guard->enabled()) {
            if (! $shoot->alternate_scheduled_date) {
                throw ValidationException::withMessages(['alternate' => ['This shoot has no alternate date to apply.']]);
            }
            $anchor = $shoot->scheduled_at ?? $shoot->serviceItems()->whereNotNull('scheduled_at')->orderBy('scheduled_at')->first()?->scheduled_at;
            $move = new \App\Models\ShootRescheduleRequest(['requested_date' => $shoot->alternate_scheduled_date,
                'requested_time' => $shoot->alternate_time ?: $anchor?->copy()->setTimezone($shoot->timezone ?: config('app.timezone', 'UTC'))->format('H:i'),
                'units_revision' => $expectedUnitsRevision]);
            $prepared = $guard->prepare(app(\App\Services\Scheduling\RescheduleWritePlan::class)->build($shoot, $move, $actor, $travelOptions), $shoot, $actor);
        }
        return $guard->commit($prepared, fn () => \App\Support\LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $actor, $expectedUnitsRevision) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('shoots')->where('id', $shoot->id)->update(['units_revision' => DB::raw('units_revision')]);
            }
            $current = Shoot::query()->lockForUpdate()->findOrFail($shoot->id);
            if (! $current->alternate_scheduled_date) {
                throw ValidationException::withMessages(['alternate' => ['This shoot has no alternate date to apply.']]);
            }
            // A date-only alternate keeps the visit's local start time. A missing
            // capture time never becomes a fabricated or flattened unit schedule.
            $anchor = $current->scheduled_at ?? $current->serviceItems()->whereNotNull('scheduled_at')->orderBy('scheduled_at')->first()?->scheduled_at;
            $localAnchor = $anchor?->copy()->setTimezone($current->timezone ?: config('app.timezone', 'UTC'));
            $move = new \App\Models\ShootRescheduleRequest([
                'requested_date' => $current->alternate_scheduled_date,
                'requested_time' => $current->alternate_time ?: $localAnchor?->format('H:i'),
                'units_revision' => $expectedUnitsRevision,
            ]);
            app(\App\Services\Shoots\MultiUnitRescheduleService::class)->apply($current, $move, $actor);
            $this->activityLogger->log($current, 'apply_alternate_date', [
                'scope' => 'all_unit_visits', 'by' => $actor->name,
                'applied_scheduled_at' => $current->scheduled_at?->toIso8601String(),
            ], $actor);

            return $current->fresh(['client', 'rep', 'photographer', 'services']);
        }), 'apply-unit-alternate-date'));
    }
}
