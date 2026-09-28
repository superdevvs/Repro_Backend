<?php

namespace App\Services\Shoots;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Order the authorized schedule before selecting a page, without loading its media. */
class ShootListOrdering
{
    public function mode(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['next_up', 'date_asc', 'date_desc'], true) ? $value : null;
    }

    public function paginate(Builder $query, string $mode, int $perPage, int $page): LengthAwarePaginator
    {
        $ids = $this->orderedIds($query, $mode);
        $page = max(1, $page);
        $pageIds = array_slice($ids, ($page - 1) * $perPage, $perPage);
        $pageQuery = (clone $query)->reorder()->whereIn('shoots.id', $pageIds);
        // Match paginate(): its page size replaces any earlier discovery limit.
        $pageQuery->getQuery()->limit = null;
        $pageQuery->getQuery()->offset = null;
        $rows = $pageIds ? $pageQuery->get() : collect();
        $positions = array_flip($pageIds);
        $rows = $rows->sortBy(fn ($shoot) => $positions[$shoot->id])->values();

        return new LengthAwarePaginator($rows, count($ids), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function get(Builder $query, string $mode): Collection
    {
        $positions = array_flip($this->orderedIds($query, $mode));

        return (clone $query)->reorder()->get()->sortBy(fn ($shoot) => $positions[$shoot->id] ?? PHP_INT_MAX)->values();
    }

    private function orderedIds(Builder $query, string $mode): array
    {
        // SQLite cannot convert IANA zones in SQL. Read only scheduling keys,
        // resolving legacy local clocks and zoned bookings like the UI does.
        $projection = (clone $query)->withoutEagerLoads()->reorder()->select([
            'shoots.id', 'shoots.scheduled_date', 'shoots.scheduled_at', 'shoots.time', 'shoots.timezone',
        ])->selectSub(User::query()->select('timezone')->whereColumn('users.id', 'shoots.photographer_id'), 'photographer_timezone')->toBase();
        $projection->limit = null;
        $projection->offset = null;
        $keys = [];
        $now = Carbon::now()->startOfMinute();
        foreach ($projection->cursor() as $row) {
            $keys[] = $this->scheduleKey($row, $now);
        }
        if ($mode === 'next_up') {
            $this->anchorUnknownTimes($keys);
        }
        usort($keys, function (array $a, array $b) use ($mode): int {
            if ($a['date'] === null || $b['date'] === null) {
                return ($a['date'] === null) <=> ($b['date'] === null) ?: $a['id'] <=> $b['id'];
            }
            if ($mode === 'next_up') {
                return $a['past'] <=> $b['past']
                    ?: ($a['instant'] <=> $b['instant']) * ($a['past'] ? -1 : 1)
                    ?: ($a['time'] === null) <=> ($b['time'] === null)
                    ?: $a['id'] <=> $b['id'];
            }
            $direction = $mode === 'date_desc' ? -1 : 1;
            $dateOrder = strcmp($a['date'], $b['date']) * $direction;
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            if ($a['time'] === null || $b['time'] === null) {
                return ($a['time'] === null) <=> ($b['time'] === null) ?: $a['id'] <=> $b['id'];
            }

            return strcmp($a['time'], $b['time']) * $direction ?: $a['id'] <=> $b['id'];
        });

        return array_column($keys, 'id');
    }

    private function anchorUnknownTimes(array &$keys): void
    {
        // Give TBD appointments a fixed day boundary rather than a pairwise
        // same-day override, which would break chronological transitivity.
        $edges = [];
        foreach ($keys as $key) {
            if ($key['date'] === null || $key['time'] === null) {
                continue;
            }
            $bucket = $key['date'].'|'.(int) $key['past'];
            $edge = $edges[$bucket] ?? $key['instant'];
            $edges[$bucket] = $key['past'] ? min($edge, $key['instant']) : max($edge, $key['instant']);
        }
        foreach ($keys as $index => $key) {
            if ($key['date'] === null || $key['time'] !== null) {
                continue;
            }
            $edge = $edges[$key['date'].'|'.(int) $key['past']] ?? $key['instant'];
            $keys[$index]['instant'] = $key['past']
                ? min($key['instant'], $edge - 1)
                : max($key['instant'], $edge + 1);
        }
    }

    private function scheduleKey(object $row, Carbon $now): array
    {
        $shootZone = $this->validTimezone($row->timezone);
        // Explicit shoot zones describe the clock displayed by getShootSchedule.
        // Legacy unzoned bookings follow ScheduleInstantResolver's photographer/app zone.
        $timezone = $shootZone ?? $this->validTimezone($row->photographer_timezone)
            ?? $this->validTimezone(config('app.timezone')) ?? 'UTC';
        $fallback = null;
        if ($row->scheduled_at) {
            try {
                $fallback = Carbon::parse($row->scheduled_at, 'UTC');
                if ($shootZone) {
                    $fallback->setTimezone($shootZone);
                }
            } catch (\Throwable) {
                // Invalid legacy values belong after dated appointments.
            }
        }
        $date = $this->localDate($row->scheduled_date) ?? $fallback?->format('Y-m-d');
        $time = $this->clockTime($row->time) ?? $fallback?->format('H:i');
        // Build the real instant from the authoritative clock shown in the UI,
        // never from an inconsistent legacy scheduled_at when booking fields exist.
        $appointment = $date === null ? null : Carbon::createFromFormat('!Y-m-d H:i', $date.' '.($time ?? '00:00'), $timezone);
        $localNow = $now->copy()->setTimezone($timezone);
        $past = $date !== null && ($time === null
            ? $date < $localNow->format('Y-m-d')
            : $appointment->getTimestamp() < $now->getTimestamp());
        if ($appointment && $time === null && ! $past) {
            $appointment->endOfDay();
        }

        return ['id' => (int) $row->id, 'date' => $date, 'time' => $time, 'past' => $past, 'instant' => $appointment?->getTimestamp()];
    }

    private function localDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $parts)) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? substr($value, 0, 10) : null;
    }

    private function clockTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm)?$/i', trim($value), $parts)) {
            return null;
        }
        $hour = (int) $parts[1];
        if ((int) $parts[2] > 59 || (int) ($parts[3] ?? 0) > 59) {
            return null;
        }
        if (! empty($parts[4])) {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = $hour % 12 + (strtolower($parts[4]) === 'pm' ? 12 : 0);
        } elseif ($hour > 23) {
            return null;
        }

        // The booking UI displays and compares appointments to minute precision.
        return sprintf('%02d:%s', $hour, $parts[2]);
    }

    private function validTimezone(mixed $value): ?string
    {
        static $zones = null;
        $zones ??= array_fill_keys(timezone_identifiers_list(), true);
        $value = is_string($value) ? trim($value) : '';

        return isset($zones[$value]) ? $value : null;
    }
}
