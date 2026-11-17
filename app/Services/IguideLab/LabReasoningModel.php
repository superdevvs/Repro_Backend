<?php
namespace App\Services\IguideLab;

/**
 * The lab's single text/vision reasoning model (config/iguide-lab-models.php). New requests use current(). Stored
 * records are validated only against the allow-list and their own receipts (what they requested and what the
 * provider echoed), never against current(): a record made with any allow-listed model stays valid after the
 * setting changes. Python protocols keep the same list (staging_assessment_protocol.REASONING_MODELS).
 */
final class LabReasoningModel
{
    /** A constant stand-in for pinned implementation snapshots: the model value itself must never change a pin. */
    public const SETTING = 'config:iguide-lab-models.reasoning';

    /** The model a stored request is being recompiled with (replay()); null = current(). */
    private static ?string $replay = null;

    /** The model new lab reasoning requests use. Throws when the setting is not allow-listed: never a silent fallback. */
    public static function current(): string
    {
        if (self::$replay !== null) return self::$replay;
        $model = config('iguide-lab-models.reasoning');
        if (! self::requestable($model)) {
            throw new LabReasoningModelException('IGUIDE_LAB_REASONING_MODEL ('.(is_string($model) ? $model : get_debug_type($model))
                .') is not one of the allowed lab reasoning models: '.implode(', ', self::allowed()).'.');
        }

        return $model;
    }

    /** @return list<string> */
    public static function allowed(): array
    {
        $allowed = config('iguide-lab-models.allowed');
        if (! is_array($allowed) || ! array_is_list($allowed) || $allowed === [] || array_filter($allowed, fn ($m) => ! is_string($m) || $m === '') !== []) {
            throw new LabReasoningModelException('config iguide-lab-models.allowed must be a non-empty list of model ids.');
        }

        return $allowed;
    }

    /** An allow-listed model id exactly as a request body names it. */
    public static function requestable(mixed $model): bool
    {
        return is_string($model) && in_array($model, self::allowed(), true);
    }

    /** An allow-listed model id, or a dated snapshot of one (gpt-6.1-terra-2026-11-01), as a provider echoes it. */
    public static function known(mixed $model): bool
    {
        if (! is_string($model)) return false;
        foreach (self::allowed() as $allowed) if (self::echoes($allowed, $model)) return true;

        return false;
    }

    /** The provider's model echo is the requested allow-listed model, exactly or as its dated snapshot. */
    public static function echoes(mixed $requested, mixed $echo): bool
    {
        return self::requestable($requested) && is_string($echo)
            && ($echo === $requested || preg_match('/\A'.preg_quote($requested, '/').'-\d{4}-\d{2}-\d{2}\z/D', $echo) === 1);
    }

    /** The one allow-listed model every provider attempt requested; null when attempts are missing or disagree. */
    public static function attempted(mixed $attempts): ?string
    {
        if (! is_array($attempts) || $attempts === []) return null;
        $models = array_values(array_unique(array_map(fn ($attempt) => is_array($attempt) ? ($attempt['requestedModelId'] ?? null) : null, $attempts), SORT_REGULAR));

        return count($models) === 1 && self::requestable($models[0]) ? $models[0] : null;
    }

    /**
     * The model of a request body this lab json_encode()d itself ("model" is always its first key). Only the model to
     * replay() with: the recompiled body must still match the stored body hash.
     */
    public static function ofPayload(string $json): string
    {
        if (preg_match('/\A\{"model":"([A-Za-z0-9._-]{1,100})"/', $json, $match) !== 1 || ! self::requestable($match[1])) {
            throw new \InvalidArgumentException('The stored request does not name an allow-listed model.');
        }

        return $match[1];
    }

    /** ofPayload() for a stored request file, reading only its first bytes. */
    public static function ofPayloadFile(string $path): string
    {
        $head = is_file($path) && ! is_link($path) ? @file_get_contents($path, false, null, 0, 256) : false;

        return self::ofPayload(is_string($head) ? $head : '');
    }

    /**
     * The model for an immutable compile request file: the one it already names (a rerun of a reserved compile keeps
     * its model, so the request bytes stay identical), else current() for a new request.
     */
    public static function forRequestFile(string $path): string
    {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) return self::current();
        $raw = ! is_link($path) && is_file($path) && filesize($path) <= 1024 * 1024 ? @file_get_contents($path) : false;
        $value = is_string($raw) ? json_decode($raw, true) : null;
        $model = is_array($value) ? ($value['model'] ?? null) : null;
        if (! self::requestable($model)) throw new \InvalidArgumentException('The reserved compile request does not name an allow-listed model.');

        return $model;
    }

    /**
     * Run $compile with current() returning $model, to recompile a stored request with the model it was sent with.
     * Null runs it with the configured model.
     */
    public static function replay(?string $model, callable $compile): mixed
    {
        if ($model === null) return $compile();
        if (! self::requestable($model)) throw new \InvalidArgumentException('The stored request names a model that is not allow-listed.');
        $previous = self::$replay;
        self::$replay = $model;
        try {
            return $compile();
        } finally {
            self::$replay = $previous;
        }
    }
}
