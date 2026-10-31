<?php

namespace App\Console\Commands;

use App\Models\ListingStudioGuestTrial;
use App\Services\ListingStudio\ListingStudioTrialPipeline;
use Illuminate\Console\Command;

class ResumeListingStudioGuestTrial extends Command
{
    protected $signature = 'listing-studio:resume-trial {id : Guest trial UUID}';

    protected $description = 'Resume retrieval of an existing confirmed provider job without creating another edit.';

    public function handle(ListingStudioTrialPipeline $pipeline): int
    {
        $trial = ListingStudioGuestTrial::find($this->argument('id'));
        if (! $trial) {
            $this->error('Trial not found.');

            return self::FAILURE;
        }
        try {
            $pipeline->resume($trial);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('This trial cannot be resumed automatically. Check its existing provider submission before any manual recovery.');

            return self::FAILURE;
        }
        $this->info('Recovery queued for the existing trial operation.');

        return self::SUCCESS;
    }
}
