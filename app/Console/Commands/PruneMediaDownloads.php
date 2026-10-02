<?php

namespace App\Console\Commands;

use App\Services\Media\LocalMediaDelivery;
use Illuminate\Console\Command;

class PruneMediaDownloads extends Command
{
    protected $signature = 'media:prune-downloads';

    protected $description = 'Remove generated downloads after their 24-hour delivery window';

    public function handle(LocalMediaDelivery $delivery): int
    {
        $this->info(sprintf('Removed %d expired temporary downloads.', $delivery->pruneTemporaryDownloads()));

        return self::SUCCESS;
    }
}
