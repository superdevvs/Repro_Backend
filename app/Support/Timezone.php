<?php

namespace App\Support;

/**
 * Boundary-only timezone normalization (Req 12.4, 12.5, 12.6).
 *
 * `Asia/Calcutta` is the deprecated alias of `Asia/Kolkata`; both name the same
 * IANA zone, so scheduling behavior must treat them as equal. These helpers are
 * applied ONLY at application boundaries (data entering/leaving the app) and
 * during comparison or display.
 *
 * They MUST NOT be used to rewrite stored dates/times: persisted timezone values
 * are left exactly as stored. `Asia/Kolkata` is used as the canonical identifier
 * for NEW writes only, while `Asia/Calcutta` continues to be accepted as an alias
 * on read/compare. Because normalization only maps an alias to its canonical name
 * (never converting wall-clock time), it cannot shift any stored time.
 */
class Timezone
{
    /** Canonical IANA identifier preferred for new writes. */
    public const CANONICAL = 'Asia/Kolkata';

    /**
     * Map of deprecated/aliased IANA timezone identifiers to their canonical name.
     * Keys are lower-cased; lookup is case-insensitive.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'asia/calcutta' => self::CANONICAL,
    ];

    /**
     * Normalize a named timezone to its canonical IANA identifier for comparison
     * or display. Maps `Asia/Calcutta` -> `Asia/Kolkata` and leaves all other
     * values untouched (aside from trimming surrounding whitespace).
     *
     * This is a pure, boundary-only transform. It never mutates stored values;
     * callers use the returned name only for comparison, display, or as the
     * identifier for a new write. Null/empty inputs are returned as an empty
     * string, so the helper never throws.
     */
    public static function normalize(?string $name): string
    {
        $trimmed = trim((string) $name);

        if ($trimmed === '') {
            return '';
        }

        return self::ALIASES[strtolower($trimmed)] ?? $trimmed;
    }

    /**
     * Determine whether two named timezones refer to the same zone, treating
     * accepted aliases (e.g. `Asia/Calcutta` and `Asia/Kolkata`) as equal.
     * Comparison is boundary-only and does not read or modify any stored value.
     */
    public static function isSame(?string $a, ?string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
