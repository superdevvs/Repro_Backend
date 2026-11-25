<?php

namespace App\Support\Cutover;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * What one process sees: engine, database, server identity and the raw route
 * values of its live config (MySQL cutover design v2, 3.2 step 11 and 5.3).
 *
 * Shared by `cutover:smoke` (every wrapper profile context) and
 * scripts/cutover/probe/fpm-engine.php (FPM, through FastCGI), so both print the
 * same values. It only reads, inside a read-only database session.
 */
final class CutoverEngineProbe
{
    /** @return array<string, mixed> */
    public static function measure(Application $app): array
    {
        $config = $app['config']->all();
        $problems = [];
        $engine = CutoverRouteValues::engine($config);
        $routeValues = null;
        try {
            $routeValues = CutoverRouteValues::fromConfig($config);
        } catch (UnexpectedValueException $refusal) {
            $problems[] = 'route values: '.$refusal->getMessage();
        }

        $name = $config['database']['default'] ?? null;
        $server = ['database' => null, 'server_uuid' => null, 'server_version' => null];
        try {
            $connection = $app['db']->connection(is_string($name) ? $name : null);
            $server = self::readOnly($connection, fn () => self::server($connection));
            if ($connection->getDriverName() !== $engine) {
                $problems[] = "the connection runs {$connection->getDriverName()}, the config names {$engine}";
            }
        } catch (Throwable $error) {
            $problems[] = 'database: '.self::message($error);
        }

        return [
            'engine' => $engine,
            'connection' => $name,
            ...$server,
            'config_cached' => $app->configurationIsCached(),
            'config_path' => $app->getCachedConfigPath(),
            'process' => self::process(),
            'route_values' => $routeValues,
            'problems' => $problems,
        ];
    }

    /**
     * Run $read in a session that the database itself keeps from writing, then
     * put the session back as it was.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $read
     * @return TReturn
     */
    public static function readOnly(Connection $connection, Closure $read): mixed
    {
        switch ($connection->getDriverName()) {
            case 'sqlite':
                $before = (int) $connection->selectOne('PRAGMA query_only')->query_only;
                $connection->statement('PRAGMA query_only = ON');
                try {
                    return $read();
                } finally {
                    $connection->statement('PRAGMA query_only = '.($before ? 'ON' : 'OFF'));
                }
            case 'mysql':
            case 'mariadb':
                $before = (int) $connection->selectOne('SELECT @@SESSION.transaction_read_only AS read_only')->read_only;
                $connection->statement('SET SESSION TRANSACTION READ ONLY');
                try {
                    return $read();
                } finally {
                    $connection->statement('SET SESSION TRANSACTION '.($before ? 'READ ONLY' : 'READ WRITE'));
                }
            default:
                throw new RuntimeException("engine {$connection->getDriverName()} is not supported");
        }
    }

    /** Process identity as the wrapper profile set it up. */
    public static function process(): array
    {
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $egid = function_exists('posix_getegid') ? posix_getegid() : null;

        return [
            'euid' => $euid,
            'user' => $euid !== null && function_exists('posix_getpwuid') ? (posix_getpwuid($euid)['name'] ?? null) : null,
            'egid' => $egid,
            'group' => $egid !== null && function_exists('posix_getgrgid') ? (posix_getgrgid($egid)['name'] ?? null) : null,
            'cwd' => getcwd() ?: null,
            'umask' => sprintf('%04o', umask()),
        ];
    }

    /** One short line, without anything that could carry a secret beyond what the driver already says. */
    public static function message(Throwable $error): string
    {
        return substr(preg_replace('/\s+/', ' ', $error->getMessage()) ?? '', 0, 500);
    }

    /** @return array{database: ?string, server_uuid: ?string, server_version: ?string} */
    private static function server(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            $main = collect($connection->select('PRAGMA database_list'))->firstWhere('name', 'main');

            return [
                'database' => $main?->file,
                'server_uuid' => null,
                'server_version' => (string) $connection->selectOne('SELECT sqlite_version() AS version')->version,
            ];
        }
        $row = $connection->selectOne('SELECT @@server_uuid AS server_uuid, DATABASE() AS name, @@version AS version');

        return ['database' => $row->name, 'server_uuid' => $row->server_uuid, 'server_version' => $row->version];
    }
}
