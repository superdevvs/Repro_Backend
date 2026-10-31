<?php

namespace App\Http\Middleware;

use App\Exceptions\PublicApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** A separate credential with no booking, account lookup, or staff API authority. */
class ValidateListingStudioBridge
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('listing-studio-trials.bridge_key');
        $provided = (string) $request->header('X-Studio-Key');
        if (strlen($expected) < 32 || ! hash_equals($expected, $provided)) {
            throw new PublicApiException('This Studio connection is unavailable.', 'studio_bridge_unauthorized', 401);
        }
        // This exact catalog route contains no guest/account data or paid mutation authority.
        if ($request->isMethod('GET') && $request->routeIs('listing-studio.catalog.external')
            && $request->is('api/external/listing-studio/catalog')) {
            return $next($request);
        }
        $session = (string) $request->header('X-Studio-Session');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $session)) {
            throw new PublicApiException('The Studio session is invalid. Refresh the page.', 'studio_session_invalid', 422);
        }
        $request->attributes->set('studio_session_hash', hash_hmac('sha256', 'session:'.$session, (string) config('app.key')));
        // Guest identity is server-authenticated. Only direct dashboard admission can establish a visitor IP.
        $rateKey = 'listing-studio-bridge:'.$request->attributes->get('studio_session_hash');
        if (RateLimiter::tooManyAttempts($rateKey, 300)) {
            throw new PublicApiException('Too many Studio requests. Please wait a moment.', 'trial_rate_limit', 429);
        }
        RateLimiter::hit($rateKey, 60);

        return $next($request);
    }
}
