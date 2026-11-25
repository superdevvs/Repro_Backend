<?php

namespace App\Http\Middleware;

use App\Services\RequestCorrelation;
use App\Services\SystemOverviewTelemetryService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one request trace per API request. By default the trace is written in terminate(),
 * which runs after the response has been sent, so its database writes do not delay the user.
 * Set system_overview.record_after_response to false to record inside the request again.
 */
class SystemOverviewTelemetryMiddleware
{
    public function __construct(
        private readonly SystemOverviewTelemetryService $telemetry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $traceId = RequestCorrelation::id($request);

        $request->attributes->set('system_overview.trace_id', $traceId);
        $request->attributes->set('system_overview.started_at', microtime(true));

        /** @var Response $response */
        $response = $next($request);

        $request->attributes->set('system_overview.finished_at', microtime(true));

        if (! $this->recordAfterResponse()) {
            $this->record($request, $response);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->recordAfterResponse() && $request->attributes->has('system_overview.finished_at')) {
            $this->record($request, $response);
        }
    }

    private function recordAfterResponse(): bool
    {
        return (bool) config('system_overview.record_after_response', true);
    }

    private function record(Request $request, Response $response): void
    {
        try {
            $this->telemetry->recordRequestTrace($request, $response);
        } catch (\Throwable) {
            // Telemetry must never break the primary API request path.
        }
    }
}
