<?php

namespace Tests\Feature;

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
}
