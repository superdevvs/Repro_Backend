<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Producer replay hold (PRODUCER_INGRESS_HOLD). Runs first in the global
 * stack, so a held request reaches no telemetry, maintenance or controller
 * code. A 503 with Retry-After is the one answer every producer retries.
 *
 * It must touch neither the database nor the cache, with the hold on or off:
 * it reads only config and the request path. It runs before the Telnyx
 * maintenance capture, which must answer while the database is sealed or
 * moving and before anything that could reach it; a lookup here would run on
 * every request during a hold plus maintenance
 * (TelnyxCaptureProducerHoldOrderTest counts queries and cache events).
 */
class HoldProducerIngress
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('producer_replay.ingress_hold') && $request->is(...(array) config('producer_replay.held_paths', []))) {
            return self::retryable('producer_ingress_held');
        }

        return $next($request);
    }

    public static function retryable(string $reason): JsonResponse
    {
        return response()->json(['received' => false, 'retryable' => true, 'error' => $reason], 503, [
            'Retry-After' => (string) config('producer_replay.retry_after_seconds', 60),
            'Cache-Control' => 'no-store',
        ]);
    }
}
