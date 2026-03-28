<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SystemOverviewTelemetryService;
use Illuminate\Http\Request;

class SystemTelemetryController extends Controller
{
    public function __construct(
        private readonly SystemOverviewTelemetryService $telemetry,
    ) {
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!$this->telemetry->telemetryAvailable()) {
            return response()->json([
                'message' => 'System overview telemetry is not initialized yet.',
                'stored' => 0,
                'telemetryAvailable' => false,
                'traceId' => $request->attributes->get('system_overview.trace_id'),
            ], 202);
        }

        $events = $request->input('events');
        if (!is_array($events) || $events === []) {
            return response()->json(['message' => 'The events payload is required.'], 422);
        }

        $stored = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            if ($this->telemetry->recordClientEvent($request, $event) !== null) {
                $stored++;
            }
        }

        return response()->json([
            'message' => 'Telemetry accepted.',
            'stored' => $stored,
            'telemetryAvailable' => true,
            'traceId' => $request->attributes->get('system_overview.trace_id'),
        ]);
    }
}
