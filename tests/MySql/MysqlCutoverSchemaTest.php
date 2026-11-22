<?php

namespace Tests\MySql;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}
