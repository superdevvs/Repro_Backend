<?php

namespace App\Http\Middleware;

use App\Exceptions\PublicApiException;
use App\Services\ListingStudio\ListingStudioDelegatedAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureListingStudioDelegate
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || $request->attributes->get('is_impersonating')
            || filled($request->header('X-Impersonate-User-Id'))) {
            throw new PublicApiException('Use your own authorized staff account.', 'delegated_billing_forbidden', 403);
        }
        ListingStudioDelegatedAccess::assertActor($request->user());

        return $next($request);
    }
}
