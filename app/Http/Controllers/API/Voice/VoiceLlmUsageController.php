<?php

namespace App\Http\Controllers\API\Voice;

use App\Http\Controllers\Controller;
use App\Models\VoiceLlmUsage;
use App\Services\TelnyxAi\VoiceLlmUsageRecorder;
use App\Services\TelnyxAi\VoiceSettingsService;
use Illuminate\Http\JsonResponse;

class VoiceLlmUsageController extends Controller
{
    public function __construct(
        private readonly VoiceLlmUsageRecorder $usage,
        private readonly VoiceSettingsService $settings,
    ) {
    }

    /**
     * GET /voice/llm-usage — current month's spend vs cap (admin only).
     */
    public function __invoke(): JsonResponse
    {
        $budget = (float) ($this->settings->all()['intelligence']['monthly_llm_budget_usd'] ?? 0);
        $summary = $this->usage->summary($budget);

        $recent = VoiceLlmUsage::query()
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'voice_call_id', 'purpose', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'created_at']);

        return response()->json(array_merge($summary, [
            'recent' => $recent,
        ]));
    }
}
