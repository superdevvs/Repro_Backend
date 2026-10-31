<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\ListingStudioOutboxService;
use Illuminate\Console\Command;

class DispatchListingStudioOrders extends Command
{
    protected $signature = 'listing-studio:orders-dispatch {--limit=100}';

    protected $description = 'Dispatch bounded durable Studio processing, delivery and refund intents.';

    public function handle(ListingStudioOutboxService $outbox): int
    {
        $result = $outbox->dispatch((int) $this->option('limit'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
