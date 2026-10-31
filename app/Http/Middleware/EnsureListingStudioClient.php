<?php

namespace App\Http\Middleware;

use App\Exceptions\PublicApiException;
use App\Services\ListingStudio\ListingStudioClientAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureListingStudioClient
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || $request->attributes->get('is_impersonating')
            || filled($request->header('X-Impersonate-User-Id'))) {
            throw new PublicApiException('Use your own client account to access Listing Studio.', 'account_ineligible', 403);
        }
        ListingStudioClientAccess::assert($request->user());

        return $next($request);
    }
}
