<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature coverage for the idempotent, SQLite-safe schema-guard migration
 * (database/migrations/2026_06_01_000000_ensure_failed_at_on_messages.php).
 *
 * Validates: Requirements 1.1, 1.2, 1.4
 */
class MigrationGuardFailedAtTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD_MIGRATION =
        'migrations/2026_06_01_000000_ensure_failed_at_on_messages.php';

    /**
     * Req 1.1: the Backend guarantees the `failed_at` column exists on the
     * `messages` table after migrations run.
     */
    public function test_failed_at_column_exists_after_migrations(): void
    {
        $this->assertTrue(
            Schema::hasColumn('messages', 'failed_at'),
            'Expected messages.failed_at to exist after migrations run.'
        );
    }

    /**
     * Req 1.2 + 1.4: re-running the guard migration's up() while the column is
     * already present is a clean no-op on SQLite (no exception, column intact).
     */
    public function test_rerunning_guard_with_column_present_is_a_clean_noop(): void
    {
        // Precondition: the base messaging migration already declares failed_at.
        $this->assertTrue(
            Schema::hasColumn('messages', 'failed_at'),
            'Precondition failed: messages.failed_at should already be present.'
        );

        $migration = $this->loadGuardMigration();

        // Re-invoking up() on an already-present column must not raise on SQLite.
        try {
            $migration->up();
        } catch (\Throwable $e) {
            $this->fail(
                'Re-running the guard migration up() with the column present '
                . 'should be a no-op, but it threw: ' . $e->getMessage()
            );
        }

        // The column is untouched and still present after the no-op run.
        $this->assertTrue(
            Schema::hasColumn('messages', 'failed_at'),
            'Expected messages.failed_at to remain present after the no-op up().'
        );
    }

    /**
     * Instantiate the migration's anonymous class by requiring the migration
     * file, which returns `new class extends Migration { ... }`.
     */
    private function loadGuardMigration(): Migration
    {
        $migration = require database_path(self::GUARD_MIGRATION);

        $this->assertInstanceOf(
            Migration::class,
            $migration,
            'Guard migration file should return a Migration instance.'
        );

        return $migration;
    }
}
