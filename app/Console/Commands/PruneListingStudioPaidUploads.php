<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\ListingStudioPaidMedia;
use Illuminate\Console\Command;

class PruneListingStudioPaidUploads extends Command
{
    protected $signature = 'listing-studio:paid-uploads-prune {--limit=100}';

    protected $description = 'Remove expired private uploads that no accepted order references';

    public function handle(ListingStudioPaidMedia $media): int
    {
        $this->info('Expired unused uploads removed: '.$media->pruneUnusedUploads((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
