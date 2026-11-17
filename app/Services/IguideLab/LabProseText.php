<?php
namespace App\Services\IguideLab;

use Illuminate\Support\Facades\Log;

/**
 * Over-long model prose (notes, descriptions, summaries, reasons) is shortened to its bound instead of discarding an
 * answer; the same rule as staging_assessment_protocol.fit_prose in Python. Only the named prose fields are touched:
 * ids, enums, hashes, prompts and every other bound stay strict in the caller's validator. Each clip is logged
 * (internal only; public DTOs never gain a key).
 */
final class LabProseText
{
    public const KEYS = ['notes','description','summary','rationale','reason','purpose','uncertainties','requestedDetails','sourceArtifacts'];
    public const ELLIPSIS = '…';

    /** At most $max code points (Python len), cut at a nearby word boundary, ending with an ellipsis. */
    public static function clip(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        if ($max < 2) return mb_substr($text, 0, $max);
        $head = mb_substr($text, 0, $max - 1);
        if (! preg_match('/\A\s\z/u', mb_substr($text, $max - 1, 1))
            && preg_match('/\A(.*\S)\s+\S*\z/us', $head, $m) && mb_strlen($m[1]) >= intdiv($max, 2)) $head = $m[1];
        $head = rtrim(preg_replace('/[,;:\-–—]+\z/u', '', rtrim($head)) ?? $head);
        return ($head === '' ? mb_substr($text, 0, $max - 1) : $head).self::ELLIPSIS;
    }

    /**
     * Clips prose by explicit path => max. A path is dot-separated keys; '*' matches one key or list index and a
     * leading '**.' any depth. Values that are not strings, or are within bound, are returned unchanged.
     */
    public static function fitPaths(mixed $value, array $limits, string $protocol): mixed
    {
        return self::walkPaths($value, [], $limits, $protocol);
    }

    /** Clips prose keys (KEYS) of a decoded answer by its JSON-schema maxLength; no enum or pattern string is touched. */
    public static function fitSchema(mixed $value, array $schema, string $protocol, bool $prose = false, string $path = '$'): mixed
    {
        $types = (array) ($schema['type'] ?? []);
        if (is_array($value) && $value !== [] && ! array_is_list($value) && in_array('object', $types, true) && is_array($schema['properties'] ?? null)) {
            foreach ($value as $key => $child)
                if (isset($schema['properties'][$key])) $value[$key] = self::fitSchema($child, $schema['properties'][$key], $protocol, in_array($key, self::KEYS, true), $path.'.'.$key);
            return $value;
        }
        if (is_array($value) && array_is_list($value) && in_array('array', $types, true) && is_array($schema['items'] ?? null)) {
            foreach ($value as $i => $child) $value[$i] = self::fitSchema($child, $schema['items'], $protocol, $prose, $path.'['.$i.']');
            return $value;
        }
        if ($prose && is_string($value) && in_array('string', $types, true) && ! isset($schema['enum']) && ! isset($schema['pattern'])
            && is_int($schema['maxLength'] ?? null) && mb_strlen($value) > $schema['maxLength']) return self::clipped($value, $schema['maxLength'], $protocol, $path);
        return $value;
    }

    private static function walkPaths(mixed $value, array $path, array $limits, string $protocol): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) $value[$key] = self::walkPaths($child, [...$path, (string) $key], $limits, $protocol);
            return $value;
        }
        if (! is_string($value)) return $value;
        foreach ($limits as $pattern => $max)
            if (self::matches(explode('.', $pattern), $path) && mb_strlen($value) > $max) return self::clipped($value, $max, $protocol, '$.'.implode('.', $path));
        return $value;
    }

    private static function matches(array $pattern, array $path): bool
    {
        if ($pattern === []) return $path === [];
        if ($pattern[0] === '**') {
            for ($i = 0; $i <= count($path); $i++) if (self::matches(array_slice($pattern, 1), array_slice($path, $i))) return true;
            return false;
        }
        return $path !== [] && ($pattern[0] === '*' || $pattern[0] === $path[0]) && self::matches(array_slice($pattern, 1), array_slice($path, 1));
    }

    private static function clipped(string $value, int $max, string $protocol, string $path): string
    {
        Log::warning('iguide-lab.prose_clipped', ['protocol' => $protocol, 'path' => $path, 'length' => mb_strlen($value), 'maxLength' => $max]);
        return self::clip($value, $max);
    }
}
