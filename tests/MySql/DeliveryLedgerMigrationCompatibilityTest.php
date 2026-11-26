<?php

namespace Tests\MySql;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DeliveryLedgerMigrationCompatibilityTest extends TestCase
{
    public static function parentTypes(): array
    {
        return ['converted signed IDs' => [false], 'native unsigned IDs' => [true]];
    }

    #[DataProvider('parentTypes')]
    public function test_delivery_migrations_preserve_parent_types_history_and_constraints(bool $unsigned): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('repro_mysql_test', DB::connection()->getDatabaseName());
        $this->assertSame('repro_test', DB::connection()->getConfig('username'));
        $originalConnection = DB::getDefaultConnection();
        $probe = 'delivery_migration_probe';
        // Two characters keep the longest automatically generated FK below
        // MySQL's identifier limit. Only these separately prefixed fixtures change.
        config(['database.connections.'.$probe => array_replace(DB::connection()->getConfig(), [
            'prefix' => 'z_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection($probe);
        Schema::clearResolvedInstance('db.schema');
        $tables = ['shoot_delivery_notification_outbox', 'shoot_provider_delivery_verifications',
            'shoot_delivery_receipts', 'workflow_logs', 'shoot_service', 'services', 'shoots', 'users'];
        $ownsTables = false;

        try {
            foreach ($tables as $table) {
                $this->assertFalse(Schema::hasTable($table), 'Refuse an existing migration fixture: '.$table);
            }
            $ownsTables = true;
            foreach (['users', 'services', 'shoot_service', 'shoots'] as $name) {
                Schema::create($name, function (Blueprint $table) use ($name, $unsigned): void {
                    $table->bigInteger('id', unsigned: $unsigned)->primary();
                    if ($name === 'shoots') {
                        foreach (['active_work_started_at', 'active_work_completed_at', 'admin_verified_at', 'completed_at'] as $column) {
                            $table->timestamp($column)->nullable();
                        }
                        $table->string('status')->nullable();
                        $table->string('workflow_status')->nullable();
                    }
                });
            }
            Schema::create('workflow_logs', function (Blueprint $table) use ($unsigned): void {
                $table->id();
                $table->bigInteger('user_id', unsigned: $unsigned);
                $table->foreign('user_id')->references('id')->on('users');
            });
            DB::table('users')->insert([['id' => 42], ['id' => 43]]);
            DB::table('workflow_logs')->insert(['id' => 7, 'user_id' => 42]);

            foreach (['2026_11_05_190000_add_component_delivery_ledger',
                '2026_11_06_000001_add_provider_auto_delivery',
                '2026_11_06_000002_create_shoot_delivery_notification_outbox'] as $name) {
                (require database_path('migrations/'.$name.'.php'))->up();
            }

            $columns = ['shoot_delivery_receipts' => ['shoot_id', 'shoot_service_id', 'actor_id'],
                'shoot_provider_delivery_verifications' => ['shoot_id', 'shoot_service_id'],
                'shoot_delivery_notification_outbox' => ['shoot_id'], 'workflow_logs' => ['user_id']];
            foreach ($columns as $table => $names) {
                foreach ($names as $name) {
                    $this->assertSame($unsigned, str_contains(Schema::getColumnType($table, $name, fullDefinition: true), 'unsigned'), $table.'.'.$name);
                }
                $this->assertCount(count($names), Schema::getForeignKeys($table));
            }
            $this->assertSame(42, (int) DB::table('workflow_logs')->where('id', 7)->value('user_id'));
            DB::table('workflow_logs')->insert(['id' => 8, 'user_id' => null]);
            $this->assertNull(DB::table('workflow_logs')->where('id', 8)->value('user_id'));
            DB::table('shoots')->insert(['id' => 42]);
            DB::table('shoot_service')->insert(['id' => 42]);

            $receipt = ['shoot_id' => 42, 'shoot_service_id' => 42, 'actor_id' => 43,
                'component_key' => 'photo', 'execution_key' => 'execution', 'method' => 'media',
                'evidence' => '{"files":[]}', 'plan_revision' => str_repeat('a', 64),
                'operation_key' => 'operation', 'delivered_at' => '2026-11-08 00:00:00'];
            $verification = ['shoot_id' => 42, 'shoot_service_id' => 42, 'provider' => 'iguide',
                'execution_key' => 'execution', 'identity_key' => 'identity', 'state' => 'ready',
                'source' => 'provider_api', 'event_fingerprint' => str_repeat('b', 64),
                'evidence' => '{}', 'verified_at' => '2026-11-08 00:00:00'];
            $outbox = ['shoot_id' => 42, 'deduplication_key' => str_repeat('c', 64),
                'operation_key' => 'operation', 'cycle_key' => 'cycle', 'is_full_order_delivery' => true,
                'service_item_ids' => '[42]'];
            foreach (['shoot_delivery_receipts' => $receipt,
                'shoot_provider_delivery_verifications' => $verification,
                'shoot_delivery_notification_outbox' => $outbox,
                'workflow_logs' => ['user_id' => 42]] as $table => $row) {
                foreach ($columns[$table] as $name) {
                    $this->assertMissingParentRejected($table, array_replace($row, [$name => 999]));
                }
            }
            DB::table('shoot_delivery_receipts')->insert($receipt);
            DB::table('shoot_provider_delivery_verifications')->insert($verification);
            DB::table('shoot_delivery_notification_outbox')->insert($outbox);
            DB::table('users')->where('id', 43)->delete();
            $this->assertNull(DB::table('shoot_delivery_receipts')->value('actor_id'));
            DB::table('shoots')->where('id', 42)->delete();
            foreach (array_slice($tables, 0, 3) as $table) {
                $this->assertSame(0, DB::table($table)->count(), 'Parent deletion must cascade: '.$table);
            }
            $this->assertSame(42, (int) DB::table('workflow_logs')->where('id', 7)->value('user_id'));
        } finally {
            try {
                if ($ownsTables) {
                    foreach ($tables as $table) {
                        Schema::dropIfExists($table);
                    }
                }
            } finally {
                DB::setDefaultConnection($originalConnection);
                Schema::clearResolvedInstance('db.schema');
                DB::purge($probe);
                config(['database.connections.'.$probe => null]);
            }
        }
    }

    private function assertMissingParentRejected(string $table, array $row): void
    {
        try {
            DB::table($table)->insert($row);
            $this->fail('A missing parent must be rejected for '.$table);
        } catch (QueryException $error) {
            $this->assertSame(1452, $error->errorInfo[1]);
        }
    }
}
