<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\ListingStudioSubscriptionReceiptService;
use Illuminate\Console\Command;

class RecoverListingStudioSubscriptionReceipts extends Command
{
    protected $signature = 'listing-studio:subscription-receipts-dispatch {--limit=100}';
    protected $description = 'Dispatch queued subscription confirmations and reconcile existing email delivery';

    public function handle(ListingStudioSubscriptionReceiptService $receipts): int
    {
        $this->info('Queued confirmation intents examined: '.$receipts->recover((int) $this->option('limit')));
        return self::SUCCESS;
    }
}
