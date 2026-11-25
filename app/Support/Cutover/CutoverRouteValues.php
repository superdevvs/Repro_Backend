<?php

namespace App\Support\Cutover;

use UnexpectedValueException;

/**
 * The route values of a Laravel config: what decides where a process reads and
 * writes (MySQL cutover design v2, 1.3).
 *
 * `cutover:smoke --engine-only` and scripts/cutover/probe/fpm-engine.php print
 * these values raw. The controller's Python live config module builds the same
 * values from bootstrap/cache/config.php and hashes them; PHP never computes the
 * route hash. The allowlist, the password rule and the canonical form are pinned
 * by scripts/cutover/probe/route-hash-vectors.json, which both sides test.
 */
final class CutoverRouteValues
{
    public const MYSQL_EXTRAS = ['mysql_cache_locks', 'mysql_queue_worker', 'mysql_scheduling_cache'];

    private const QUEUE_KEYS = ['driver', 'connection', 'table'];

    /** Laravel's ConfigurationUrlParser aliases. */
    private const URL_DRIVERS = ['mysql2' => 'mysql', 'postgres' => 'pgsql', 'postgresql' => 'pgsql', 'sqlite3' => 'sqlite', 'mssql' => 'sqlsrv'];

    /**
     * The engine of the default connection: a url scheme wins over the driver.
     *
     * @param  array<string, mixed>  $config  the whole config, as config()->all()
     */
    public static function engine(array $config): ?string
    {
        $default = $config['database']['default'] ?? null;
        $connection = is_string($default) ? ($config['database']['connections'][$default] ?? null) : null;
        if (! is_array($connection)) {
            return null;
        }
        $url = $connection['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $scheme = strtolower(explode(':', $url, 2)[0]);

            return self::URL_DRIVERS[$scheme] ?? $scheme;
        }

        return is_string($connection['driver'] ?? null) ? $connection['driver'] : null;
    }

    /**
     * @param  array<string, mixed>  $config  the whole config, as config()->all()
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException when a value cannot be printed without a secret
     */
    public static function fromConfig(array $config): array
    {
        $database = (array) ($config['database'] ?? []);
        $connections = (array) ($database['connections'] ?? []);
        $default = $database['default'] ?? null;
        if (! is_string($default) || $default === '') {
            throw new UnexpectedValueException('database.default is not a connection name');
        }
        $chosen = [$default => $connections[$default] ?? null];
        if (self::engine($config) === 'mysql') {
            foreach (self::MYSQL_EXTRAS as $name) {
                $chosen[$name] = $connections[$name] ?? null;
            }
        }

        $cache = (array) ($config['cache'] ?? []);
        $stores = (array) ($cache['stores'] ?? []);
        $cacheDefault = $cache['default'] ?? null;
        $queue = (array) ($config['queue'] ?? []);
        $queues = [];
        foreach ((array) ($queue['connections'] ?? []) as $name => $settings) {
            $queues[$name] = array_intersect_key((array) $settings, array_flip(self::QUEUE_KEYS));
        }
        $key = $config['app']['key'] ?? null;

        $values = self::withoutPasswords([
            'app.key_sha256' => $key === null ? null : hash('sha256', (string) $key),
            'cache.default' => $cacheDefault,
            'cache.stores.default' => is_string($cacheDefault) ? ($stores[$cacheDefault] ?? null) : null,
            'cache.stores.scheduling' => $stores['scheduling'] ?? null,
            'database.connections' => $chosen,
            'database.default' => $default,
            'database.repro_leftover_transactions' => $database['repro_leftover_transactions'] ?? null,
            'queue.connections' => $queues,
            'queue.default' => $queue['default'] ?? null,
        ]);
        self::refuseUrlPasswords($values);

        return $values;
    }

    /** JSON form: every array an object with string keys, as Python's var_export reader returns it. */
    public static function forJson(mixed $value): mixed
    {
        return is_array($value) ? (object) array_map([self::class, 'forJson'], $value) : $value;
    }

    /** Every key named exactly `password`, at any depth, becomes `password_set`. */
    private static function withoutPasswords(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_key_exists('password', $value) && array_key_exists('password_set', $value)) {
            throw new UnexpectedValueException('a config map holds both password and password_set');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if ($key === 'password') {
                $result['password_set'] = ! ($item === null || $item === '');

                continue;
            }
            $result[$key] = self::withoutPasswords($item);
        }

        return $result;
    }

    private static function refuseUrlPasswords(mixed $value, string $path = ''): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if ($key === 'url' && is_string($item) && $item !== '') {
                $parts = parse_url($item);
                if (($parts !== false && ($parts['pass'] ?? '') !== '') || preg_match('#//[^/@]*:[^/@]*@#', $item)) {
                    throw new UnexpectedValueException("the url at {$path}url carries a password; set the password key instead");
                }
            }
            self::refuseUrlPasswords($item, $path.$key.'.');
        }
    }
}
