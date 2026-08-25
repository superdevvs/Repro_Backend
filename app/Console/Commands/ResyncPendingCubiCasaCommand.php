<?php

namespace App\Console\Commands;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-fetch CubiCasa orders that are still in non-Ready states (Pending,
 * Fixing, New, Draft) so we catch any missed Ready transitions where the
 * webhook didn't reach us. Mirrors ResyncPendingIguidesCommand.
 */
class ResyncPendingCubiCasaCommand extends Command
{
    protected $signature = 'cubicasa:resync-pending {--limit=100 : Max shoots per run}';

    protected $description = 'Re-fetch CubiCasa orders for shoots whose status is still pending/fixing/new/draft.';

    public function handle(CubiCasaService $cubicasa): int
    {
        if (!$cubicasa->hasCredentials()) {
            $this->error('CUBICASA_API_KEY is not set.');
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

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
            ->whereNotNull('cubicasa_order_id')
            ->where(function ($q) {
                $q->whereNull('cubicasa_status')
                    ->orWhereIn('cubicasa_status', ['Pending', 'Fixing', 'New', 'Draft']);
            })
            ->orderByDesc('id')
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
            // Still a visible failure: the retry inside the service is bounded,
            // so reaching here means contention did not clear.
            $this->error("{$failed} CubiCasa shoot(s) could not be re-synced.");
            return self::FAILURE;
        }

        return self::SUCCESS;
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
        $terminal = [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED];

        // Same reason as handle(): dispatching a job is itself a write to the
        // `jobs` table, so the read must be finished first.
        $candidateIds = Shoot::query()
            ->whereNull('cubicasa_order_id')
            ->whereNull('cubicasa_external_id')
            ->whereNotNull('scheduled_at')
            ->whereNotIn('status', $terminal)
            ->whereNotIn('workflow_status', $terminal)
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');

        $created = 0;
        foreach ($candidateIds as $shootId) {
            $shoot = Shoot::with('services.category')->find($shootId);
            if (!$shoot || !$shoot->hasCubiCasaEligibleService()) {
                continue;
            }

            CreateCubiCasaOrderJob::dispatch($shoot->id, 'backfill');
            $created++;
        }

        return $created;
    }
}
