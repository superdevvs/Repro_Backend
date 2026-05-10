<?php

namespace App\Console\Commands;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Console\Command;

/**
 * Re-run asset ingestion for shoots that already have cubicasa_floorplans data
 * but no ingested ShootFile records (metadata.source = "cubicasa"). Mirrors
 * BackfillIguideAssetsCommand.
 */
class BackfillCubiCasaAssetsCommand extends Command
{
    protected $signature = 'cubicasa:backfill-assets {--limit=200} {--shoot=}';

    protected $description = 'Backfill CubiCasa deliverables (PDFs/JPG floors) into ShootFile rows for historical shoots.';

    public function handle(): int
    {
        $shootId = $this->option('shoot');
        $limit = (int) $this->option('limit');

        $query = Shoot::query()
            ->whereNotNull('cubicasa_floorplans');

        if ($shootId) {
            $query->where('id', (int) $shootId);
        } else {
            $query->limit($limit);
        }

        $queued = 0;
        foreach ($query->cursor() as $shoot) {
            $floorplans = is_array($shoot->cubicasa_floorplans) ? $shoot->cubicasa_floorplans : [];
            if (empty($floorplans)) {
                continue;
            }

            $existing = ShootFile::query()
                ->where('shoot_id', $shoot->id)
                ->where('media_type', 'floorplan')
                ->get();

            $existingKeys = [];
            foreach ($existing as $file) {
                $metadata = is_array($file->metadata) ? $file->metadata : [];
                $key = $metadata['cubicasa_asset_key'] ?? null;
                if (is_string($key) && $key !== '') {
                    $existingKeys[$key] = true;
                }
            }

            $missing = array_filter($floorplans, static function ($f) use ($existingKeys) {
                $key = is_array($f) ? ($f['asset_key'] ?? null) : null;
                return is_string($key) && $key !== '' && !isset($existingKeys[$key]);
            });

            if (empty($missing)) {
                continue;
            }

            IngestCubiCasaAssetsJob::dispatch($shoot->id, array_values($missing));
            $queued++;
        }

        $this->info("Queued {$queued} CubiCasa asset backfill jobs.");
        return self::SUCCESS;
    }
}
