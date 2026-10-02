<?php

namespace App\Services\Media;

/** Browser measurements are untrusted. Keep only bounded numbers and fixed labels. */
final class TransferTelemetry
{
    public static function validate(mixed $value): ?array
    {
        if (! is_array($value)
            || ! in_array($value['direction'] ?? null, ['upload', 'download'], true)
            || ! in_array($value['mediaType'] ?? null, ['raw', 'edited', 'extra'], true)
            || ! in_array($value['outcome'] ?? null, ['confirmed', 'failed', 'cancelled'], true)
            || ! is_bool($value['chunked'] ?? null)) {
            return null;
        }

        foreach (['bytes' => 1099511627776, 'status' => 599] as $key => $max) {
            if (! is_int($value[$key] ?? null) || $value[$key] < 0 || $value[$key] > $max) {
                return null;
            }
        }
        foreach (['transferMs', 'confirmationMs', 'totalMs'] as $key) {
            if (! array_key_exists($key, $value)) {
                return null;
            }
            if ($key !== 'totalMs' && $value[$key] === null) {
                continue;
            }
            if ((! is_int($value[$key]) && ! is_float($value[$key]))
                || ! is_finite((float) $value[$key]) || $value[$key] < 0 || $value[$key] > 86400000) {
                return null;
            }
        }

        return array_intersect_key($value, array_flip([
            'direction', 'mediaType', 'bytes', 'transferMs', 'confirmationMs',
            'totalMs', 'status', 'outcome', 'chunked',
        ]));
    }
}
