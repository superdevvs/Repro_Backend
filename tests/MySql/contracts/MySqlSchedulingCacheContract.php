<?php

namespace Tests\MySql\Contracts;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Run explicitly against the disposable socket-only MySQL fixture. */
final class MySqlSchedulingCacheContract extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('isolated-v1', getenv('MYSQL_QUEUE_WORKER_FIXTURE'));
        self::assertSame('testing', getenv('APP_ENV'));
        self::assertSame('/fixture-socket/mysql.sock', getenv('DB_SOCKET'));
        self::assertSame('repro_mysql_test', getenv('DB_DATABASE'));
        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app['db']->disconnect('mysql_scheduling_cache');
            $this->app['db']->disconnect('mysql');
            $this->app->flush();
            HandleExceptions::flushState($this);
        }
        parent::tearDown();
    }

    public function test_only_enabled_mysql_scheduling_routes_to_the_new_connection(): void
    {
        $cases = [
            ['sqlite', 'true', null],
            ['mysql', 'false', null],
            ['mysql', 'true', 'mysql_scheduling_cache'],
        ];
        foreach ($cases as [$engine, $enabled, $expected]) {
            $environment = $_ENV;
            $server = $_SERVER;
            $overrides = [
                'DB_CONNECTION' => $engine,
                'REPRO_MYSQL_SCHEDULING_CACHE' => $enabled,
                'DB_CACHE_CONNECTION' => 'existing-cache',
                'DB_CACHE_LOCK_CONNECTION' => 'existing-locks',
            ];
            try {
                $_ENV = $overrides + $_ENV;
                $_SERVER = $overrides + $_SERVER;
                $stores = (require dirname(__DIR__, 3).'/config/cache.php')['stores'];
            } finally {
                $_ENV = $environment;
                $_SERVER = $server;
            }

            self::assertSame($expected, $stores['scheduling']['connection']);
            self::assertSame($expected, $stores['scheduling']['lock_connection']);
            self::assertSame('existing-cache', $stores['database']['connection']);
            self::assertSame('existing-locks', $stores['database']['lock_connection']);
        }
    }

    public function test_stock_cache_and_lock_progress_across_a_business_rollback(): void
    {
        $business = $this->app['db']->connection('mysql');
        $cacheConnection = $this->app['db']->connection('mysql_scheduling_cache');
        $store = $this->app['cache']->store('scheduling');
        self::assertSame($cacheConnection, $store->getStore()->getConnection());
        self::assertSame($cacheConnection, $store->getStore()->getLockConnection());
        self::assertNotSame($business->getPdo(), $cacheConnection->getPdo());
        self::assertSame('repro_cache_fixture@localhost', $cacheConnection->getPdo()->query('SELECT CURRENT_USER()')->fetchColumn());

        $lock = $store->lock('contract-scheduling', 30);
        self::assertSame(0, $business->transactionLevel());
        self::assertTrue($lock->get());
        try {
            $business->beginTransaction();
            $business->getPdo()->query('SELECT id FROM repro_mysql_control.writer_token WHERE id = 1 FOR UPDATE')->closeCursor();
            self::assertFalse($store->lock('contract-scheduling', 30)->get());
            self::assertTrue($store->put('contract-scheduling-count', 6, 30));
            self::assertSame(7, $store->increment('contract-scheduling-count'));
        } finally {
            if ($business->transactionLevel() > 0) {
                $business->rollBack();
            }
            self::assertTrue($lock->release());
        }
        self::assertSame(7, $store->get('contract-scheduling-count'));
        self::assertTrue($store->forget('contract-scheduling-count'));
    }

    public function test_cached_store_refuses_a_replacement_pdo_with_broader_grants(): void
    {
        $store = $this->app['cache']->store('scheduling');
        self::assertTrue($store->put('contract-cache-reconnect', 'before', 30));

        $config = $this->app['config'];
        $usernameKey = 'database.connections.mysql_scheduling_cache.username';
        $originalUsername = $config->get($usernameKey);
        $db = $this->app['db'];
        $db->disconnect('mysql_scheduling_cache');
        $config->set($usernameKey, 'repro_cache_broad');
        try {
            $store->put('contract-cache-reconnect', 'after', 30);
            self::fail('A replacement account with non-cache grants must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('on cache and cache_locks', $exception->getMessage());
        } finally {
            $config->set($usernameKey, $originalUsername);
            // Reset the rejected lazy resolver so the next query reconnects with the restored config.
            $db->disconnect('mysql_scheduling_cache');
        }
        self::assertSame('before', $store->get('contract-cache-reconnect'));
        self::assertTrue($store->forget('contract-cache-reconnect'));
    }
}
