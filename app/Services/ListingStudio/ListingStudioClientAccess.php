<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Models\User;

final class ListingStudioClientAccess
{
    public static function assert(User $client): void
    {
        if ($client->role !== 'client' || ! $client->isAccountEligibleForAuthentication()) {
            throw new PublicApiException('Use an active client account to access Listing Studio.', 'account_ineligible', 403);
        }
    }
}
