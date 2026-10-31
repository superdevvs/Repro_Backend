<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\ListingStudioBillingEmailService;
use Illuminate\Console\Command;

class RecoverListingStudioBillingEmails extends Command
{
    protected $signature = 'listing-studio:billing-emails-dispatch {--limit=100}';

    protected $description = 'Dispatch bounded queued payment-request emails without repeating accepted sends';

    public function handle(ListingStudioBillingEmailService $emails): int
    {
        $this->info('Queued email intents examined: '.$emails->recover((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
