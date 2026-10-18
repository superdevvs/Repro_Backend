<?php

namespace App\Console\Commands;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile unfinished orders and incomplete Ready deliveries, including
 * orders whose webhook never reached us.
 */
class ResyncPendingCubiCasaCommand extends Command
{
    protected $signature = 'cubicasa:resync-pending {--limit=100 : Max shoots per run}';

    protected $description = 'Reconcile unfinished CubiCasa orders and recover missing floorplan deliveries.';

    public function handle(CubiCasaService $cubicasa): int
    {
        if (!$cubicasa->hasCredentials()) {
            $this->error('CUBICASA_API_KEY is not set.');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        app(\App\Services\Shoots\ProviderFloorplanRecovery::class)->recover('cubicasa', $limit);
        $missingAssetShootIds = $this->readyShootsMissingAssets();

        // Collect the ids and let the read finish before any write happens.
        //
        // This used to iterate ->cursor() and write to each shoot inside the
        // loop. On SQLite that leaves a read snapshot open across the writes, so
        // when the queue worker committed to the same file mid-loop this
        // connection got SQLITE_BUSY_SNAPSHOT ("database is locked") that
        // busy_timeout cannot wait out. Roughly one scheduled run in five died
        // partway through, which also meant backfillMissingOrders() below never
        // ran on those runs.
        $shootIds = Shoot::query()
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->whereDoesntHave('units')
            ->where(function ($q) {
                $q->whereNotNull('cubicasa_order_id')->orWhereNotNull('cubicasa_external_id');
            })
            ->where(function ($q) use ($missingAssetShootIds) {
                $q->whereNull('cubicasa_status')
                    ->orWhereRaw('LOWER(cubicasa_status) NOT IN (?, ?, ?, ?)', ['ready', 'cancelled', 'canceled', 'declined'])
                    ->orWhere('cubicasa_sync_status', CubiCasaService::SYNC_STATUS_FAILED)
                    ->orWhereIn('id', $missingAssetShootIds);
            })
            ->where(function ($q) {
                $q->whereNull('cubicasa_sync_status')
                    ->orWhereNotIn('cubicasa_sync_status', [CubiCasaService::SYNC_STATUS_QUEUED, CubiCasaService::SYNC_STATUS_RUNNING])
                    ->orWhereNull('cubicasa_sync_started_at')
                    ->orWhere('cubicasa_sync_started_at', '<=', now()->subMinutes(10));
            })
            // Ordering by newest id kept older shoots outside the same first
            // 100 forever. Include attempts as well as successes so failed
            // provider requests also rotate behind untouched orders.
            ->orderByRaw('CASE WHEN cubicasa_sync_started_at IS NULL THEN cubicasa_last_synced_at WHEN cubicasa_last_synced_at IS NULL OR cubicasa_sync_started_at > cubicasa_last_synced_at THEN cubicasa_sync_started_at ELSE cubicasa_last_synced_at END ASC')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        $failed = 0;

        foreach ($shootIds as $shootId) {
            $shoot = Shoot::with('services.category')->find($shootId);
            if (!$shoot) {
                continue;
            }

            // One shoot must never take the rest of the run down with it, and
            // must never skip the backfill safety net.
            try {
                $parsed = $cubicasa->syncShoot($shoot);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('CubiCasa resync failed for one shoot; continuing with the rest.', [
                    'shoot_id' => $shootId,
                    'error' => $e->getMessage(),
                ]);
                $this->releaseRunningState($cubicasa, $shoot, $e);
                continue;
            }

            if (!$parsed) {
                $failed++;
                continue;
            }

            $floorplans = is_array($parsed['floorplans'] ?? null) ? $parsed['floorplans'] : [];
            if (!empty($floorplans) && $shoot->hasCubiCasaEligibleService()) {
                IngestCubiCasaAssetsJob::dispatch($shoot->id, $floorplans);
            }
            $count++;
        }

        $created = $this->backfillMissingOrders($limit);

        $this->info("Re-synced {$count} CubiCasa shoot(s); queued {$created} missing order(s).");

        if ($failed > 0) {
            // Provider failures and exhausted write retries must be visible
            // to scheduler monitoring without aborting other shoots.
            $this->error("{$failed} CubiCasa shoot(s) could not be re-synced.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * A Ready provider status only completes delivery once all returned asset
     * keys exist locally. Read this set fully before dispatching any writes;
     * even a partial ingestion failure must be recoverable on SQLite.
     *
     * @return array<int, int>
     */
    private function readyShootsMissingAssets(): array
    {
        return Shoot::query()
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->whereDoesntHave('units')
            ->whereRaw('LOWER(cubicasa_status) = ?', ['ready'])
            ->with(['files' => fn ($q) => $q->where('media_type', 'floorplan')->whereNull('shoot_service_id')->select('id', 'shoot_id', 'metadata', 'media_type', 'file_type', 'mime_type', 'scan_status')])
            ->get(['id', 'cubicasa_floorplans'])
            ->filter(function (Shoot $shoot): bool {
                $floorplans = collect($shoot->cubicasa_floorplans ?? [])
                    ->filter(fn ($item) => is_array($item) && !empty($item['asset_key']) && !empty($item['url']));
                if ($shoot->files->contains(fn ($file) => $file->isInvalidProviderFloorplan() && $file->scan_status !== 'infected')) return true;
                if ($floorplans->isEmpty()) {
                    // A local replacement deliberately clears provider originals.
                    return $shoot->files->isEmpty();
                }

                $existingKeys = $shoot->files->pluck('metadata.cubicasa_asset_key')->filter();
                return $floorplans->pluck('asset_key')->diff($existingKeys)->isNotEmpty();
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Clear the "running" marker a crashed sync leaves behind.
     *
     * syncShoot() stamps sync_status=running before it calls the provider. When
     * the following write died, the shoot stayed "running" forever and looked
     * like an in-flight sync that never completed.
     */
    private function releaseRunningState(CubiCasaService $cubicasa, Shoot $shoot, \Throwable $cause): void
    {
        try {
            $cubicasa->markSyncFailed(
                $shoot,
                CubiCasaService::SYNC_STATUS_FAILED,
                'Re-sync could not complete: ' . $cause->getMessage()
            );
        } catch (\Throwable $e) {
            // If even this write cannot land, leave the row alone rather than
            // masking the original cause.
            Log::warning('Could not record the CubiCasa re-sync failure on the shoot.', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue orders for shoots that should have one but don't.
     *
     * The re-sync loop above only ever looked at shoots that already carry a
     * cubicasa_order_id, so a shoot whose order was never created — because the
     * create 400'd, or because it reached "scheduled" by a route that never
     * dispatched — could never be recovered by the scheduled job. That made a
     * missing order permanent and silent. This is the safety net.
     */
    private function backfillMissingOrders(int $limit): int
    {
        // Same reason as handle(): dispatching a job is itself a write to the
        // `jobs` table, so the read must be finished first.
        $candidateIds = Shoot::query()
            ->cubicasaAutoOrderEligible()
            ->whereNull('cubicasa_order_id')
            ->whereNull('cubicasa_external_id')
            ->whereNotNull('scheduled_at')
            ->whereIn(DB::raw('LOWER(TRIM(status))'), CreateCubiCasaOrderJob::AUTO_CREATE_STATUSES)
            ->whereIn(DB::raw("LOWER(COALESCE(NULLIF(TRIM(workflow_status), ''), TRIM(status)))"), CreateCubiCasaOrderJob::AUTO_CREATE_STATUSES)
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->whereDoesntHave('units')
            // Filter before LIMIT: unrelated photo bookings must not consume
            // all of the reconciliation slots and hide eligible older shoots.
            ->cubicasaEligible()
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $created = 0;
        foreach ($candidateIds as $shootId) {
            $shoot = Shoot::with('services.category')->find($shootId);
            if (!$shoot || !$shoot->hasCubiCasaAutoOrderService()) {
                continue;
            }

            CreateCubiCasaOrderJob::dispatch($shoot->id, 'backfill');
            $created++;
        }

        return $created;
    }
}
