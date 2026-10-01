<?php

namespace App\Http\Middleware;

use App\Services\Messaging\DashboardMessagingPolicy;
use Closure;
use Illuminate\Http\Request;

class RequireDashboardEmailStaff
{
    public function handle(Request $request, Closure $next)
    {
        app(DashboardMessagingPolicy::class)->authorizeEmail($request->user());

        return $next($request);
    }
}
