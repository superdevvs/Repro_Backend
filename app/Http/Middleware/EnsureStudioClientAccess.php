<?php

namespace App\Http\Middleware;

use App\Services\Studio\StudioClientAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudioClientAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        StudioClientAccess::authorize($request->user());

        return $next($request);
    }
}
