<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** Exercise network/write boundaries without PHPUnit's enclosing transaction. */
trait FreshDatabaseOutsideTransaction
{
    use DatabaseMigrations;
    use DatabaseTruncation {
        truncateDatabaseTables as private truncateMySqlDatabaseTables;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            return; // The framework's truncation hook below keeps real commits.
        }
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function () {
            // The next test migrates a fresh in-memory database. Do not downgrade
            // unrelated historical schemas whose SQLite down() drops indexed columns.
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $this->assertMySqlTestDatabase();
        $this->truncateMySqlDatabaseTables();
        // RefreshDatabase tests may follow this class. Leave their shared schema
        // empty too, without a transaction enclosing application/network work.
        $this->beforeApplicationDestroyed(function (): void {
            $this->assertMySqlTestDatabase();
            $this->truncateTablesForAllConnections();
        });
    }

    private function assertMySqlTestDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'repro_mysql_test'
            || DB::connection()->getConfig('username') !== 'repro_test') {
            throw new \RuntimeException('Database truncation requires the separately permissioned MySQL test account.');
        }
    }
}
