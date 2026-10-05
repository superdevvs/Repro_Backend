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
        $result = app(\App\Services\Shoots\ProviderFloorplanRecovery::class)->recover('cubicasa', max(1, (int) $this->option('limit')), $this->option('shoot') ? (int) $this->option('shoot') : null);
        $this->line(json_encode($result));

        return self::SUCCESS;
    }
}
