<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Schema::hasTable / hasColumn for code that runs on every request.
 *
 * With database.schema_cache.enabled, answers are kept in the database.schema_cache.store cache
 * under the release key (by default a fingerprint of the migration files, frozen by config:cache),
 * so a new release uses new keys. "It exists" is kept for `ttl` seconds. "Missing" is kept only for
 * `negative_ttl` seconds (0 never keeps it), so a table or column added without a new release is
 * seen within that time. When the cache is off or fails, the answer comes straight from the
 * database, as Schema::has* does.
 */
final class SchemaFacts
{
    public static function hasTable(string $table, ?string $connection = null): bool
    {
        return self::remember("table:{$table}", $connection,
            fn () => Schema::connection($connection)->hasTable($table));
    }

    public static function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        return self::remember("column:{$table}.".strtolower($column), $connection,
            fn () => Schema::connection($connection)->hasColumn($table, $column));
    }

    /**
     * @param  callable():bool  $ask
     */
    private static function remember(string $fact, ?string $connection, callable $ask): bool
    {
        $settings = (array) config('database.schema_cache', []);

        if (! ($settings['enabled'] ?? false)) {
            return $ask();
        }

        $key = implode(':', [
            'schema-facts',
            (string) ($settings['release'] ?? ''),
            $connection ?? (string) config('database.default'),
            $fact,
        ]);

        try {
            $store = Cache::store($settings['store'] ?? null);
            $known = $store->get($key);
            if (is_bool($known)) {
                return $known;
            }
        } catch (Throwable) {
            return $ask();
        }

        $exists = $ask();
        $ttl = $exists
            ? max(60, (int) ($settings['ttl'] ?? 86400))
            : (int) ($settings['negative_ttl'] ?? 60);

        if ($ttl > 0) {
            try {
                $store->put($key, $exists, $ttl);
            } catch (Throwable) {
                // A cache that cannot be written only costs the next request a lookup.
            }
        }

        return $exists;
    }
}
