<?php

namespace App\Console\Commands;

use App\Jobs\SyncShootIguideJob;
use App\Models\Shoot;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Re-attempt iGUIDE sync for recent shoots that don't yet have a tour_url.
 * Useful when a photographer creates the iGuide on youriguide.com a day or
 * two AFTER the shoot was uploaded, and no webhook reaches us (e.g. before
 * webhooks were configured, or for an iGuide created without our webhook URL).
 */
class ResyncPendingIguidesCommand extends Command
{
    protected $signature = 'iguide:resync-pending {--days=7 : How many days back to scan} {--limit=100 : Max shoots per run}';

    protected $description = 'Re-attempt iGUIDE sync for recent shoots without an iguide_tour_url.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $cutoff = Carbon::now()->subDays(max(1, $days));

        // Read the ids first and let the query finish before dispatching.
        // Dispatching is a write to the `jobs` table, and holding a SQLite read
        // snapshot open across a write is what made cubicasa:resync-pending fail
        // with "database is locked" on roughly one run in five.
        $shootIds = Shoot::query()
            ->whereNull('iguide_tour_url')
            ->where(function ($q) use ($cutoff) {
                $q->where('updated_at', '>=', $cutoff)
                    ->orWhere('scheduled_date', '>=', $cutoff->copy()->toDateString());
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        foreach ($shootIds as $shootId) {
            $shoot = Shoot::with('services.category')->find($shootId);
            // Skip shoots that didn't book a floorplan / iGuide service.
            if (!$shoot || !$shoot->hasIguideEligibleService()) {
                continue;
            }
            SyncShootIguideJob::dispatch($shoot->id);
            $count++;
        }

        $this->info("Queued {$count} iGUIDE resync jobs (window: last {$days} day(s)).");
        return self::SUCCESS;
    }
}
