<?php

namespace App\Console\Commands;

use App\Services\IguideOfflineChunkUploadService;
use Illuminate\Console\Command;

class PruneIguideOfflineUploads extends Command
{
    protected $signature = 'iguide-uploads:prune';

    protected $description = 'Expire resumable iGUIDE uploads and remove abandoned private chunks';

    public function handle(IguideOfflineChunkUploadService $uploads): int
    {
        $result = $uploads->prune();
        $this->info(sprintf(
            'iGUIDE resumable uploads: %d expired, %d assembly jobs requeued, %d completed scans reconciled, %d stale scans failed, %d terminal sessions pruned, %d orphan staging directories pruned.',
            $result['expired'],
            $result['requeued'],
            $result['scan_reconciled'],
            $result['scan_failed'],
            $result['pruned'],
            $result['orphan_pruned']
        ));

        return self::SUCCESS;
    }
}
