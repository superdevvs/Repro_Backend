<?php

namespace App\Services\ListingStudio;

use App\Contracts\ListingStudio\ListingStudioFulfillmentDispatcher;
use App\Jobs\FinalizeListingStudioRevision;
use App\Models\ListingStudioOutbox;

class ListingStudioPaidFulfillment implements ListingStudioFulfillmentDispatcher
{
    public function dispatch(ListingStudioOutbox $event): void
    {
        $payload = $event->payload;
        match ($event->type) {
            'process_item' => app(ListingStudioPaidPipeline::class)->start($payload['itemId'], $payload['attemptId']),
            'create_revision_task' => app(ListingStudioRevisionService::class)->create($payload['itemId']),
            'finalize_revision_delivery' => FinalizeListingStudioRevision::dispatch($payload['deliveryId']),
            default => throw new \RuntimeException('Unsupported Listing Studio fulfillment event.'),
        };
    }
}
