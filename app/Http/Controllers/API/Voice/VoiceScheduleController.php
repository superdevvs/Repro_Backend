<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceScheduleOverride;
use App\Services\TelnyxAi\BusinessScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceScheduleController extends Controller
{
    public function __construct(private readonly BusinessScheduleService $schedule)
    {
    }

    /**
     * GET /voice/schedule/state?caller_tz=America/Los_Angeles
     */
    public function state(Request $request): JsonResponse
    {
        $callerTz = $request->query('caller_tz');

        return response()->json([
            'state' => $this->schedule->currentState(null, $callerTz),
            'guidance' => $this->schedule->robbieScheduleGuidance(null, $callerTz),
        ]);
    }

    /**
     * GET /voice/schedule/overrides
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'overrides' => VoiceScheduleOverride::query()->orderByDesc('starts_at')->get(),
        ]);
    }

    /**
     * POST /voice/schedule/overrides
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'mode' => ['required', 'in:open,closed'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);
        $data['created_by'] = $request->user()?->id;

        $override = VoiceScheduleOverride::query()->create($data);

        return response()->json($override, 201);
    }

    /**
     * DELETE /voice/schedule/overrides/{override}
     */
    public function destroy(VoiceScheduleOverride $override): JsonResponse
    {
        $override->delete();

        return response()->json(['deleted' => true]);
    }
}
