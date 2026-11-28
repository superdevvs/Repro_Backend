<?php

namespace Tests\MySql;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class MysqlCutoverSchemaTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    private function complete(?string $uuid = null, bool $frozen = true): Process
    {
        $command = [PHP_BINARY, base_path('scripts/mysql/complete-schema.php'), '--app='.base_path(),
            '--expected-server-uuid='.($uuid ?? DB::selectOne('SELECT @@server_uuid AS uuid')->uuid)];
        if ($frozen) {
            $command[] = '--writers-stopped';
        }
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    public function test_sqlite_noop_ledger_does_not_skip_mysql_repairs_or_rewrite_historical_migration_ids(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $user = User::withoutEvents(fn () => User::factory()->create(['name' => 'Preserve Exact Legacy Case']));
        $user->refresh();
        $original = $user->getRawOriginal();
        $ledger = DB::table('migrations')->orderBy('id')->get()->toJson();
        $definitions = json_decode(file_get_contents(database_path('schema/mysql-cascade-triggers.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($definitions as $trigger) {
            DB::unprepared('DROP TRIGGER `'.$trigger['name'].'`');
        }
        Schema::table('invoices', fn ($table) => $table->dropIndex('invoices_effective_period_sort'));
        Schema::table('invoices', fn ($table) => $table->string('role')->nullable(false)->default('photographer')->change());
        Schema::table('photographer_equipments', fn ($table) => $table->bigInteger('photographer_id')->nullable(false)->change());
        $this->assertDatabaseHas('migrations', ['migration' => '2026_11_04_180100_preserve_aryeo_cascade_change_feed']);
        foreach ([1, 2] as $run) {
            $process = $this->complete();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['verified']);
            $this->assertTrue($result['migration_ledger_unchanged']);
            $this->assertFalse($result['cutover_ready']);
        }
        $this->assertTrue(Schema::hasIndex('invoices', 'invoices_effective_period_sort'));
        $this->assertTrue(collect(Schema::getColumns('invoices'))->firstWhere('name', 'role')['nullable']);
        $this->assertTrue(collect(Schema::getColumns('photographer_equipments'))->firstWhere('name', 'photographer_id')['nullable']);
        $this->assertSame(10, DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', 'repro_mysql_test')->whereIn('TRIGGER_NAME', array_column($definitions, 'name'))->count());
        $this->assertSame($ledger, DB::table('migrations')->orderBy('id')->get()->toJson());
        $this->assertSame($original, $user->fresh()->getRawOriginal());
    }

    public function test_wrong_server_or_missing_freeze_attestation_refuses_schema_changes(): void
    {
        $ledger = DB::table('migrations')->orderBy('id')->get()->toJson();
        $this->assertFalse($this->complete('00000000-0000-0000-0000-000000000000')->isSuccessful());
        $this->assertFalse($this->complete(frozen: false)->isSuccessful());
        $this->assertTrue(Schema::hasIndex('invoices', 'invoices_effective_period_sort'));
        $this->assertSame($ledger, DB::table('migrations')->orderBy('id')->get()->toJson());
    }

    #[DataProvider('invoiceRoleDefinitions')]
    public function test_nullable_role_repair_preserves_existing_type_literals_collation_and_data(
        string $type, ?string $default, string $collation, string $value, bool $noBackslashEscapes = false
    ): void {
        $sqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $comment = "Legacy 'role' \\ comment";
        try {
            if ($noBackslashEscapes) {
                DB::statement('SET SESSION sql_mode = ?', [$sqlMode.',NO_BACKSLASH_ESCAPES']);
            }
            $quote = fn (string $literal): string => DB::getPdo()->quote($literal);
            DB::statement('ALTER TABLE invoices MODIFY role '.$type.' CHARACTER SET utf8mb4 COLLATE '.$collation
                .' NOT NULL'.($default === null ? '' : ' DEFAULT '.$quote($default)).' COMMENT '.$quote($comment));
            $before = $this->invoiceRoleMetadata();
            $this->assertSame($default, $before['column_default']);
            $this->assertSame($comment, $before['column_comment']);
            $user = User::withoutEvents(fn () => User::factory()->create());
            $invoice = ['user_id' => $user->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31'];
            $id = DB::table('invoices')->insertGetId($invoice + ['role' => $value]);
            $original = (array) DB::table('invoices')->find($id);

            $migration = require database_path('migrations/2026_11_04_210000_restore_mysql_nullable_invoice_role.php');
            foreach ([1, 2] as $run) {
                $migration->up();
                $this->assertSame(array_replace($before, ['is_nullable' => 'YES']), $this->invoiceRoleMetadata());
                $this->assertSame($original, (array) DB::table('invoices')->find($id));
                $this->assertSame($value, DB::table('invoices')->where('id', $id)->value('role'));
            }
            $nullId = DB::table('invoices')->insertGetId($invoice + ['role' => null]);
            $defaultId = DB::table('invoices')->insertGetId($invoice);
            $this->assertNull(DB::table('invoices')->where('id', $nullId)->value('role'));
            $this->assertSame($default, DB::table('invoices')->where('id', $defaultId)->value('role'));
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$sqlMode]);
            $this->restoreInvoiceRoleFixture();
        }
    }

    public static function invoiceRoleDefinitions(): array
    {
        $escaped = "client's \\ role";

        return [
            'imported longtext without default' => ['LONGTEXT', null, 'utf8mb4_0900_bin', str_repeat('Legacy rôle ', 80).'  '],
            'fresh varchar default' => ['VARCHAR(255)', 'photographer', 'utf8mb4_0900_bin', 'editor'],
            'custom varchar width and collation' => ['VARCHAR(47)', $escaped, 'utf8mb4_general_ci', 'CuStOm rôle  '],
            'wider varchar capacity' => ['VARCHAR(512)', 'editor', 'utf8mb4_0900_bin', str_repeat('Legacy ', 60)],
            'empty literal default' => ['VARCHAR(33)', '', 'utf8mb4_0900_bin', 'editor'],
            'NULL literal default' => ['VARCHAR(35)', 'NULL', 'utf8mb4_0900_bin', 'editor'],
            'zero literal default' => ['VARCHAR(36)', '0', 'utf8mb4_0900_bin', 'editor'],
            'no backslash escapes' => ['VARCHAR(47)', $escaped, 'utf8mb4_general_ci', 'CuStOm rôle  ', true],
        ];
    }

    public function test_nullable_role_repair_refuses_expression_defaults_without_changing_them(): void
    {
        try {
            DB::statement('ALTER TABLE invoices MODIFY role VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL DEFAULT (UUID())');
            $before = $this->invoiceRoleMetadata();
            $this->assertSame('DEFAULT_GENERATED', $before['extra']);
            $user = User::withoutEvents(fn () => User::factory()->create());
            $id = DB::table('invoices')->insertGetId(['user_id' => $user->id, 'role' => 'Preserve Original Role  ',
                'period_start' => '2026-01-01', 'period_end' => '2026-01-31']);
            $original = (array) DB::table('invoices')->find($id);
            $migration = require database_path('migrations/2026_11_04_210000_restore_mysql_nullable_invoice_role.php');
            try {
                $migration->up();
                $this->fail('An unreviewed expression default must not be rewritten.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Unsupported invoices.role definition; nullability was not changed.', $error->getMessage());
            }
            $this->assertSame($before, $this->invoiceRoleMetadata());
            $this->assertSame($original, (array) DB::table('invoices')->find($id));
        } finally {
            $this->restoreInvoiceRoleFixture();
        }
    }

    private function invoiceRoleMetadata(): array
    {
        return (array) DB::selectOne('SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable,
            CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name,
            COLUMN_DEFAULT AS column_default, EXTRA AS extra,
            GENERATION_EXPRESSION AS generation_expression, COLUMN_COMMENT AS column_comment
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['invoices', 'role']);
    }

    private function restoreInvoiceRoleFixture(): void
    {
        DB::table('invoices')->delete();
        DB::statement("ALTER TABLE invoices MODIFY role VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL DEFAULT NULL COMMENT ''");
    }
}
