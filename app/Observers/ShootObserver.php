<?php

namespace App\Observers;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\GenerateShootMediaArchiveJob;
use App\Jobs\SyncShootIguideJob;
use App\Models\Shoot;
use App\Services\CompensationEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShootObserver
{
    public function deleting(Shoot $shoot): void
    {
        if ($shoot->isComplimentaryReshoot()
            || $shoot->reshootChildren()->exists()
            || $shoot->rootReshootDescendants()->exists()
            || $shoot->compReshootItems()->exists()
            || $shoot->compensations()->exists()) {
            throw ValidationException::withMessages([
                'shoot' => [
                    'Shoots in a complimentary-reshoot lineage cannot be permanently deleted. Cancel the shoot to preserve its audit trail.',
                ],
            ]);
        }
    }

    public function updated(Shoot $shoot): void
    {
        $this->ensureCubiCasaOrder($shoot);
        $this->ensureIguideDiscovery($shoot);

        if ($shoot->wasChanged(['workflow_status', 'status', 'admin_verified_at', 'completed_at'])) {
            app(CompensationEligibilityService::class)->syncForShoot($shoot);
        }

        if (!$shoot->wasChanged('workflow_status') && !$shoot->wasChanged('status')) {
            return;
        }

        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));

        // Only build a media archive when the shoot actually has the relevant
        // files. A no-media (fast-forward) delivery has nothing to archive, so
        // dispatching the job would just fail with "No downloadable files
        // available".
        $rawCount = (int) ($shoot->raw_photo_count ?? 0);
        $editedCount = (int) ($shoot->edited_photo_count ?? 0);

        if ($status === Shoot::STATUS_EDITING) {
            if ($rawCount > 0) {
                GenerateShootMediaArchiveJob::dispatch($shoot->id, 'raw', 'small');
            }
            return;
        }

        if ($status === Shoot::STATUS_READY || $status === Shoot::STATUS_DELIVERED) {
            if ($editedCount > 0) {
                GenerateShootMediaArchiveJob::dispatch($shoot->id, 'edited', 'small');
            }
        }
    }

    /**
     * Dispatch a CubiCasa order whenever a shoot arrives at "scheduled with a
     * floor-plan service" by ANY route.
     *
     * CreateShootAction and ApproveShootAction dispatch explicitly, but they
     * were the only two paths that did — scheduling an existing shoot, a plain
     * PATCH to requested -> scheduled, applying an alternate date and the
     * AI-chat booking flow all produced no order at all. Hooking the lifecycle
     * covers those and any path added later. Duplicate dispatches are harmless:
     * CreateCubiCasaOrderJob no-ops on an already-linked shoot, and repeated
     * creates reuse the shoot's persisted Idempotency-Key.
     */
    private function ensureCubiCasaOrder(Shoot $shoot): void
    {
        // Only react to a transition, and order the cheap checks before the
        // relationship query that hasCubiCasaEligibleService() performs.
        if (!$shoot->wasChanged('scheduled_at')
            && !$shoot->wasChanged('workflow_status')
            && !$shoot->wasChanged('status')
        ) {
            return;
        }

        if ($shoot->scheduled_at === null) {
            return;
        }

        if (!empty($shoot->cubicasa_order_id) || !empty($shoot->cubicasa_external_id)) {
            return;
        }

        // Never order for a shoot that is not yet confirmed. A client request
        // can carry a preferred date while still awaiting approval, and paying
        // for a scan before anyone approves the booking is not recoverable.
        $blocked = [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED, Shoot::STATUS_REQUESTED];
        if (in_array($shoot->status, $blocked, true)
            || in_array($shoot->workflow_status, $blocked, true)
        ) {
            return;
        }

        if (!$shoot->hasCubiCasaEligibleService()) {
            return;
        }

        // Never let an ordering side effect break the save that triggered it.
        // The catch must live INSIDE the deferred callback: on a sync queue the
        // job executes when the transaction commits, which is after this method
        // has returned, so a try/catch around dispatch()->afterCommit() here
        // would not contain the throw and would 500 the caller.
        // Logged at error level on purpose: warnings are dropped under
        // LOG_LEVEL=error, which is how the original 400 stayed invisible.
        $dispatch = static function () use ($shoot): void {
            try {
                CreateCubiCasaOrderJob::dispatch($shoot->id, 'lifecycle');
            } catch (\Throwable $e) {
                Log::error('CubiCasa lifecycle auto-create failed; shoot update completed regardless.', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        // Defer past the surrounding transaction so the job never reads a row
        // that has not been committed yet.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }

    /**
     * Attempt iGUIDE discovery whenever a shoot arrives at "scheduled with a
     * floor-plan / iGuide service" by ANY route.
     *
     * Mirrors ensureCubiCasaOrder so that rescheduling, a plain status PATCH,
     * an alternate date or the AI-chat booking flow all get a discovery attempt
     * without waiting on the half-hourly reconciliation command. Unlike
     * CubiCasa this orders nothing and costs nothing: it is a provider lookup,
     * and SyncShootIguideJob re-checks eligibility, no-ops when no match is
     * found, and de-duplicates ingested assets by asset key.
     */
    private function ensureIguideDiscovery(Shoot $shoot): void
    {
        if (!$shoot->wasChanged('scheduled_at')
            && !$shoot->wasChanged('workflow_status')
            && !$shoot->wasChanged('status')
        ) {
            return;
        }

        if ($shoot->scheduled_at === null) {
            return;
        }

        // Already resolved: the tour URL is the done-marker the reconciliation
        // command uses, so honour it here too.
        if (!empty($shoot->iguide_tour_url)) {
            return;
        }

        $blocked = [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED, Shoot::STATUS_REQUESTED];
        if (in_array($shoot->status, $blocked, true)
            || in_array($shoot->workflow_status, $blocked, true)
        ) {
            return;
        }

        if (!$shoot->hasIguideEligibleService()) {
            return;
        }

        // Same containment rule as CubiCasa: the catch must be inside the
        // deferred callback, because on a sync queue the job runs at commit,
        // after this method has already returned.
        $dispatch = static function () use ($shoot): void {
            try {
                SyncShootIguideJob::dispatch($shoot->id);
            } catch (\Throwable $e) {
                Log::error('iGUIDE lifecycle discovery failed; shoot update completed regardless.', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
