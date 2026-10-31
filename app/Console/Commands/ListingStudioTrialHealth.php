<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\ListingStudioTrialMonitoring;
use Illuminate\Console\Command;

class ListingStudioTrialHealth extends Command
{
    protected $signature = 'listing-studio:trial-health';

    protected $description = 'Read-only JSON health, quota, and conversion aggregates for private Studio trials.';

    public function handle(ListingStudioTrialMonitoring $monitoring): int
    {
        $snapshot = $monitoring->snapshot();
        $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $snapshot['warnings'] ? self::FAILURE : self::SUCCESS;
    }
}
