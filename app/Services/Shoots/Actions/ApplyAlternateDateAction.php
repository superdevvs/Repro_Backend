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
    ) {
    }

    /**
     * @param 'main'|'all_services' $scope
     */
    public function execute(Shoot $shoot, string $scope, User $actor): Shoot
    {
        // Req 5.3 / 9.4 — reject when no stored alternate; make NO schedule changes.
        // Guard runs BEFORE the transaction so nothing is mutated when rejected.
        if (empty($shoot->alternate_scheduled_date)) {
            throw ValidationException::withMessages([
                'alternate' => ['This shoot has no alternate date to apply.'],
            ]);
        }

        return DB::transaction(function () use ($shoot, $scope, $actor) {
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
        });
    }
}
