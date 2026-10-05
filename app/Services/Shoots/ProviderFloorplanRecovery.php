<?php

namespace App\Services\Shoots;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\IngestIguideAssetsJob;
use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Support\Facades\Cache;

class ProviderFloorplanRecovery
{
    public function recover(string $provider, int $limit = 100, ?int $shootId = null, bool $dryRun = false): array
    {
        if (!in_array($provider, ['cubicasa', 'iguide'], true)) throw new \InvalidArgumentException('Unsupported floorplan provider.');
        $result = ['shoots' => 0, 'imports' => 0, 'scans' => 0, 'previews' => 0];
        // Finish each read before writing queue/cache rows on SQLite. Apply the work limit
        // after inspecting completeness so already-complete shoots cannot starve old ones.
        $ids = Shoot::query()->where(fn ($q) => $q->whereNotNull($provider.'_floorplans')->orWhereHas('units', fn ($u) => $u->whereNotNull('provider_data'))->orWhereHas('files', fn ($f) => $f->where('media_type', 'floorplan')->where('metadata->source', $provider)))
            ->when($shootId, fn ($q) => $q->whereKey($shootId))->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $shoot = Shoot::with('services.category')->find($id);
            if (!$shoot || $shoot->isInternalTestShoot()) continue;
            $scopes = [];
            if (!$shoot->units()->exists()) $scopes[0] = $shoot->getAttribute($provider.'_floorplans') ?? [];
            foreach ($shoot->units()->get() as $unit) {
                $flat = $unit->provider_data ?? [];
                $flatLine = $flat[$provider.'_service_line_id'] ?? null;
                if ($flatLine && is_array($flat[$provider.'_floorplans'] ?? null) && $unit->serviceItems()->whereKey($flatLine)->exists()) {
                    $scopes[(int) $flatLine] = $flat[$provider.'_floorplans'];
                }
                foreach (data_get($unit->provider_data, 'lines', []) as $lineId => $data) {
                    if (is_array($data[$provider.'_floorplans'] ?? null) && $unit->serviceItems()->whereKey($lineId)->exists()) {
                        $scopes[(int) $lineId] = $data[$provider.'_floorplans'];
                    }
                }
            }
            $worked = false;
            foreach ($scopes as $lineId => $assets) {
                if (!is_array($assets)) continue;
                $files = ShootFile::query()->where('shoot_id', $id)->where('media_type', 'floorplan')
                    ->where('shoot_service_id', $lineId ?: null)->get();
                $keys = [];
                foreach ($files as $file) {
                    $meta = $file->metadata ?? [];
                    if (($meta['source'] ?? null) !== $provider) continue;
                    if ($file->isInvalidProviderFloorplan() && $file->scan_status !== ShootFile::SCAN_STATUS_INFECTED) continue;
                    if (!empty($meta[$provider.'_asset_key'])) $keys[$meta[$provider.'_asset_key']] = true;
                    if (in_array($file->scan_status, [ShootFile::SCAN_STATUS_QUARANTINED, ShootFile::SCAN_STATUS_FAILED], true)) {
                        $worked = true;
                        if ($this->queue('scan:'.$file->id, fn () => ScanShootFileJob::dispatch($file->id)->afterCommit(), $dryRun)) $result['scans']++;
                    } elseif ($file->scan_status === ShootFile::SCAN_STATUS_CLEAN && !$file->web_path) {
                        $worked = true;
                        if ($this->queue('preview:'.$file->id, fn () => ProcessImageJob::dispatch($file)->afterCommit(), $dryRun)) $result['previews']++;
                    }
                }
                $missing = array_values(array_filter($assets, fn ($a) => is_array($a) && !empty($a['url']) && !empty($a['asset_key']) && !isset($keys[$a['asset_key']])));
                if ($missing) {
                    $worked = true;
                    $job = $provider === 'cubicasa' ? IngestCubiCasaAssetsJob::class : IngestIguideAssetsJob::class;
                    if ($this->queue('import:'.$provider.':'.$id.':'.$lineId, fn () => $job::dispatch($id, $missing, $lineId ?: null)->afterCommit(), $dryRun)) $result['imports']++;
                }
            }
            if ($worked && ++$result['shoots'] >= max(1, $limit)) break;
        }
        return $result;
    }

    private function queue(string $identity, callable $dispatch, bool $dryRun): bool
    {
        if ($dryRun) return true;
        $key = 'provider-floorplans:recovery:'.$identity;
        // Bounded coalescing; a lost/failed job becomes recoverable again. Existing scan
        // jobs independently refuse to overwrite terminal clean/infected verdicts.
        if (!Cache::add($key, true, now()->addMinutes(20))) return false;
        try { $dispatch(); } catch (\Throwable $error) { Cache::forget($key); throw $error; }
        return true;
    }
}
