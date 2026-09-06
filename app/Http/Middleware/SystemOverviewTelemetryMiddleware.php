<?php

namespace App\Http\Middleware;

use App\Services\SystemOverviewTelemetryService;
use App\Services\RequestCorrelation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SystemOverviewTelemetryMiddleware
{
    public function __construct(
        private readonly SystemOverviewTelemetryService $telemetry,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $traceId = RequestCorrelation::id($request);

        $request->attributes->set('system_overview.trace_id', $traceId);
        $request->attributes->set('system_overview.started_at', microtime(true));

        /** @var Response $response */
        $response = $next($request);

        try {
            $this->telemetry->recordRequestTrace($request, $response);
        } catch (\Throwable) {
            // Telemetry must never break the primary API request path.
        }

        return $response;
    }
}
