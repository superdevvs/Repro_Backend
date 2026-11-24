<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The Sales CRM stays dark until CRM_ENABLED is on. */
class EnsureCrmEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('crm.enabled')) {
            return response()->json(['message' => 'The Sales CRM is not switched on yet.'], 404);
        }

        return $next($request);
    }
}
