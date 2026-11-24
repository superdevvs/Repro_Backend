<?php

namespace Tests\MySql;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RecentFeatureMigrationCompatibilityTest extends TestCase
{
    public static function parentTypes(): array
    {
        return ['converted signed IDs' => [false], 'native unsigned IDs' => [true]];
    }

    #[DataProvider('parentTypes')]
    public function test_new_feature_migrations_preserve_parent_types_and_deletion_contracts(bool $unsigned): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('repro_mysql_test', DB::connection()->getDatabaseName());
        $this->assertSame('repro_test', DB::connection()->getConfig('username'));
        $originalConnection = DB::getDefaultConnection();
        $probe = 'recent_feature_migration_probe';
        config(['database.connections.'.$probe => array_replace(DB::connection()->getConfig(), [
            'prefix' => 'n_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection($probe);
        Schema::clearResolvedInstance('db.schema');
        $tables = ['editor_payouts', 'shoot_guest_shares', 'message_reactions', 'message_send_receipts', 'message_media',
            'mobile_device_preferences', 'mobile_assistant_actions', 'shoot_service_earning_snapshots',
            'personal_access_tokens', 'shoots', 'users'];
        $ownsTables = false;

        try {
            foreach ($tables as $table) {
                $this->assertFalse(Schema::hasTable($table), 'Refuse an existing migration fixture: '.$table);
            }
            $ownsTables = true;
            foreach (['users', 'shoots', 'personal_access_tokens'] as $name) {
                Schema::create($name, fn (Blueprint $table) => $table->bigInteger('id', unsigned: $unsigned)->primary());
            }
            DB::table('users')->insert([['id' => 42], ['id' => 43]]);
            DB::table('shoots')->insert(['id' => 42]);
            DB::table('personal_access_tokens')->insert(['id' => 42]);

            foreach (['2026_11_11_150000_preserve_earned_pay_on_unpaid_service_removal',
                '2026_11_12_120000_create_mobile_assistant_actions_table',
                '2026_11_13_040000_create_mobile_device_preferences_table',
                '2026_11_14_010000_create_messaging_media_tables',
                '2026_11_14_150000_create_shoot_guest_shares_table'] as $name) {
                (require database_path('migrations/'.$name.'.php'))->up();
            }

            $columns = ['shoot_service_earning_snapshots' => ['shoot_id', 'recipient_id', 'removed_by'],
                'mobile_assistant_actions' => ['user_id'],
                'mobile_device_preferences' => ['personal_access_token_id', 'user_id'],
                'message_media' => ['user_id'], 'message_send_receipts' => ['user_id'],
                'message_reactions' => ['user_id'], 'shoot_guest_shares' => ['shoot_id', 'created_by']];
            foreach ($columns as $table => $names) {
                foreach ($names as $name) {
                    $this->assertSame($unsigned, str_contains(Schema::getColumnType($table, $name, fullDefinition: true), 'unsigned'), $table.'.'.$name);
                }
                $this->assertCount(count($names), Schema::getForeignKeys($table));
            }
            $date = '2026-11-22 00:00:00';
            $uuid = '00000000-0000-4000-8000-000000000042';
            $rows = [
                'shoot_service_earning_snapshots' => ['shoot_id' => 42, 'shoot_service_id' => 999,
                    'recipient_id' => 42, 'removed_by' => 43, 'scheduled_date' => '2026-11-22',
                    'earned_at' => $date, 'amount' => '123.45', 'snapshot' => '{"original_service_id":999}'],
                'mobile_assistant_actions' => ['id' => $uuid, 'user_id' => 43, 'request_id' => $uuid,
                    'action' => 'note.create', 'request_hash' => str_repeat('a', 64), 'scope_hash' => str_repeat('b', 64),
                    'proposal' => 'synthetic-encrypted-proposal', 'expires_at' => $date],
                'mobile_device_preferences' => ['personal_access_token_id' => 42, 'user_id' => 43,
                    'installation_id' => $uuid, 'sequence' => 1, 'preferences' => '{"sms":false}', 'enabled_after' => '{}'],
                'message_media' => ['id' => $uuid, 'user_id' => 43, 'channel' => 'SMS', 'name' => 'fixture.jpg',
                    'size' => 1, 'expires_at' => $date],
                'message_send_receipts' => ['user_id' => 43, 'request_key' => $uuid, 'fingerprint' => str_repeat('c', 64)],
                'message_reactions' => ['user_id' => 43, 'message_kind' => 'sms', 'message_id' => '42', 'emoji' => '+1'],
                'shoot_guest_shares' => ['shoot_id' => 42, 'created_by' => 43, 'email' => 'fixture@example.test',
                    'token_hash' => str_repeat('d', 64), 'expires_at' => $date],
            ];
            foreach ($rows as $table => $row) {
                foreach ($columns[$table] as $name) {
                    $this->assertConstraintError(fn () => DB::table($table)->insert(array_replace($row, [$name => 999])), 1452);
                }
                DB::table($table)->insert($row);
            }
            // The deleted service's booked identity and earned amount remain historical facts.
            $this->assertSame(999, (int) DB::table('shoot_service_earning_snapshots')->value('shoot_service_id'));
            $this->assertSame('123.45', DB::table('shoot_service_earning_snapshots')->value('amount'));
            $this->assertConstraintError(fn () => DB::table('users')->where('id', 42)->delete(), 1451);

            DB::table('personal_access_tokens')->where('id', 42)->delete();
            $this->assertSame(0, DB::table('mobile_device_preferences')->count());
            DB::table('personal_access_tokens')->insert(['id' => 42]);
            DB::table('mobile_device_preferences')->insert($rows['mobile_device_preferences']);
            DB::table('users')->where('id', 43)->delete();
            foreach (['mobile_assistant_actions', 'mobile_device_preferences', 'message_send_receipts', 'message_reactions', 'shoot_guest_shares'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), 'User deletion must cascade: '.$table);
            }
            $this->assertSame(1, DB::table('message_media')->count());
            $this->assertNull(DB::table('message_media')->value('user_id'));
            $this->assertNull(DB::table('shoot_service_earning_snapshots')->value('removed_by'));
            $this->assertSame(42, (int) DB::table('shoot_service_earning_snapshots')->value('recipient_id'));
            DB::table('shoots')->where('id', 42)->delete();
            $this->assertSame(0, DB::table('shoot_service_earning_snapshots')->count());
            $this->assertEditorPayoutIdentityMigration();
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

    private function assertEditorPayoutIdentityMigration(): void
    {
        Schema::create('editor_payouts', function (Blueprint $table) {
            $table->id();
            foreach (['editor_id', 'shoot_id', 'service_id'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->unsignedBigInteger('shoot_service_id')->nullable();
            $table->decimal('amount', 10, 2);
        });
        $table = DB::connection()->getQueryGrammar()->wrapTable('editor_payouts');
        DB::statement('CREATE UNIQUE INDEX editor_payouts_execution_unique ON '.$table.' (editor_id, shoot_id, service_id, (COALESCE(shoot_service_id, 0)))');
        $migration = require database_path('migrations/2026_11_11_173000_preserve_editor_payouts_on_service_removal.php');
        $migration->up();
        $row = ['editor_id' => 42, 'shoot_id' => 42, 'service_id' => 5, 'amount' => '123.45'];
        DB::table('editor_payouts')->insert($row);
        $this->assertConstraintError(fn () => DB::table('editor_payouts')->insert($row), 1062);
        DB::table('editor_payouts')->insert($row + ['shoot_service_id' => 101]);
        $this->assertConstraintError(fn () => DB::table('editor_payouts')->insert($row + ['removed_shoot_service_id' => 101]), 1062);
        DB::table('editor_payouts')->where('shoot_service_id', 101)->update([
            'shoot_service_id' => null, 'removed_shoot_service_id' => 101,
        ]);
        DB::table('editor_payouts')->insert($row + ['removed_shoot_service_id' => 102]);
        $this->assertSame(3, DB::table('editor_payouts')->count());
        $this->assertSame('123.45', DB::table('editor_payouts')->where('removed_shoot_service_id', 101)->value('amount'));
        $this->assertConstraintError(fn () => DB::table('editor_payouts')->insert($row + ['shoot_service_id' => 101]), 1062);
        try {
            $migration->down();
            $this->fail('Rollback discarded retained payout execution identities.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Removed service editor payouts exist; refusing to discard their execution identity.', $error->getMessage());
        }
        DB::table('editor_payouts')->whereNotNull('removed_shoot_service_id')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('editor_payouts', 'removed_shoot_service_id'));
        $this->assertConstraintError(fn () => DB::table('editor_payouts')->insert($row), 1062);
        DB::table('editor_payouts')->insert($row + ['shoot_service_id' => 103]);
        $this->assertConstraintError(fn () => DB::table('editor_payouts')->insert($row + ['shoot_service_id' => 103]), 1062);
    }

    #[DataProvider('parentTypes')]
    public function test_crm_migrations_support_existing_parent_ids_and_reference_data(bool $unsigned): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('repro_mysql_test', DB::connection()->getDatabaseName());
        $this->assertSame('repro_test', DB::connection()->getConfig('username'));
        $connection = DB::getDefaultConnection();
        $probe = 'crm_recent_feature_probe';
        config(['database.connections.'.$probe => array_replace(DB::connection()->getConfig(), [
            'prefix' => 'q_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection($probe);
        Schema::clearResolvedInstance('db.schema');
        $tables = fn () => array_column(DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,2)=?',
            ['q_']
        ), 'name');
        $ownsTables = false;
        try {
            $this->assertSame([], $tables(), 'Refuse existing CRM migration probe tables.');
            $ownsTables = true;
            foreach (['users', 'shoots'] as $parent) {
                Schema::create($parent, fn (Blueprint $t) => $t->bigInteger('id', unsigned: $unsigned)->primary());
                DB::table($parent)->insert(['id' => 4294967301]);
            }
            $migrations = array_filter(glob(database_path('migrations/2026_11_24_*.php')),
                fn ($file) => preg_match('/_(crm|recruit)_/', basename($file)));
            $this->assertCount(9, $migrations);
            foreach ($migrations as $file) {
                (require $file)->up();
            }
            $this->assertCount(39, $tables());
            $this->assertSame(8, DB::table('crm_event_types')->count());
            $this->assertSame(3, DB::table('crm_drip_campaigns')->where('status', 'draft')->count());
            $this->assertSame(30, DB::table('crm_drip_steps')->count());
            $id = DB::table('crm_contacts')->insertGetId([
                'name' => 'Migration fixture', 'name_normalized' => 'migration fixture', 'stage' => 'inquiry',
                'segment' => 'residential', 'client_user_id' => 4294967301,
                'review_reasons' => json_encode(['audit'], JSON_THROW_ON_ERROR),
            ]);
            DB::table('crm_quotes')->insert(['number' => 'Q-TEST', 'public_token' => 'fixture', 'contact_id' => $id,
                'booking_status' => 'requested', 'booking_shoot_id' => 4294967301, 'total' => 15.25]);
            $this->assertSame(1, DB::table('crm_contacts')->join('users', 'users.id', '=', 'crm_contacts.client_user_id')->count());
            $this->assertSame(1, DB::table('crm_quotes')->join('shoots', 'shoots.id', '=', 'crm_quotes.booking_shoot_id')->count());
            $this->assertSame(1, DB::table('crm_contacts')->whereJsonContains('review_reasons', 'audit')->count());
            $this->assertConstraintError(fn () => DB::table('crm_quotes')->insert(['number' => 'Q-TEST2',
                'public_token' => 'fixture2', 'contact_id' => $id, 'booking_shoot_id' => 4294967301]), 1062);
            foreach (array_reverse($migrations) as $file) {
                (require $file)->down();
            }
            $this->assertCount(2, $tables());
        } finally {
            if ($ownsTables) {
                foreach ($tables() as $name) {
                    Schema::dropIfExists(substr($name, 2));
                }
            }
            DB::setDefaultConnection($connection);
            Schema::clearResolvedInstance('db.schema');
            DB::purge($probe);
        }
    }

    private function assertConstraintError(callable $write, int $number): void
    {
        try {
            $write();
            $this->fail('The foreign key must reject this write.');
        } catch (QueryException $error) {
            $this->assertSame($number, $error->errorInfo[1]);
        }
    }
}
