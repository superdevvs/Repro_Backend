<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemOverviewTelemetryService;

class SystemOverviewController extends Controller
{
    public function __construct(
        private readonly SystemOverviewTelemetryService $telemetry,
    ) {
    }

    public function snapshot()
    {
        return response()->json([
            'data' => $this->telemetry->buildSnapshot(),
            'telemetryAvailable' => $this->telemetry->telemetryAvailable(),
        ]);
    }

    public function history()
    {
        return response()->json([
            'data' => $this->telemetry->buildHistory(),
            'telemetryAvailable' => $this->telemetry->telemetryAvailable(),
        ]);
    }

    public function liveUsers()
    {
        return response()->json([
            'data' => $this->telemetry->buildLiveUsers(),
            'telemetryAvailable' => $this->telemetry->telemetryAvailable(),
        ]);
    }

    public function routes()
    {
        return response()->json([
            'data' => $this->telemetry->buildRoutesCatalog(),
            'telemetryAvailable' => $this->telemetry->telemetryAvailable(),
        ]);
    }

    public function trace(string $traceId)
    {
        if (!$this->telemetry->telemetryAvailable()) {
            return response()->json([
                'message' => 'System overview telemetry is not initialized yet.',
                'code' => 'system_overview_unavailable',
                'telemetryAvailable' => false,
            ], 503);
        }

        $trace = $this->telemetry->buildTraceDetail($traceId);
        if (!$trace) {
            return response()->json(['message' => 'Trace not found.'], 404);
        }

        return response()->json([
            'data' => $trace,
            'telemetryAvailable' => true,
        ]);
    }
}
