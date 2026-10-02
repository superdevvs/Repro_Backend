<?php

namespace App\Services\Scheduling;

use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/** Reservations contain only our usage accounting, never Google route content. */
class GoogleRoutesBudget
{
    public function reserve(int $elements = 1): bool
    {
        if ($elements < 1 || $elements > $this->limit()) {
            return false;
        }
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Reserve route usage outside the booking transaction.');
        }
        // One SQLite statement obtains the writer lock before checking the rolling
        // total. Concurrent requests cannot both spend the last available unit.
        $changed = LockedWrite::run(fn () => DB::affectingStatement(
            'INSERT INTO scheduling_route_usage (elements, reserved_at) '
            .'SELECT ?, ? WHERE COALESCE((SELECT SUM(elements) FROM scheduling_route_usage WHERE reserved_at > ?), 0) + ? <= ?',
            [$elements, now('UTC')->toDateTimeString(), $this->cutoff(), $elements, $this->limit()]
        ), 'scheduling-route-budget');
        if ($changed === 1) {
            $status = $this->status();
            foreach ([75, 90, 100] as $threshold) {
                if ($status['usage_percent'] >= $threshold
                    && (($status['used_elements'] - $elements) / $status['limit_elements'] * 100) < $threshold) {
                    Log::channel('scheduling')->notice('routes_budget_threshold', [
                        'threshold' => $threshold, 'used_elements' => $status['used_elements'],
                        'limit_elements' => $status['limit_elements'],
                    ]);
                }
            }
        }

        // Never refund uncertain attempts: Google may have processed a timeout.
        return $changed === 1;
    }

    public function status(): array
    {
        $used = (int) DB::table('scheduling_route_usage')->where('reserved_at', '>', $this->cutoff())->sum('elements');
        $recent = DB::table('scheduling_route_usage')->where('reserved_at', '>', now('UTC')->subDays(7)->toDateTimeString());
        $recentUnits = (int) (clone $recent)->sum('elements');
        $oldest = (clone $recent)->min('reserved_at');
        $observationDays = $oldest ? max(1, min(7, \Carbon\Carbon::parse($oldest, 'UTC')->diffInSeconds(now('UTC')) / 86400)) : 7;
        $forecast = (int) ceil($recentUnits / $observationDays * 31);
        $percent = round($used / $this->limit() * 100, 2);
        $forecastPercent = round($forecast / $this->limit() * 100, 2);

        return [
            'used_elements' => $used, 'limit_elements' => $this->limit(),
            'remaining_elements' => max(0, $this->limit() - $used),
            'usage_percent' => $percent, 'alert_level' => $this->alertLevel($percent),
            'budget_usd' => 100, 'estimated_cost_usd' => round($used * 0.01, 2),
            'rolling_days' => 31, 'window_started_at' => $this->cutoff(),
            'forecast_elements' => $forecast, 'forecast_percent' => $forecastPercent,
            'forecast_alert_level' => $this->alertLevel($forecastPercent),
            'exhausted' => $used >= $this->limit(),
        ];
    }

    private function limit(): int
    {
        return max(1, min(10000, (int) config('services.google_routes.limit_elements', 10000)));
    }

    private function cutoff(): string
    {
        return now('UTC')->subDays(31)->toDateTimeString();
    }

    private function alertLevel(float $percent): int
    {
        return $percent >= 100 ? 100 : ($percent >= 90 ? 90 : ($percent >= 75 ? 75 : 0));
    }
}
