<?php

namespace Tests\Unit\Database;

use Tests\TestCase;

/**
 * The one-writer rule on MySQL: exactly one connection name carries the writer
 * token, with fixed session settings. Every other MySQL connection is exempt and
 * restricted to non-business tables. Loads the real config files on any engine.
 */
class MySqlWriterPolicyConfigTest extends TestCase
{
    private function loadConfig(string $file, array $env): array
    {
        $names = array_unique([...array_keys($env), 'DB_CONNECTION', 'REPRO_MYSQL_WRITER_POLICY',
            'REPRO_MYSQL_WRITER_LONG_HOLD_MS', 'REPRO_MYSQL_SCHEDULING_CACHE', 'DB_CACHE_LOCK_CONNECTION',
            'APP_ENV', 'REPRO_MYSQL_LEVEL0_LOCK_GUARD']);
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        try {
            foreach ($env as $name => $value) {
                putenv("$name=$value");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }

            return require config_path($file);
        } finally {
            foreach ($saved as $name => [$getenv, $env, $server]) {
                $getenv === false ? putenv($name) : putenv("$name=$getenv");
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    public function test_main_mysql_connection_carries_the_policy_with_fixed_session_settings(): void
    {
        $mysql = $this->loadConfig('database.php', ['DB_CONNECTION' => 'mysql'])['connections']['mysql'];

        $this->assertTrue($mysql['repro_writer_policy']);
        $this->assertSame('READ COMMITTED', $mysql['isolation_level']);
        $this->assertSame(5, $mysql['repro_lock_wait_timeout']);
        $this->assertSame(1000, $mysql['repro_writer_long_hold_ms']);
    }

    public function test_a_row_lock_outside_a_transaction_is_an_error_in_tests_and_a_log_line_elsewhere(): void
    {
        $testing = $this->loadConfig('database.php', ['DB_CONNECTION' => 'mysql', 'APP_ENV' => 'testing']);
        $production = $this->loadConfig('database.php', ['DB_CONNECTION' => 'mysql', 'APP_ENV' => 'production']);

        $this->assertSame('throw', $testing['connections']['mysql']['repro_level0_lock_guard']);
        $this->assertSame('log', $production['connections']['mysql']['repro_level0_lock_guard']);
    }

    public function test_policy_is_off_only_when_explicitly_disabled(): void
    {
        $off = $this->loadConfig('database.php', ['DB_CONNECTION' => 'mysql', 'REPRO_MYSQL_WRITER_POLICY' => 'false']);
        $on = $this->loadConfig('database.php', ['DB_CONNECTION' => 'mysql', 'REPRO_MYSQL_WRITER_POLICY' => 'true']);

        $this->assertFalse($off['connections']['mysql']['repro_writer_policy']);
        $this->assertTrue($on['connections']['mysql']['repro_writer_policy']);
        // The session settings stay fixed whether or not the token is taken.
        $this->assertSame('READ COMMITTED', $off['connections']['mysql']['isolation_level']);
        $this->assertSame(5, $off['connections']['mysql']['repro_lock_wait_timeout']);
    }

    public function test_exactly_one_mysql_connection_name_carries_the_token(): void
    {
        $connections = $this->loadConfig('database.php', ['DB_CONNECTION' => 'mysql'])['connections'];
        $mysql = array_filter($connections, fn (array $config) => ($config['driver'] ?? null) === 'mysql');

        $carriers = array_keys(array_filter($mysql, fn (array $config) => ($config['repro_writer_policy'] ?? null) === true));
        $this->assertSame(['mysql'], $carriers);

        // Every other MySQL connection is exempt and limited to bookkeeping tables.
        $exempt = array_diff_key($mysql, ['mysql' => true]);
        $this->assertEqualsCanonicalizing(['mysql_queue_worker', 'mysql_scheduling_cache', 'mysql_cache_locks'], array_keys($exempt));
        foreach ($exempt as $name => $config) {
            $this->assertFalse($config['repro_writer_policy'], $name);
        }
        $this->assertSame(['cache_locks'], $mysql['mysql_cache_locks']['repro_writer_exempt_tables']);
        $this->assertSame(['jobs'], $mysql['mysql_queue_worker']['repro_writer_exempt_tables']);
        $this->assertSame(['cache', 'cache_locks'], $mysql['mysql_scheduling_cache']['repro_writer_exempt_tables']);
        // Same account and session settings as the business connection, without the token.
        $this->assertSame($mysql['mysql']['username'], $mysql['mysql_cache_locks']['username']);
        $this->assertSame(5, $mysql['mysql_cache_locks']['repro_lock_wait_timeout']);
    }

    public function test_database_cache_locks_get_their_own_connection_on_mysql_only(): void
    {
        $mysql = $this->loadConfig('cache.php', ['DB_CONNECTION' => 'mysql'])['stores'];
        $sqlite = $this->loadConfig('cache.php', ['DB_CONNECTION' => 'sqlite'])['stores'];
        $pilot = $this->loadConfig('cache.php', ['DB_CONNECTION' => 'mysql', 'REPRO_MYSQL_SCHEDULING_CACHE' => 'true'])['stores'];

        $this->assertSame('mysql_cache_locks', $mysql['database']['lock_connection']);
        $this->assertSame('mysql_cache_locks', $mysql['scheduling']['lock_connection']);
        $this->assertNull($mysql['database']['connection']);
        $this->assertNull($mysql['scheduling']['connection']);

        $this->assertNull($sqlite['database']['lock_connection']);
        $this->assertNull($sqlite['scheduling']['lock_connection']);

        // The scheduling-cache pilot keeps its separately granted session for both.
        $this->assertSame('mysql_scheduling_cache', $pilot['scheduling']['connection']);
        $this->assertSame('mysql_scheduling_cache', $pilot['scheduling']['lock_connection']);
    }
}
