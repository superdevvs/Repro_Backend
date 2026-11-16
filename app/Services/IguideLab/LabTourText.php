<?php
namespace App\Services\IguideLab;

/**
 * Contract text sanitiser (spec 5.7, D19). Lengths are UTF-16 code units, as zod's string .max() counts them.
 * A string that fails the contract's UNSAFE_TEXT rule is replaced by a constant fallback, never partially stripped.
 */
final class LabTourText
{
    /** tourContract.ts:81 (no /u: the control-character class only matches ASCII bytes, as in the reader). */
    public const UNSAFE = '/bearer|accessToken|api[_ -]?key|secret|https?:\/\/|[\x00-\x1f\x7f]/i';
    public const ELLIPSIS = "\u{2026}";

    public static function utf16Length(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function safe(string $s): bool
    {
        return preg_match(self::UNSAFE, $s) === 0;
    }

    /**
     * Clean a model-written string for a contract text field of $min..$max UTF-16 units. Returns $fallback when the
     * value is not a UTF-8 string, fails the unsafe rule, or is shorter than $min after cleaning.
     */
    public static function clean(mixed $s, int $min, int $max, ?string $fallback): ?string
    {
        if (! is_string($s) || ! mb_check_encoding($s, 'UTF-8')) return $fallback;
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        if (! is_string($s)) return $fallback;
        $s = trim($s);
        if (! self::safe($s)) return $fallback;
        if (self::utf16Length($s) > $max) $s = rtrim(self::prefix($s, max(0, $max - 1))).self::ELLIPSIS;
        $length = self::utf16Length($s);
        if ($length < $min) return $fallback;

        return self::safe($s) && $length <= $max ? $s : $fallback;
    }

    /** Longest prefix of at most $max UTF-16 units, without an ellipsis (room and floor names, spec 5.7). */
    public static function truncate(string $s, int $max): string
    {
        return self::prefix(mb_check_encoding($s, 'UTF-8') ? $s : mb_scrub($s, 'UTF-8'), $max);
    }

    /** Never splits a surrogate pair: a code point above U+FFFF (4 UTF-8 bytes) counts 2 units. */
    private static function prefix(string $s, int $max): string
    {
        $out = '';
        $units = 0;
        foreach (mb_str_split($s, 1, 'UTF-8') as $char) {
            $units += strlen($char) === 4 ? 2 : 1;
            if ($units > $max) break;
            $out .= $char;
        }

        return $out;
    }
}
