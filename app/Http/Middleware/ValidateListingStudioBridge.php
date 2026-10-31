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
        $session = (string) $request->header('X-Studio-Session');
        $ip = (string) $request->header('X-Studio-IP');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $session) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new PublicApiException('The Studio session is invalid. Refresh the page.', 'studio_session_invalid', 422);
        }
        $request->attributes->set('studio_session_hash', hash_hmac('sha256', 'session:'.$session, (string) config('app.key')));
        $request->attributes->set('studio_ip_hash', hash_hmac('sha256', 'ip:'.$ip, (string) config('app.key')));
        $rateKey = 'listing-studio-bridge:'.$request->attributes->get('studio_ip_hash');
        if (RateLimiter::tooManyAttempts($rateKey, 300)) {
            throw new PublicApiException('Too many Studio requests. Please wait a moment.', 'trial_rate_limit', 429);
        }
        RateLimiter::hit($rateKey, 60);

        return $next($request);
    }
}
