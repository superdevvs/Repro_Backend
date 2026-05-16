<?php

namespace App\Services\ReproAi\Tools;

use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AvailabilityTools
{
    private PhotographerAvailabilityService $availability;

    public function __construct()
    {
        $this->availability = app(PhotographerAvailabilityService::class);
    }

    /**
     * Get a compact summary of available windows.
     *
     * @param array<string, mixed> $params { date?: 'YYYY-MM-DD', date_range?: 'today'|'week', service_id?: int, photographer_id?: int }
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getAvailability(array $params, array $context = []): array
    {
        try {
            [$from, $to] = $this->resolveRange($params);

            $photographerIds = $this->resolvePhotographers($params);
            if ($photographerIds === []) {
                return [
                    'success' => true,
                    'range' => [
                        'from' => $from->toDateString(),
                        'to' => $to->toDateString(),
                    ],
                    'photographers' => [],
                    'message' => 'No active photographers configured.',
                ];
            }

            $results = [];
            foreach ($photographerIds as $photographerId) {
                $slots = $this->availability->getAvailableSlots($photographerId, $from->copy(), $to->copy());

                if (empty($slots)) {
                    continue;
                }

                $results[] = [
                    'photographer_id' => $photographerId,
                    'photographer_name' => optional(User::find($photographerId))->name,
                    'slot_count' => count($slots),
                    'windows' => $this->summarizeSlots($slots),
                ];
            }

            return [
                'success' => true,
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'photographers' => $results,
            ];
        } catch (\Throwable $e) {
            Log::error('AvailabilityTools.getAvailability failed', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [
                'success' => false,
                'error' => 'Unable to load availability right now.',
            ];
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(array $params): array
    {
        if (!empty($params['date'])) {
            $day = Carbon::parse((string) $params['date'])->startOfDay();
            return [$day, $day->copy()->endOfDay()];
        }

        $range = strtolower((string) ($params['date_range'] ?? 'week'));

        if ($range === 'today') {
            $today = Carbon::today();
            return [$today, $today->copy()->endOfDay()];
        }

        // Default: next 7 days.
        return [Carbon::today(), Carbon::today()->addDays(7)->endOfDay()];
    }

    /**
     * @return list<int>
     */
    private function resolvePhotographers(array $params): array
    {
        if (!empty($params['photographer_id'])) {
            return [(int) $params['photographer_id']];
        }

        return User::query()
            ->where('role', 'photographer')
            ->whereIn('account_status', ['active', 'enabled'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->take(10)
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @return list<array<string, mixed>>
     */
    private function summarizeSlots(array $slots): array
    {
        $out = [];
        foreach (array_slice($slots, 0, 8) as $slot) {
            $start = $slot['start'] ?? $slot['start_time'] ?? null;
            $end = $slot['end'] ?? $slot['end_time'] ?? null;
            if (!$start) {
                continue;
            }
            $out[] = [
                'date' => Carbon::parse((string) $start)->toDateString(),
                'start' => Carbon::parse((string) $start)->format('g:i A'),
                'end' => $end ? Carbon::parse((string) $end)->format('g:i A') : null,
            ];
        }
        return $out;
    }
}
