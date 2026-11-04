<?php

namespace App\Jobs;

use App\Services\ListingStudio\ListingStudioSubscriptionReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendListingStudioSubscriptionReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public string $receiptId)
    {
        // Email never runs inside the payment request or webhook, even on a sync-configured install.
        $this->onConnection('database')->onQueue('default');
    }

    public function handle(ListingStudioSubscriptionReceiptService $receipts): void
    {
        $receipts->deliver($this->receiptId);
    }
}
