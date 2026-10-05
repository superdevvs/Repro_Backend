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
        $result = app(\App\Services\Shoots\ProviderFloorplanRecovery::class)->recover('iguide', max(1, (int) $this->option('limit')), $this->option('shoot') ? (int) $this->option('shoot') : null);
        $this->line(json_encode($result));

        return self::SUCCESS;
    }
}
