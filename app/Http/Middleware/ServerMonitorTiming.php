<?php
namespace App\Http\Middleware;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServerMonitorTiming
{
    public function handle(\Illuminate\Http\Request $request, \Closure $next)
    {
        $start = hrtime(true);
        if (config('server-monitor.enabled')) $request->attributes->set('monitor_started', $start);
        $measure = $request->is('api/shoots', 'api/shoots/history', 'api/shoots/filters', 'api/invoices', 'api/invoices/summary', 'api/dashboard/*');
        if (! $measure) return $next($request);
        $state = (object) ['active' => true, 'queries' => 0, 'db' => 0.0];
        DB::listen(function (QueryExecuted $query) use ($state) {
            if ($state->active) { $state->queries++; $state->db += $query->time; }
        });
        try {
            $response = $next($request);
            $duration = (hrtime(true) - $start) / 1e6;
            $serialize = (float) $request->attributes->get('serialization_ms', 0);
            $response->headers->set('Server-Timing', sprintf('app;dur=%.2f, db;dur=%.2f, serialize;dur=%.2f', $duration, $state->db, $serialize));
            Log::channel('performance')->info('api_request', [
                'route' => $request->route()?->uri(), 'status' => $response->getStatusCode(),
                'duration_ms' => round($duration, 2), 'db_ms' => round($state->db, 2),
                'queries' => $state->queries, 'serialization_ms' => round($serialize, 2),
                'response_bytes' => strlen((string) $response->getContent()),
            ]);
            return $response;
        } finally { $state->active = false; }
    }
}
