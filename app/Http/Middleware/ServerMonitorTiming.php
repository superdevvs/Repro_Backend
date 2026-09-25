<?php

namespace App\Http\Middleware;

class ServerMonitorTiming
{
    public function handle(\Illuminate\Http\Request $request, \Closure $next)
    {
        if (config('server-monitor.enabled')) $request->attributes->set('monitor_started', hrtime(true));
        return $next($request);
    }
}
