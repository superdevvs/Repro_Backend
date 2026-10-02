<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** Exercise network/write boundaries without PHPUnit's enclosing transaction. */
trait FreshDatabaseOutsideTransaction
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function () {
            // The next test migrates a fresh in-memory database. Do not downgrade
            // unrelated historical schemas whose SQLite down() drops indexed columns.
            RefreshDatabaseState::$migrated = false;
        });
    }
}
