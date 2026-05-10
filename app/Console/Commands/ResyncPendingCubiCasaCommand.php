<?php

namespace App\Console\Commands;

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

        $this->info("Re-synced {$count} CubiCasa shoot(s).");
        return self::SUCCESS;
    }
}
