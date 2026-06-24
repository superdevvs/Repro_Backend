<?php

namespace App\Services\TelnyxAi;

use App\Models\VoiceCall;
use Carbon\CarbonImmutable;

class VoiceCallStatsService
{
    public function stats(string $range = '7d'): array
    {
        $days = match ($range) {
            'today' => 1,
            '30d' => 30,
            default => 7,
        };

        $start = $range === 'today'
            ? now()->startOfDay()
            : now()->subDays($days - 1)->startOfDay();

        $query = VoiceCall::query()->where('created_at', '>=', $start);
        $total = (clone $query)->count();
        $answered = (clone $query)->whereNotNull('answered_at')->count();
        $missed = (clone $query)
            ->where('direction', 'INBOUND')
            ->whereNotNull('ended_at')
            ->whereNull('answered_at')
            ->count();
        $aiHandled = (clone $query)->where('handled_by', 'ai')->count();
        $needsFollowUp = (clone $query)->where(function ($q): void {
            $q->where('disposition', 'handoff_to_staff')
                ->orWhere('needs_follow_up', true)
                ->orWhereJsonContains('metadata->needs_follow_up', true);
        })->count();
        $avgDuration = (int) round((clone $query)->whereNotNull('duration_seconds')->avg('duration_seconds') ?? 0);

        return [
            'range' => $range,
            'cards' => [
                ['key' => 'total_calls', 'label' => 'Total Calls', 'value' => $total, 'sparkline' => $this->sparkline($days)],
                ['key' => 'answered', 'label' => 'Answered', 'value' => $answered, 'sparkline' => $this->sparkline($days, answered: true)],
                ['key' => 'missed', 'label' => 'Missed', 'value' => $missed, 'sparkline' => $this->sparkline($days, missed: true)],
                ['key' => 'avg_duration', 'label' => 'Avg Duration', 'value' => $avgDuration, 'suffix' => 'sec', 'sparkline' => []],
                ['key' => 'ai_handled', 'label' => 'AI Handled', 'value' => $aiHandled, 'sparkline' => []],
                ['key' => 'needs_follow_up', 'label' => 'Needs Follow-up', 'value' => $needsFollowUp, 'sparkline' => []],
            ],
        ];
    }

    private function sparkline(int $days, ?array $statuses = null, bool $answered = false, bool $missed = false): array
    {
        $points = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::now()->subDays($i);
            $query = VoiceCall::query()
                ->whereDate('created_at', $day->toDateString());

            if ($statuses !== null) {
                $query->whereIn('status', $statuses);
            }
            if ($answered) {
                $query->whereNotNull('answered_at');
            }
            if ($missed) {
                $query->where('direction', 'INBOUND')
                    ->whereNotNull('ended_at')
                    ->whereNull('answered_at');
            }

            $points[] = [
                'date' => $day->toDateString(),
                'value' => $query->count(),
            ];
        }

        return $points;
    }
}
