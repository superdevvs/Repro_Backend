<?php

namespace App\Jobs;

use App\Services\ListingStudio\ListingStudioBillingEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendListingStudioBillingRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $deliveryId)
    {
        $this->onQueue('default');
    }

    public function handle(ListingStudioBillingEmailService $emails): void
    {
        $emails->deliver($this->deliveryId);
    }
}
