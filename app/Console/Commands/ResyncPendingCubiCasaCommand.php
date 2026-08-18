<?php

namespace App\Console\Commands;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Console\Command;

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

        $query = Shoot::query()
            ->with('services.category')
            ->whereNotNull('cubicasa_order_id')
            ->where(function ($q) {
                $q->whereNull('cubicasa_status')
                    ->orWhereIn('cubicasa_status', ['Pending', 'Fixing', 'New', 'Draft']);
            })
            ->orderByDesc('id')
            ->limit($limit);

        $count = 0;
        foreach ($query->cursor() as $shoot) {
            $parsed = $cubicasa->syncShoot($shoot);
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
        return self::SUCCESS;
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

        $candidates = Shoot::query()
            ->with('services.category')
            ->whereNull('cubicasa_order_id')
            ->whereNull('cubicasa_external_id')
            ->whereNotNull('scheduled_at')
            ->whereNotIn('status', $terminal)
            ->whereNotIn('workflow_status', $terminal)
            ->orderByDesc('id')
            ->limit($limit);

        $created = 0;
        foreach ($candidates->cursor() as $shoot) {
            if (!$shoot->hasCubiCasaEligibleService()) {
                continue;
            }

            CreateCubiCasaOrderJob::dispatch($shoot->id, 'backfill');
            $created++;
        }

        return $created;
    }
}
