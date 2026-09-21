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

        $query = VoiceCall::query()->whereBetween('created_at', [$start, now()]);
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

    /**
     * Operational insights computed from stored calls only. Deltas are omitted
     * when the previous window has no comparable traffic.
     *
     * @return array<string, mixed>
     */
    public function insights(string $range = '7d'): array
    {
        $days = match ($range) {
            'today' => 1,
            '30d' => 30,
            default => 7,
        };

        $currentStart = $range === 'today'
            ? now()->startOfDay()
            : now()->subDays($days - 1)->startOfDay();
        $previousStart = $currentStart->copy()->subDays($days);
        $previousEnd = $currentStart->copy()->subSecond();

        $current = $this->insightWindow($currentStart, now());
        $previous = $this->insightWindow($previousStart, $previousEnd);

        $intents = VoiceCall::query()
            ->whereBetween('created_at', [$currentStart, now()])
            ->whereNotNull('intent')
            ->where('intent', '!=', '')
            ->selectRaw('intent, count(*) as aggregate')
            ->groupBy('intent')
            ->orderByDesc('aggregate')
            ->get();

        $intentTotal = max(1, (int) $intents->sum('aggregate'));
        $intentRows = $intents->map(fn ($row) => [
            'key' => (string) $row->intent,
            'label' => str_replace('_', ' ', (string) $row->intent),
            'count' => (int) $row->aggregate,
            'pct' => round(((int) $row->aggregate / $intentTotal) * 100),
        ])->values()->all();

        $volume = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::now()->subDays($i);
            $dayQuery = VoiceCall::query()->whereDate('created_at', $day->toDateString())->where('created_at', '<=', now());
            $team = (clone $dayQuery)->where(function ($q): void {
                $q->where('handled_by', 'human')->orWhere('handled_by', 'mixed');
            })->whereNotNull('answered_at')->count();
            $ai = (clone $dayQuery)->where('handled_by', 'ai')->whereNotNull('answered_at')->count();
            $missed = (clone $dayQuery)
                ->where('direction', 'INBOUND')
                ->whereNotNull('ended_at')
                ->whereNull('answered_at')
                ->count();
            $total = (clone $dayQuery)->count();
            $volume[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'team' => $team,
                'ai' => $ai,
                'missed' => $missed,
                'total' => $total,
            ];
        }

        $opportunity = null;
        $topIntent = $intentRows[0] ?? null;
        if ($topIntent && $topIntent['count'] >= 3) {
            $opportunity = [
                'title' => 'Most common caller need this period',
                'detail' => sprintf(
                    '%d callers were tagged “%s”. Review whether Robbie’s greeting and tools cover that request.',
                    $topIntent['count'],
                    $topIntent['label']
                ),
            ];
        }

        return [
            'range' => $range,
            'answered_rate' => $current['answered_rate'],
            'answered_rate_delta' => $this->delta($current['answered_rate'], $previous['answered_rate'], $previous['inbound']),
            'median_answer_seconds' => $current['median_answer_seconds'],
            'median_answer_delta' => $this->delta($current['median_answer_seconds'], $previous['median_answer_seconds'], $previous['answered']),
            'bookings_from_calls' => $current['bookings'],
            'bookings_delta' => $this->delta($current['bookings'], $previous['bookings'], $previous['total'], absolute: true),
            'missed_recovered_rate' => $current['missed_recovered_rate'],
            'missed_recovered_delta' => $this->delta($current['missed_recovered_rate'], $previous['missed_recovered_rate'], $previous['missed']),
            'inbound_total' => $current['inbound'],
            'volume_by_day' => $volume,
            'intents' => $intentRows,
            'handoff_connected_rate' => $current['handoff_connected_rate'],
            'handoffs_needing_callback' => $current['handoffs_needing_callback'],
            'opportunity' => $opportunity,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *   total: int,
     *   inbound: int,
     *   answered: int,
     *   missed: int,
     *   bookings: int,
     *   answered_rate: float|null,
     *   median_answer_seconds: int|null,
     *   missed_recovered_rate: float|null,
     *   handoff_connected_rate: float|null,
     *   handoffs_needing_callback: int
     * }
     */
    private function insightWindow($start, $end): array
    {
        $query = VoiceCall::query()->whereBetween('created_at', [$start, $end]);
        $total = (clone $query)->count();
        $inboundQuery = (clone $query)->where('direction', 'INBOUND');
        $inbound = (clone $inboundQuery)->count();
        $answered = (clone $inboundQuery)->whereNotNull('answered_at')->count();
        $missedQuery = (clone $inboundQuery)->whereNotNull('ended_at')->whereNull('answered_at');
        $missed = (clone $missedQuery)->count();
        // Count each call once only when a trusted tool audit records a created
        // shoot. A booking intention or confirmation request is not a booking.
        $bookings = (clone $query)->where(function ($booked): void {
            $booked->whereHas('toolInvocations', function ($tool): void {
                $tool->where('tool_name', 'book_shoot')->where('status', 'executed')
                    ->where('output_payload->success', true)
                    ->where('output_payload->result->success', true)
                    ->where('output_payload->result->shoot_id', '>', 0);
            })->orWhereExists(function ($tool): void {
                $tool->selectRaw('1')->from('tool_bridge_invocations')
                    ->where('tool', 'book_shoot')->where('channel', 'VOICE')->where('status', 'ok')
                    ->where('response_json->ok', true)
                    ->where('response_json->result->success', true)
                    ->where('response_json->result->shoot_id', '>', 0)
                    ->where(function ($identity): void {
                        $identity->whereColumn('request_json->context->voice_call_id', 'voice_calls.id')
                            ->orWhereColumn('tool_bridge_invocations.call_control_id', 'voice_calls.call_control_id');
                    });
            });
        })->count();
        $recovered = (clone $missedQuery)->where(function ($callback): void {
            $callback->whereHas('scheduledCallback.resultVoiceCall', fn ($result) => $result->whereNotNull('answered_at'))
                ->orWhereHas('scheduledCalls.resultVoiceCall', fn ($result) => $result->whereNotNull('answered_at'));
        })->count();
        $handoffQuery = (clone $query)->where(function ($q): void {
            $q->where('status', 'transferred')->orWhere('disposition', 'transferred')
                ->orWhere('disposition', 'handoff_to_staff')
                ->orWhereNotNull('metadata->transfer_requested_at')
                ->orWhereNotNull('metadata->handoff_requested_at');
        });
        $handoffs = (clone $handoffQuery)->count();
        // Command acceptance sets disposition too early; a processed provider
        // event confirms the transfer actually connected.
        $connected = (clone $handoffQuery)->whereHas('events', function ($event): void {
            $event->where('event_type', 'call.transferred')->whereNotNull('processed_at')->whereNull('processing_error');
        })->count();
        $handoffCallbacks = (clone $handoffQuery)->where(function ($q): void {
            $pending = fn ($callback) => $callback->whereIn('status', ['scheduled', 'deferred', 'dialing', 'failed']);
            $q->whereHas('scheduledCallback', $pending)->orWhereHas('scheduledCalls', $pending);
        })->count();

        $answerSeconds = (clone $inboundQuery)
            ->whereNotNull('answered_at')
            ->whereNotNull('started_at')
            ->whereColumn('answered_at', '>=', 'started_at')
            ->get(['started_at', 'answered_at'])
            ->map(fn (VoiceCall $call) => $call->started_at->diffInSeconds($call->answered_at))
            ->sort()
            ->values();
        $median = null;
        if ($answerSeconds->isNotEmpty()) {
            $middle = intdiv($answerSeconds->count(), 2);
            $median = $answerSeconds->count() % 2
                ? $answerSeconds[$middle]
                : ($answerSeconds[$middle - 1] + $answerSeconds[$middle]) / 2;
        }

        return [
            'total' => $total,
            'inbound' => $inbound,
            'answered' => $answered,
            'missed' => $missed,
            'bookings' => $bookings,
            'answered_rate' => $inbound > 0 ? round(($answered / $inbound) * 100, 1) : null,
            'median_answer_seconds' => $median === null ? null : (int) round($median),
            'missed_recovered_rate' => $missed > 0 ? round(($recovered / $missed) * 100, 1) : null,
            'handoff_connected_rate' => $handoffs > 0 ? round(($connected / $handoffs) * 100, 1) : null,
            'handoffs_needing_callback' => $handoffCallbacks,
        ];
    }

    private function delta(int|float|null $current, int|float|null $previous, int $previousVolume, bool $absolute = false): int|float|null
    {
        if ($current === null || $previous === null || $previousVolume < 1) {
            return null;
        }

        $delta = $current - $previous;

        return $absolute ? (int) round($delta) : round($delta, 1);
    }

    private function sparkline(int $days, ?array $statuses = null, bool $answered = false, bool $missed = false): array
    {
        $points = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::now()->subDays($i);
            $query = VoiceCall::query()
                ->whereDate('created_at', $day->toDateString())->where('created_at', '<=', now());

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
