<?php

namespace App\Services\Scheduling;

/** Adjacent availability rows form continuous working time; real gaps remain. */
class AvailableTimeWindows
{
    public static function merge(iterable $rows, string $startKey = 'start_time', string $endKey = 'end_time'): array
    {
        $ranges = [];
        foreach ($rows as $row) {
            $start = substr((string) data_get($row, $startKey), 0, 5);
            $end = substr((string) data_get($row, $endKey), 0, 5);
            if ($start !== '' && $end > $start) $ranges[] = ['start_time' => $start, 'end_time' => $end];
        }
        usort($ranges, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range['start_time'] <= $merged[$last]['end_time']) {
                $merged[$last]['end_time'] = max($range['end_time'], $merged[$last]['end_time']);
            } else $merged[] = $range;
        }
        return $merged;
    }
}
