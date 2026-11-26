<?php

namespace Tests\Feature;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** The deploy check passes on both engines when the configuration is right. */
class WriterPolicyCheckCommandTest extends TestCase
{
    public function test_deploy_check_reports_the_engine_and_passes(): void
    {
        $code = Artisan::call('db:writer-policy-check');
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $code, json_encode($report));
        $this->assertSame(DB::getDriverName(), $report['engine']);
        $this->assertSame([], $report['problems']);
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame('not applicable', $report['writer_policy']);
        } else {
            $this->assertTrue($report['writer_policy']);
            $this->assertTrue($report['token']['acquired']);
        }
    }

    public function test_a_second_mysql_connection_without_a_table_limit_fails_the_check_on_mysql(): void
    {
        config(['database.connections.mysql_extra' => ['driver' => 'mysql', 'repro_writer_policy' => false]]);
        $code = Artisan::call('db:writer-policy-check');
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(0, $code);
        } else {
            $this->assertSame(1, $code);
            $this->assertStringContainsString('mysql_extra', implode(' ', $report['problems']));
        }
    }

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> Environment values to restore. */
    private array $savedEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => [$server, $env, $process]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            putenv($process === false ? $name : $name.'='.$process);
        }
        $this->savedEnvironment = [];
        parent::tearDown();
    }

    /** Set real environment variables, as a deployed .env would, and rebuild the database config from them. */
    private function useEnvironment(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->savedEnvironment[$name] ??= [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
        config(['database' => require config_path('database.php')]);
    }

    /**
     * Answers the check's session query and token transaction as a correctly
     * provisioned server would, and records where it was asked to connect.
     */
    private function provisionedServer(): ConnectionFactory
    {
        $factory = new class(app()) extends ConnectionFactory
        {
            /** @var list<array> */
            public array $made = [];

            public function make(array $config, $name = null)
            {
                $this->made[] = $config;

                return new class
                {
                    public function selectOne($query)
                    {
                        return (object) ['isolation' => 'READ-COMMITTED', 'row_lock_wait' => 5, 'metadata_lock_wait' => 5];
                    }

                    public function transaction($callback)
                    {
                        return $callback();
                    }

                    public function disconnect(): void {}
                };
            }
        };
        $this->app->instance(ConnectionFactory::class, $factory);

        return $factory;
    }

    private function check(): array
    {
        $code = Artisan::call('db:writer-policy-check');

        return [$code, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_a_database_url_in_the_environment_passes_with_the_shipped_connections(): void
    {
        // The shipped sqlite, mariadb, pgsql and sqlsrv templates also read DB_URL, but nothing
        // connects through them. Only the default connection's target is the application's.
        $this->useEnvironment(['DB_CONNECTION' => 'mysql', 'DB_URL' => 'mysql://url-user:url-secret@url-host:3307/url_database']);
        $factory = $this->provisionedServer();

        [$code, $report] = $this->check();

        $this->assertSame([], $report['problems']);
        $this->assertSame(0, $code);
        $this->assertSame('mysql', $report['engine']);
        $this->assertCount(1, $factory->made);
        $this->assertSame(['url-host', 3307, 'url_database'], [
            $factory->made[0]['host'] ?? null, $factory->made[0]['port'] ?? null, $factory->made[0]['database'] ?? null,
        ]);
    }

    public static function databaseUrlOptionsOnTheCacheLockSession(): array
    {
        return [
            'the writer token' => ['repro_writer_policy=true',
                'Connection mysql_cache_locks also carries the writer token; exactly one may.'],
            'a business table' => ['repro_writer_exempt_tables[]=cache_locks&repro_writer_exempt_tables[]=users',
                'Connection mysql_cache_locks skips the writer token for tables outside its bookkeeping scope: users.'],
        ];
    }

    #[DataProvider('databaseUrlOptionsOnTheCacheLockSession')]
    public function test_database_url_options_reach_the_cache_lock_session_as_they_do_in_the_application(
        string $query, string $problem): void
    {
        // mysql_cache_locks inherits DB_URL from the mysql settings, and Laravel lets the URL's
        // query options override a connection's own keys. The queue-worker and scheduling-cache
        // sessions set no URL; the unused templates still do not count.
        $this->useEnvironment(['DB_CONNECTION' => 'mysql',
            'DB_URL' => 'mysql://url-user:url-secret@url-host:3307/url_database?'.$query]);
        $this->provisionedServer();

        [$code, $report] = $this->check();

        $this->assertSame([$problem], $report['problems']);
        $this->assertSame(1, $code);
    }

    public function test_the_shipped_mysql_connection_layout_passes(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql_reporting' => [
            'driver' => 'mysql', 'repro_writer_policy' => false, 'repro_writer_exempt_tables' => [],
        ]]);
        $this->provisionedServer();

        [$code, $report] = $this->check();

        $this->assertSame([], $report['problems']);
        $this->assertSame(0, $code);
    }

    public static function exemptionsOutsideTheirScope(): array
    {
        return [
            'business table on the cache-lock session' => ['mysql_cache_locks', ['cache_locks', 'users'], 'users'],
            'cache values on the cache-lock session' => ['mysql_cache_locks', ['cache_locks', 'cache'], 'cache'],
            'business table on the queue-worker session' => ['mysql_queue_worker', ['jobs', 'shoots'], 'shoots'],
            'business table on the scheduling-cache session' => ['mysql_scheduling_cache', ['cache', 'cache_locks', 'invoices'], 'invoices'],
            'a token-free connection the layout does not know' => ['mysql_reporting', ['jobs'], 'jobs'],
        ];
    }

    #[DataProvider('exemptionsOutsideTheirScope')]
    public function test_a_token_free_connection_may_skip_the_token_only_for_its_own_bookkeeping_tables(
        string $connection, array $tables, string $outside): void
    {
        config(['database.default' => 'mysql', "database.connections.{$connection}" => [
            ...(config("database.connections.{$connection}") ?? ['driver' => 'mysql']),
            'repro_writer_policy' => false, 'repro_writer_exempt_tables' => $tables,
        ]]);
        $this->provisionedServer();

        [$code, $report] = $this->check();

        $this->assertSame(1, $code, json_encode($report));
        $problems = implode(' ', $report['problems']);
        $this->assertStringContainsString($connection, $problems);
        $this->assertStringContainsString($outside, $problems);
    }

    public function test_the_check_connects_to_the_database_url_target_as_the_application_does(): void
    {
        config([
            'database.default' => 'mysql_url_probe',
            'database.connections.mysql_url_probe' => [
                'driver' => 'mysql', 'url' => 'mysql://url-user@url-host:3307/url_database',
                'host' => 'fallback-host', 'port' => '3306', 'database' => 'fallback_database',
                'username' => 'fallback-user', 'repro_writer_policy' => true,
            ],
        ]);
        // Record the settings the check would connect with; never connect anywhere.
        $factory = new class(app()) extends ConnectionFactory
        {
            public ?array $config = null;

            public function make(array $config, $name = null)
            {
                $this->config = $config;

                throw new RuntimeException('No connection in this test.');
            }
        };
        $this->app->instance(ConnectionFactory::class, $factory);

        $this->assertSame(1, Artisan::call('db:writer-policy-check'));
        $this->assertSame(['url-host', 3307, 'url_database', 'url-user'], [
            $factory->config['host'] ?? null, $factory->config['port'] ?? null,
            $factory->config['database'] ?? null, $factory->config['username'] ?? null,
        ]);
        $this->assertArrayNotHasKey('url', $factory->config);
    }
}
