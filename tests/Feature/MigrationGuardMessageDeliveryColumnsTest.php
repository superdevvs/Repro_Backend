<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationGuardMessageDeliveryColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD_MIGRATION =
        'migrations/2026_09_20_160000_ensure_delivered_at_and_error_message_on_messages.php';

    public function test_delivery_columns_exist_after_migrations(): void
    {
        $this->assertTrue(Schema::hasColumn('messages', 'delivered_at'));
        $this->assertTrue(Schema::hasColumn('messages', 'error_message'));
    }

    public function test_rerunning_guard_with_columns_present_is_a_clean_noop(): void
    {
        $this->assertTrue(Schema::hasColumn('messages', 'delivered_at'));
        $this->assertTrue(Schema::hasColumn('messages', 'error_message'));

        $migration = $this->loadGuardMigration();

        try {
            $migration->up();
        } catch (\Throwable $e) {
            $this->fail(
                'Re-running the delivery-column guard with columns present '
                .'should be a no-op, but it threw: '.$e->getMessage()
            );
        }

        $this->assertTrue(Schema::hasColumn('messages', 'delivered_at'));
        $this->assertTrue(Schema::hasColumn('messages', 'error_message'));
    }

    public function test_guard_restores_dropped_delivery_columns(): void
    {
        Schema::table('messages', function ($table) {
            $table->dropColumn(['delivered_at', 'error_message']);
        });

        $this->assertFalse(Schema::hasColumn('messages', 'delivered_at'));
        $this->assertFalse(Schema::hasColumn('messages', 'error_message'));

        $this->loadGuardMigration()->up();

        $this->assertTrue(Schema::hasColumn('messages', 'delivered_at'));
        $this->assertTrue(Schema::hasColumn('messages', 'error_message'));
    }

    private function loadGuardMigration(): Migration
    {
        $migration = require database_path(self::GUARD_MIGRATION);

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
