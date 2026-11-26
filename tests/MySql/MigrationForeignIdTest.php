<?php

namespace Tests\MySql;

use App\Support\MigrationForeignId;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class MigrationForeignIdTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public static function parentTypes(): array
    {
        return ['imported signed IDs' => [false], 'native unsigned IDs' => [true]];
    }

    #[DataProvider('parentTypes')]
    public function test_new_foreign_keys_match_the_parent_sign_without_changing_values(bool $unsigned): void
    {
        try {
            Schema::create('_migration_id_parent', function (Blueprint $table) use ($unsigned): void {
                $table->bigInteger('id', autoIncrement: false, unsigned: $unsigned)->primary();
            });
            Schema::create('_migration_id_child', function (Blueprint $table): void {
                $table->id();
                MigrationForeignId::matching($table, 'parent_id', '_migration_id_parent')->constrained('_migration_id_parent');
            });
            DB::table('_migration_id_parent')->insert(['id' => 42]);
            DB::table('_migration_id_child')->insert(['parent_id' => 42]);
            $type = Schema::getColumnType('_migration_id_child', 'parent_id', fullDefinition: true);
            $this->assertSame($unsigned, str_contains($type, 'unsigned'));
            $this->assertSame(42, (int) DB::table('_migration_id_child')->value('parent_id'));
            $this->assertCount(1, Schema::getForeignKeys('_migration_id_child'));
            try {
                DB::table('_migration_id_child')->insert(['parent_id' => 43]);
                $this->fail('The matching type must retain foreign-key enforcement.');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertSame(1452, $exception->errorInfo[1]);
            }
        } finally {
            Schema::dropIfExists('_migration_id_child');
            Schema::dropIfExists('_migration_id_parent');
        }
    }
}
