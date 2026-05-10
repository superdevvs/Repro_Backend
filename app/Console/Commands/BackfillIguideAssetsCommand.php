<?php

namespace App\Console\Commands;

use App\Jobs\IngestIguideAssetsJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Console\Command;

/**
 * Re-run asset ingestion for shoots that already have iguide_floorplans data
 * but no ingested ShootFile records (metadata.source = "iguide"). Used after
 * deploying the new ingestion pipeline to bring historical iGuides up to date.
 */
class BackfillIguideAssetsCommand extends Command
{
    protected $signature = 'iguide:backfill-assets {--limit=200} {--shoot=}';

    protected $description = 'Backfill iGUIDE deliverables (PDFs/JPG floors) into ShootFile records for historical shoots.';

    public function handle(): int
    {
        $shootId = $this->option('shoot');
        $limit = (int) $this->option('limit');

        $query = Shoot::query()
            ->whereNotNull('iguide_floorplans');

        if ($shootId) {
            $query->where('id', (int) $shootId);
        } else {
            $query->limit($limit);
        }

        $queued = 0;
        foreach ($query->cursor() as $shoot) {
            $floorplans = is_array($shoot->iguide_floorplans) ? $shoot->iguide_floorplans : [];
            if (empty($floorplans)) {
                continue;
            }

            // Skip shoots that already have iGuide-sourced ShootFiles for every asset_key.
            $existing = ShootFile::query()
                ->where('shoot_id', $shoot->id)
                ->where('media_type', 'floorplan')
                ->get();

            $existingKeys = [];
            foreach ($existing as $file) {
                $metadata = is_array($file->metadata) ? $file->metadata : [];
                $key = $metadata['iguide_asset_key'] ?? null;
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

            IngestIguideAssetsJob::dispatch($shoot->id, array_values($missing));
            $queued++;
        }

        $this->info("Queued {$queued} iGUIDE asset backfill jobs.");
        return self::SUCCESS;
    }
}
