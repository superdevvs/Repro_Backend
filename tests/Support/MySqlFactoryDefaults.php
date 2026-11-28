<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MySqlFactoryDefaults
{
    public static function seed(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        if (DB::connection()->getDatabaseName() !== 'repro_mysql_test'
            || DB::connection()->getConfig('username') !== 'repro_test') {
            throw new \RuntimeException('Factory fixtures require the isolated MySQL test account');
        }
        // Schema squashing skips historical data migrations. These are the
        // factory templates/rules exported from an empty SQLite migrate:fresh,
        // with zero users and no production or customer records.
        // Keep stock copy aligned with data migrations: the schema's ledger
        // already records them before these fixture rows are inserted.
        $defaults = json_decode(file_get_contents(__DIR__.'/../Fixtures/mysql-factory-defaults.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($defaults as $table => $rows) {
            if (Schema::hasTable($table) && ! DB::table($table)->exists()) {
                foreach ($rows as $row) {
                    DB::table($table)->insert($row);
                }
            }
        }
    }
}
