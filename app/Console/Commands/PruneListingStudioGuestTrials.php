<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\ListingStudioGuestTrialService;
use Illuminate\Console\Command;

class PruneListingStudioGuestTrials extends Command
{
    protected $signature = 'listing-studio:prune-guest-trials {--limit=100}';

    protected $description = 'Expire unclaimed instant-trial media after seven days without removing entitlement history.';

    public function handle(ListingStudioGuestTrialService $trials): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $reconciled = $trials->reconcileStale($limit);
        $count = $trials->expireUnclaimed($limit);
        $admissions = app(\App\Services\ListingStudio\ListingStudioTrialAdmission::class)->prune(10000);
        app(\App\Services\ListingStudio\ListingStudioTrialMonitoring::class)->prune();
        $this->info('Expired '.$count.' unclaimed Studio trial results.');
        $this->info('Reconciled '.$reconciled.' interrupted trial records.');
        $this->info('Removed '.$admissions.' expired upload admissions.');

        return self::SUCCESS;
    }
}
