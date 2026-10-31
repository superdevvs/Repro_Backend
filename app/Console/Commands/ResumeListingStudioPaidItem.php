<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\ListingStudioPaidPipeline;
use Illuminate\Console\Command;

class ResumeListingStudioPaidItem extends Command
{
    protected $signature = 'listing-studio:paid-resume {item : Existing held paid item UUID}';

    protected $description = 'Recover the same held paid workspace without discarding accepted provider checkpoints';

    public function handle(ListingStudioPaidPipeline $pipeline): int
    {
        try {
            $pipeline->resume($this->argument('item'));
            $this->info('Existing paid operation recovered or queued. No replacement order was created.');

            return self::SUCCESS;
        } catch (\App\Exceptions\StudioProviderException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
