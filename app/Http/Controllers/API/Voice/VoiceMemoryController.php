<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use App\Services\TelnyxAi\VoiceMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceMemoryController extends Controller
{
    public function __construct(
        private readonly VoiceMemoryService $memory,
        private readonly TelnyxVoiceCallService $calls,
    ) {
    }

    /**
     * GET /voice/calls/{call}/memory?tier=1|2|3
     */
    public function show(Request $request, VoiceCall $call): JsonResponse
    {
        $tier = (int) $request->query('tier', 3);
        $resolved = $this->calls->resolveCaller((string) ($call->from_phone ?: $call->to_phone));

        $payload = match ($tier) {
            1 => $this->memory->tier($call, 'tier1') ?? $this->memory->loadTier1($call, $resolved),
            2 => $this->memory->tier($call, 'tier2') ?? $this->memory->loadTier2($call, $resolved),
            default => $this->memory->tier($call, 'tier3') ?? $this->memory->loadTier3($call, $resolved),
        };

        return response()->json([
            'tier' => $tier,
            'memory' => $payload,
            'importance_score' => $this->memory->importanceScore($call->fresh()),
        ]);
    }

    /**
     * POST /voice/calls/{call}/memory/load-full
     */
    public function loadFull(VoiceCall $call): JsonResponse
    {
        $resolved = $this->calls->resolveCaller((string) ($call->from_phone ?: $call->to_phone));
        // Ensure lower tiers exist for a complete picture.
        if (!$this->memory->tier($call, 'tier1')) {
            $this->memory->loadTier1($call, $resolved);
        }
        $this->memory->loadTier2($call->fresh(), $resolved);
        $tier3 = $this->memory->loadTier3($call->fresh(), $resolved);

        return response()->json([
            'tier' => 3,
            'memory' => $tier3,
            'all' => [
                'tier1' => $this->memory->tier($call->fresh(), 'tier1'),
                'tier2' => $this->memory->tier($call->fresh(), 'tier2'),
                'tier3' => $tier3,
            ],
        ]);
    }
}
