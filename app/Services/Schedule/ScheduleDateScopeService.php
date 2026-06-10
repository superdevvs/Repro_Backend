<?php

namespace App\Services\Schedule;

use App\Models\Shoot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScheduleDateScopeService
{
    private const CACHE_PREFIX = 'schedule:local-date';

    public function localDateForShoot(Shoot $shoot): ?string
    {
        return $this->localDateFromValues(
            $shoot->scheduled_at,
            $shoot->scheduled_date,
            $shoot->time,
            $shoot->timezone
        );
    }

    public function originalLocalDateForShoot(Shoot $shoot): ?string
    {
        return $this->localDateFromValues(
            $shoot->getOriginal('scheduled_at'),
            $shoot->getOriginal('scheduled_date'),
            $shoot->getOriginal('time'),
            $shoot->getOriginal('timezone')
        );
    }

    public function localDateFromValues(mixed $scheduledAt, mixed $scheduledDate = null, mixed $time = null, ?string $timezone = null): ?string
    {
        $timezone = $this->validTimezone($timezone);

        if ($scheduledAt) {
            try {
                $date = $scheduledAt instanceof CarbonInterface
                    ? $scheduledAt->copy()
                    : Carbon::parse((string) $scheduledAt, config('app.timezone'));

                return $date->setTimezone($timezone)->toDateString();
            } catch (\Throwable $exception) {
                Log::warning('Unable to resolve scheduled_at local date.', [
                    'scheduled_at' => is_scalar($scheduledAt) ? (string) $scheduledAt : get_debug_type($scheduledAt),
                    'timezone' => $timezone,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($scheduledDate) {
            try {
                return Carbon::parse((string) $scheduledDate, $timezone)->toDateString();
            } catch (\Throwable $exception) {
                Log::warning('Unable to resolve scheduled_date local date.', [
                    'scheduled_date' => is_scalar($scheduledDate) ? (string) $scheduledDate : get_debug_type($scheduledDate),
                    'timezone' => $timezone,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return null;
    }

    public function localDateForScheduledAt(mixed $scheduledAt, ?string $timezone = null): ?string
    {
        return $this->localDateFromValues($scheduledAt, null, null, $timezone);
    }

    public function localTimeForScheduledAt(mixed $scheduledAt, ?string $timezone = null): ?string
    {
        if (!$scheduledAt) {
            return null;
        }

        try {
            $date = $scheduledAt instanceof CarbonInterface
                ? $scheduledAt->copy()
                : Carbon::parse((string) $scheduledAt, config('app.timezone'));

            return $date->setTimezone($this->validTimezone($timezone))->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, Shoot>
     */
    public function shootsForLocalDate(string $date, array $excludedStatuses = [], ?Closure $queryMutator = null): Collection
    {
        return $this->filterByLocalRange(
            $this->candidateQueryForLocalRange($date, $date, $queryMutator)
                ->get(),
            $date,
            $date
        )->reject(fn (Shoot $shoot) => in_array((string) $shoot->status, $excludedStatuses, true))
            ->values();
    }

    public function countForLocalDate(string $date, array $excludedStatuses = [], ?Closure $queryMutator = null): int
    {
        return $this->shootsForLocalDate($date, $excludedStatuses, $queryMutator)->count();
    }

    public function countForLocalRange(string $startDate, string $endDate, array $excludedStatuses = [], ?Closure $queryMutator = null): int
    {
        return $this->filterByLocalRange(
            $this->candidateQueryForLocalRange($startDate, $endDate, $queryMutator)->get(),
            $startDate,
            $endDate
        )->reject(fn (Shoot $shoot) => in_array((string) $shoot->status, $excludedStatuses, true))
            ->count();
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $relations
     * @return Collection<int, Shoot>
     */
    public function upcomingShoots(string $fromDate, int $limit, array $columns = ['*'], array $relations = [], ?Closure $queryMutator = null): Collection
    {
        $query = Shoot::query()
            ->select($columns)
            ->with($relations);

        $this->applyFutureCandidateWindow($query, $fromDate);

        if ($queryMutator) {
            $queryMutator($query);
        }

        return $query
            ->limit(max($limit * 6, $limit))
            ->get()
            ->filter(fn (Shoot $shoot) => ($this->localDateForShoot($shoot) ?? '') >= $fromDate)
            ->sortBy(function (Shoot $shoot) {
                $localTime = $shoot->scheduled_at
                    ? ($this->localTimeForScheduledAt($shoot->scheduled_at, $shoot->timezone) ?? $shoot->time)
                    : $shoot->time;

                return sprintf(
                    '%s %s %010d',
                    $this->localDateForShoot($shoot) ?? '9999-12-31',
                    $localTime ?: '99:99',
                    $shoot->id
                );
            })
            ->take($limit)
            ->values();
    }

    /**
     * Default time-to-live (seconds) for the per-date read-through cache.
     */
    private const DEFAULT_REMEMBER_SECONDS = 600;

    public function rememberForDate(string $date, string $keySuffix, int $seconds, Closure $callback): mixed
    {
        $key = $this->cacheKey($date, $keySuffix);
        $this->registerDateCacheKey($date, $key);

        return Cache::remember($key, now()->addSeconds($seconds), $callback);
    }

    /**
     * Read-through cache for a single calendar date.
     *
     * This is the canonical read seam (Req 8.1, 8.3): the resulting cache key is
     * registered in the same per-date registry that {@see invalidateDate()} /
     * {@see invalidateDates()} bust, guaranteeing that reads and writes agree on
     * the same per-date bucket. Reschedules that move a shoot between two days are
     * handled by passing both the old and new date to {@see invalidateDates()}.
     */
    public function remember(string $date, Closure $loader, string $keySuffix = 'default', ?int $seconds = null): mixed
    {
        return $this->rememberForDate(
            $date,
            $keySuffix,
            $seconds ?? self::DEFAULT_REMEMBER_SECONDS,
            $loader
        );
    }

    /**
     * @param  array<int, string>  $dates
     */
    public function rememberForDates(array $dates, string $keySuffix, int $seconds, Closure $callback): mixed
    {
        $dates = collect($dates)->filter()->unique()->values()->all();
        $key = self::CACHE_PREFIX . ':multi:' . md5(implode('|', $dates) . ':' . $keySuffix);

        foreach ($dates as $date) {
            $this->registerDateCacheKey((string) $date, $key);
        }

        return Cache::remember($key, now()->addSeconds($seconds), $callback);
    }

    public function invalidateShootBuckets(Shoot $shoot): void
    {
        $this->invalidateDates([
            $this->originalLocalDateForShoot($shoot),
            $this->localDateForShoot($shoot),
        ]);
    }

    /**
     * Bust the per-date cache buckets for one or more calendar dates.
     *
     * Writes (create / update / reschedule) call this with the affected
     * date(s) — e.g. [oldDate, newDate] on a reschedule — within the same
     * request so the Schedule_View reflects the change immediately (Req 8.1,
     * 8.3). Because each date is invalidated through the same per-date registry
     * used by {@see remember()} / {@see rememberForDate()}, the invalidation
     * keys match the read keys exactly.
     *
     * @param  array<int, string|null>  $dates
     */
    public function invalidateDates(array $dates): void
    {
        $dates = collect($dates)->filter()->unique()->values();

        foreach ($dates as $date) {
            $this->invalidateDate((string) $date);
        }
    }

    public function invalidateDate(string $date): void
    {
        $registryKey = $this->registryKey($date);
        foreach ((array) Cache::get($registryKey, []) as $key) {
            if (is_string($key) && $key !== '') {
                Cache::forget($key);
            }
        }

        Cache::forget($registryKey);
    }

    protected function candidateQueryForLocalRange(string $startDate, string $endDate, ?Closure $queryMutator = null): Builder
    {
        $query = Shoot::query();

        $start = Carbon::parse($startDate, config('app.timezone'))->subDay()->startOfDay();
        $end = Carbon::parse($endDate, config('app.timezone'))->addDay()->endOfDay();

        $query->where(function (Builder $scope) use ($start, $end, $startDate, $endDate) {
            $scope->whereBetween('scheduled_at', [$start, $end])
                ->orWhereBetween('scheduled_date', [$startDate, $endDate]);
        });

        if ($queryMutator) {
            $queryMutator($query);
        }

        return $query;
    }

    protected function applyFutureCandidateWindow(Builder $query, string $fromDate): void
    {
        $start = Carbon::parse($fromDate, config('app.timezone'))->subDay()->startOfDay();

        $query->where(function (Builder $scope) use ($start, $fromDate) {
            $scope->where('scheduled_at', '>=', $start)
                ->orWhere('scheduled_date', '>=', $fromDate);
        });
    }

    /**
     * @param  Collection<int, Shoot>  $shoots
     * @return Collection<int, Shoot>
     */
    protected function filterByLocalRange(Collection $shoots, string $startDate, string $endDate): Collection
    {
        return $shoots
            ->filter(function (Shoot $shoot) use ($startDate, $endDate) {
                $localDate = $this->localDateForShoot($shoot);

                return $localDate !== null && $localDate >= $startDate && $localDate <= $endDate;
            })
            ->values();
    }

    protected function cacheKey(string $date, string $suffix): string
    {
        return self::CACHE_PREFIX . ':' . $date . ':' . md5($suffix);
    }

    protected function registryKey(string $date): string
    {
        return self::CACHE_PREFIX . ':' . $date . ':keys';
    }

    protected function registerDateCacheKey(string $date, string $key): void
    {
        $registryKey = $this->registryKey($date);
        $keys = collect((array) Cache::get($registryKey, []))
            ->push($key)
            ->filter()
            ->unique()
            ->values()
            ->all();

        Cache::put($registryKey, $keys, now()->addDay());
    }

    protected function validTimezone(?string $timezone): string
    {
        $timezone = trim((string) ($timezone ?: config('app.timezone')));

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone');
    }
}
