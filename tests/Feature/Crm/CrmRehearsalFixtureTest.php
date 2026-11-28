<?php

namespace Tests\Feature\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * scripts/mysql/crm-rehearsal-fixture.php builds the synthetic SQLite snapshot for the
 * CRM conversion check by running only the CRM migrations. Its CRM tables must match
 * a fully migrated database, so no CRM migration is left out of the rehearsal.
 */
class CrmRehearsalFixtureTest extends CrmTestCase
{
    private ?string $snapshot = null;

    protected function tearDown(): void
    {
        if ($this->snapshot !== null && is_file($this->snapshot)) {
            unlink($this->snapshot);
        }
        parent::tearDown();
    }

    /** The capture's rule for CRM tables (scripts/cutover/nightly/runner.py is_crm_table). */
    private static function isCrmTable(string $table): bool
    {
        return str_starts_with($table, 'crm_') || str_starts_with($table, 'recruit_') || $table === 'email_suppressions';
    }

    /** @return array<string, array{type: string, notnull: int}> Each column's declared type and NOT NULL, by name. */
    private static function columns(\PDO $db, string $table): array
    {
        $columns = [];
        foreach ($db->query('SELECT name, type, "notnull" FROM pragma_table_info('.$db->quote($table).')')->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $columns[$column['name']] = ['type' => $column['type'], 'notnull' => (int) $column['notnull']];
        }

        return $columns;
    }

    public function test_fixture_has_every_crm_table_and_column_of_the_migrated_schema(): void
    {
        $this->snapshot = sys_get_temp_dir().'/crm-rehearsal-fixture-'.bin2hex(random_bytes(8)).'.sqlite';
        $process = new Process([PHP_BINARY, base_path('scripts/mysql/crm-rehearsal-fixture.php'), $this->snapshot],
            base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(120);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());

        $scope = json_decode(file_get_contents(base_path('database/schema/migration-scope.json')), true, flags: JSON_THROW_ON_ERROR);
        // Both sides are SQLite under phpunit.xml, so a migration the fixture skips that changes only a
        // column's type or NOT NULL (a ->change() or a DB::statement) is caught too. Under
        // phpunit.mysql.xml the migrated schema is MySQL, whose types differ, so only names compare.
        $sqlite = DB::getDriverName() === 'sqlite';
        $expected = [];
        foreach (array_filter(array_keys($scope['tables']), self::isCrmTable(...)) as $table) {
            $expected[$table] = $sqlite ? self::columns(DB::connection()->getPdo(), $table) : array_fill_keys(Schema::getColumnListing($table), null);
            ksort($expected[$table]);
        }
        $fixture = new \PDO('sqlite:'.$this->snapshot);
        $actual = [];
        foreach ($fixture->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            if (self::isCrmTable($table)) {
                $columns = self::columns($fixture, $table);
                $actual[$table] = $sqlite ? $columns : array_fill_keys(array_keys($columns), null);
                ksort($actual[$table]);
            }
        }
        $fixture = null;
        ksort($expected);
        ksort($actual);

        $this->assertCount(37, $expected);
        $this->assertSame($expected, $actual, 'The fixture misses a CRM migration. It must run every migration that creates or changes a CRM table.');

        // The same set the nightly capture requires (test_runner.py ties that list to the migration files).
        preg_match_all('/^Ran (\S+)$/m', $process->getOutput(), $ran);
        $config = json_decode(file_get_contents(base_path('scripts/cutover/nightly/nightly-config.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($config['crm_migrations'], $ran[1], 'The fixture must run exactly the crm_migrations of nightly-config.json.');
    }
}
