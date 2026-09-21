<?php

namespace App\Services\Voice;

/** Calls-only timezone boundaries for hosts without the optional tzdata aliases. */
final class VoiceTimezone
{
    public static function normalize(mixed $timezone): mixed
    {
        // Keep malformed input unchanged so the normal validator rejects it.
        // UTC is portable and should retain its familiar spelling.
        if (! is_string($timezone) || $timezone === 'UTC') {
            return $timezone;
        }

        static $aliases = null;
        $aliases ??= require __DIR__.'/../../../resources/data/voice-timezone-aliases.php';

        // Resolve only exact, published IANA links. Never construct the alias:
        // production may not have its zoneinfo file, even with ALL_WITH_BC.
        return $aliases[$timezone] ?? $timezone;
    }

    public static function normalizeWindows(array $input): array
    {
        foreach (['business_hours', 'quiet_hours'] as $window) {
            if (is_array($input[$window] ?? null) && array_key_exists('timezone', $input[$window])) {
                $input[$window]['timezone'] = self::normalize($input[$window]['timezone']);
            }
        }

        return $input;
    }
}
