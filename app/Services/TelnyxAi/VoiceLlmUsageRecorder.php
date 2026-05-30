<?php

namespace App\Services\TelnyxAi;

use App\Models\VoiceCall;
use App\Models\VoiceLlmUsage;

/**
 * Records per-LLM-call token usage and cost for the voice intelligence layer,
 * and answers budget questions used to pause enrichment when a tenant exceeds
 * its monthly cap.
 */
class VoiceLlmUsageRecorder
{
    // Rough blended USD per 1K tokens. Kept conservative; exact pricing is not
    // critical for the soft monthly cap.
    private const COST_PER_1K_INPUT = 0.005;
    private const COST_PER_1K_OUTPUT = 0.015;

    public function record(?VoiceCall $call, string $purpose, string $model, int $inputTokens, int $outputTokens): VoiceLlmUsage
    {
        $cost = ($inputTokens / 1000) * self::COST_PER_1K_INPUT
            + ($outputTokens / 1000) * self::COST_PER_1K_OUTPUT;

        return VoiceLlmUsage::query()->create([
            'voice_call_id' => $call?->id,
            'purpose' => $purpose,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($cost, 5),
            'created_at' => now(),
        ]);
    }

    /**
     * Total spend over the rolling 30-day window.
     */
    public function monthlySpend(): float
    {
        return (float) VoiceLlmUsage::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('cost_usd');
    }

    /**
     * True when the rolling spend has met or exceeded the configured cap.
     * A cap of 0 means unlimited.
     */
    public function isBudgetExceeded(float $monthlyBudgetUsd): bool
    {
        if ($monthlyBudgetUsd <= 0) {
            return false;
        }

        return $this->monthlySpend() >= $monthlyBudgetUsd;
    }

    /**
     * @return array{spend_usd:float,budget_usd:float,exceeded:bool,remaining_usd:float}
     */
    public function summary(float $monthlyBudgetUsd): array
    {
        $spend = $this->monthlySpend();
        $exceeded = $monthlyBudgetUsd > 0 && $spend >= $monthlyBudgetUsd;

        return [
            'spend_usd' => round($spend, 4),
            'budget_usd' => round($monthlyBudgetUsd, 2),
            'exceeded' => $exceeded,
            'remaining_usd' => $monthlyBudgetUsd > 0 ? round(max(0, $monthlyBudgetUsd - $spend), 4) : 0.0,
        ];
    }
}
