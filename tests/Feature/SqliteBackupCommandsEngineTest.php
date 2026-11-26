<?php

namespace Tests\Feature;

use App\Support\DatabaseDriver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * shoots:classify-travel-fees and shoots:reconcile-partial-delivery check their change against
 * a SQLite safety backup. On MySQL an old SQLite file could still "match" and the repair would
 * go ahead without a real backup of the live data, so --apply must refuse before it reads or
 * writes anything.
 */
class SqliteBackupCommandsEngineTest extends TestCase
{
    private int $queries = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.unix_socket', '/nonexistent/repro-engine-check.sock');
        DB::listen(function (): void {
            $this->queries++;
        });
    }

    public function test_travel_fee_apply_refuses_when_the_live_database_is_not_sqlite(): void
    {
        $this->artisan('shoots:classify-travel-fees', [
            '--apply' => true, '--actor' => 1, '--expected-plan' => str_repeat('a', 64),
            '--backup-path' => __FILE__,
        ])->expectsOutputToContain('the live database is mysql')->assertFailed();

        $this->assertSame(0, $this->queries);
    }

    public function test_partial_delivery_apply_refuses_when_the_live_database_is_not_sqlite(): void
    {
        $this->artisan('shoots:reconcile-partial-delivery', [
            'shoot' => 10, '--source-service' => 11, '--target-service' => 12, '--file-ids' => '13,14',
            '--expected-source-scheduled-at' => '2026-11-20 10:00:00', '--finalization-event' => 15,
            '--actor' => 1, '--backup-path' => __FILE__, '--apply' => true,
        ])->expectsOutputToContain('the live database is mysql')->assertFailed();

        $this->assertSame(0, $this->queries);
    }

    public function test_the_engine_comes_from_db_url_when_it_overrides_the_connection_driver(): void
    {
        // Laravel lets DB_URL replace the driver of the chosen connection (ConfigurationUrlParser),
        // so a "sqlite" connection with a mysql:// URL really talks to MySQL.
        $this->useSqliteConnectionWithUrl('mysql://repro@localhost/repro?unix_socket=/nonexistent/repro-engine-url.sock');

        $this->assertSame('mysql', DatabaseDriver::driver());
        $this->assertFalse(DatabaseDriver::isSqlite());
        $this->assertSame('mysql', DatabaseDriver::driver('sqlite'));
        $this->assertSame(0, $this->queries);
    }

    public function test_driver_aliases_in_db_url_resolve_like_laravel_does(): void
    {
        $this->useSqliteConnectionWithUrl('mysql2://repro@localhost/repro');
        $this->assertSame('mysql', DatabaseDriver::driver());

        config()->set('database.connections.sqlite.url', 'sqlite3:///tmp/repro-engine-url.sqlite');
        $this->assertSame('sqlite', DatabaseDriver::driver());
        $this->assertTrue(DatabaseDriver::isSqlite());
    }

    public function test_apply_refuses_when_db_url_points_the_sqlite_connection_at_mysql(): void
    {
        $this->useSqliteConnectionWithUrl('mysql://repro@localhost/repro?unix_socket=/nonexistent/repro-engine-url.sock');

        $this->artisan('shoots:classify-travel-fees', [
            '--apply' => true, '--actor' => 1, '--expected-plan' => str_repeat('a', 64),
            '--backup-path' => __FILE__,
        ])->expectsOutputToContain('the live database is mysql')->assertFailed();

        $this->artisan('shoots:reconcile-partial-delivery', [
            'shoot' => 10, '--source-service' => 11, '--target-service' => 12, '--file-ids' => '13,14',
            '--expected-source-scheduled-at' => '2026-11-20 10:00:00', '--finalization-event' => 15,
            '--actor' => 1, '--backup-path' => __FILE__, '--apply' => true,
        ])->expectsOutputToContain('the live database is mysql')->assertFailed();

        $this->assertSame(0, $this->queries);
    }

    private function useSqliteConnectionWithUrl(string $url): void
    {
        DB::purge('sqlite');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.url', $url);
    }
}
