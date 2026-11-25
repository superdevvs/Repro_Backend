<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Sales CRM stays dark until CRM_ENABLED is on. Staff routes get 404.
 * Producer routes use "crm.enabled:retry" and get a retryable 503 instead,
 * because a producer treats 404 as permanent and drops the submission.
 */
class EnsureCrmEnabled
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! config('crm.enabled')) {
            if ($mode === 'retry') {
                return HoldProducerIngress::retryable('crm_paused');
            }

            return response()->json(['message' => 'The Sales CRM is not switched on yet.'], 404);
        }

        return $next($request);
    }
}
