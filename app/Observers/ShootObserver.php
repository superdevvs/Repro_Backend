<?php

namespace App\Observers;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\GenerateShootMediaArchiveJob;
use App\Jobs\SyncShootIguideJob;
use App\Models\Shoot;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Services\CompensationEligibilityService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
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

        // Any hard-delete path (API DeleteShootAction, purge command, QA cleanup)
        // must drop Google Calendar mappings/events. afterCommit waits for the
        // surrounding DB transaction so a rolled-back delete never queues removal.
        if (! $shoot->isInternalTestShoot()) {
            app(GoogleCalendarSyncDispatcher::class)->dispatchShootRemoval((int) $shoot->id);
        }
    }

    public function created(Shoot $shoot): void
    {
        // Cover booking paths that forget an explicit calendar dispatch. CreateShootAction
        // also dispatches; duplicate afterCommit jobs are idempotent (fingerprint no-op).
        $this->ensureGoogleCalendarSync($shoot, true);
    }

    public function updated(Shoot $shoot): void
    {
        $this->ensureCubiCasaOrder($shoot);
        $this->ensureIguideDiscovery($shoot);
        $this->ensureGoogleCalendarSync($shoot);

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
                // Prebuild the full original raw ZIP so editor/admin full-set
                // downloads and share packaging can reuse a signature-matched
                // archive instead of rebuilding on every click. Keep the small
                // preview archive too for existing client/dashboard paths.
                app(\App\Services\Shoots\ShootMediaArchiveService::class)
                    ->queueArchiveGeneration($shoot, 'raw', 'original');
                app(\App\Services\Shoots\ShootMediaArchiveService::class)
                    ->queueArchiveGeneration($shoot, 'raw', 'small');
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
     * Push (or refresh) the photographer Google Calendar event whenever a shoot
     * is created or its assignment/schedule/status changes by ANY route.
     *
     * Create/Schedule/Update/Approve actions dispatch explicitly, but plain
     * status PATCHes, AI-chat booking, and service-item photographer assignment
     * via alternate controllers have historically skipped the push — leaving
     * brand-new bookings off the photographer's calendar while older linked
     * events (from connect-time resync) still looked fine.
     */
    private function ensureGoogleCalendarSync(Shoot $shoot, bool $created = false): void
    {
        if ($shoot->suppressesExternalNotifications()) {
            return;
        }

        // wasRecentlyCreated remains true on subsequent saves of the same model.
        // Only the actual created event may bypass the changed-field check.
        if (! $created
            && ! $shoot->wasChanged(['photographer_id', 'scheduled_at', 'status', 'workflow_status'])
        ) {
            return;
        }

        $shootId = (int) $shoot->id;
        $dispatch = function () use ($shootId): void {
            try {
                // Creation often precedes attaching service assignments. Read the
                // committed row and assignments so we do not publish half a booking.
                $committedShoot = Shoot::find($shootId);
                if (! $committedShoot || ! $this->hasGoogleCalendarWork($committedShoot)) {
                    return;
                }

                app(GoogleCalendarSyncDispatcher::class)->dispatchShootSync($shootId);
            } catch (\Throwable $e) {
                Log::error('Google Calendar lifecycle sync dispatch failed; shoot save completed regardless.', [
                    'shoot_id' => $shootId,
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

    private function hasGoogleCalendarWork(Shoot $shoot): bool
    {
        if ($shoot->suppressesExternalNotifications()) {
            return false;
        }

        // Existing events still need cleanup when a schedule or assignment is
        // removed, or a booking moves to a state that should leave the calendar.
        if (GoogleCalendarEventMapping::where('shoot_id', $shoot->id)->exists()) {
            return true;
        }

        $statuses = [strtolower((string) $shoot->status), strtolower((string) $shoot->workflow_status)];
        if (array_intersect($statuses, [Shoot::STATUS_REQUESTED, Shoot::STATUS_DECLINED, Shoot::STATUS_ON_HOLD, 'hold_on'])) {
            return false;
        }

        $serviceItems = $shoot->serviceItems()->get(['photographer_id', 'scheduled_at']);
        if (! $shoot->scheduled_at && ! $serviceItems->contains(fn ($item) => $item->scheduled_at !== null)) {
            return false;
        }

        $photographerIds = $serviceItems->pluck('photographer_id')->push($shoot->photographer_id)->filter()->unique();

        return $photographerIds->isNotEmpty()
            && GoogleCalendarConnection::whereIn('user_id', $photographerIds)->where('sync_enabled', true)->exists();
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
        if ($shoot->isInternalTestShoot()) {
            return;
        }

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
                if ($shoot->units()->exists()) return;
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
        if ($shoot->isInternalTestShoot()) {
            return;
        }

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
                if ($shoot->units()->exists()) return;
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
