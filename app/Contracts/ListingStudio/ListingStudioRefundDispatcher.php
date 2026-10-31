<?php

namespace App\Contracts\ListingStudio;

use App\Models\ListingStudioOutbox;

interface ListingStudioRefundDispatcher
{
    /**
     * Called outside financial transactions. Must safely replay the same event ID.
     * Payload contains orderId, itemId, attemptId. Never create a new purchase on retry.
     */
    public function dispatch(ListingStudioOutbox $event): void;
}
