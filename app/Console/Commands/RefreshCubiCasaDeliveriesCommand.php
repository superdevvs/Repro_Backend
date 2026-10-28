<?php

namespace App\Console\Commands;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Shoot;
use App\Services\Shoots\ShootUnitTourScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Bounded, rotating fallback for revisions whose provider webhook was lost. */
class RefreshCubiCasaDeliveriesCommand extends Command
{
    protected $signature = 'cubicasa:refresh-deliveries {--shoot= : Refresh only this shoot} {--limit=25 : Maximum service scopes per run}';

    protected $description = 'Check delivered CubiCasa floorplans for revisions without replacing unchanged files.';

    public function handle(ShootUnitTourScope $scope): int
    {
        $candidates = [];
        $shoots = Shoot::query()->when($this->option('shoot'), fn ($q) => $q->whereKey((int) $this->option('shoot')))
            ->whereNotIn('status', ['cancelled', 'canceled', 'declined', 'on_hold'])
            ->where(fn ($q) => $q->whereNotNull('cubicasa_order_id')->orWhereHas('units', fn ($u) => $u->whereNotNull('provider_data')))
            ->with(['units', 'serviceItems.service', 'services.category'])->get();
        foreach ($shoots as $shoot) {
            if ($shoot->isInternalTestShoot()) {
                continue;
            }
            $views = [];
            if ($shoot->units->isEmpty()) {
                $views[] = $shoot;
            }
            foreach ($shoot->units as $unit) {
                $data = $unit->provider_data ?? [];
                $ids = array_unique(array_filter(array_merge([$data['cubicasa_service_line_id'] ?? null], array_keys($data['lines'] ?? []))));
                foreach ($ids as $id) {
                    $line = $shoot->serviceItems->firstWhere('id', (int) $id);
                    if ($line && (int) $line->shoot_unit_id === (int) $unit->id) {
                        $views[] = $scope->project($shoot, $unit, $line);
                    }
                }
            }
            foreach ($views as $view) {
                // Empty provider assets can mean a deliberate manual replacement.
                if (! $view->cubicasa_order_id || strtolower($view->cubicasa_status ?? '') !== 'ready'
                    || empty($view->cubicasa_floorplans) || ! $view->hasCubiCasaEligibleService()) {
                    continue;
                }
                $lineId = $view->relationLoaded('tourServiceLine') ? $view->getRelation('tourServiceLine')?->id : null;
                $key = 'cubicasa:revision-poll:'.$shoot->id.':'.($lineId ?? 0);
                if (! $this->option('shoot') && Cache::has($key)) {
                    continue;
                }
                $candidates[] = ['shoot' => $view, 'line' => $lineId, 'key' => $key,
                    'last' => $view->cubicasa_last_synced_at?->getTimestamp() ?? 0];
            }
        }
        usort($candidates, fn ($a, $b) => [$a['last'], $a['shoot']->id, $a['line'] ?? 0] <=> [$b['last'], $b['shoot']->id, $b['line'] ?? 0]);
        $queued = 0;
        foreach (array_slice($candidates, 0, max(1, (int) $this->option('limit'))) as $candidate) {
            if (! $this->option('shoot') && ! Cache::add($candidate['key'], true, now()->addMinutes(45))) {
                continue;
            }
            try {
                IngestCubiCasaAssetsJob::dispatch($candidate['shoot']->id, $candidate['shoot']->cubicasa_floorplans, $candidate['line'], true);
                $queued++;
            } catch (\Throwable $exception) {
                Cache::forget($candidate['key']);
                throw $exception;
            }
        }
        $this->info("Queued {$queued} CubiCasa delivery revision check(s).");

        return self::SUCCESS;
    }
}
