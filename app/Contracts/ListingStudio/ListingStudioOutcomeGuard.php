<?php

namespace App\Contracts\ListingStudio;

use App\Models\ListingStudioOrderItem;

interface ListingStudioOutcomeGuard
{
    /**
     * DB-only check inside the core write transaction, before financial release/refund.
     * Reject unresolved accepted provider work or pending/recoverable local delivery.
     * Throw a safe PublicApiException; missing implementation must fail closed.
     */
    public function assertDefinitiveStop(ListingStudioOrderItem $item): void;
}
