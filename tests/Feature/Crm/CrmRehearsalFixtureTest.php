<?php

namespace Tests\Feature\Crm;

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

    public function test_fixture_has_every_crm_table_and_column_of_the_migrated_schema(): void
    {
        $this->snapshot = sys_get_temp_dir().'/crm-rehearsal-fixture-'.bin2hex(random_bytes(8)).'.sqlite';
        $process = new Process([PHP_BINARY, base_path('scripts/mysql/crm-rehearsal-fixture.php'), $this->snapshot],
            base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(120);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());

        $scope = json_decode(file_get_contents(base_path('database/schema/migration-scope.json')), true, flags: JSON_THROW_ON_ERROR);
        $expected = [];
        foreach (array_filter(array_keys($scope['tables']), self::isCrmTable(...)) as $table) {
            $columns = Schema::getColumnListing($table);
            sort($columns);
            $expected[$table] = $columns;
        }
        $fixture = new \PDO('sqlite:'.$this->snapshot);
        $actual = [];
        foreach ($fixture->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            if (self::isCrmTable($table)) {
                $columns = $fixture->query('SELECT name FROM pragma_table_info('.$fixture->quote($table).')')->fetchAll(\PDO::FETCH_COLUMN);
                sort($columns);
                $actual[$table] = $columns;
            }
        }
        $fixture = null;
        ksort($expected);
        ksort($actual);

        $this->assertCount(37, $expected);
        $this->assertSame($expected, $actual, 'The fixture misses a CRM migration. It must run every migration that creates or changes a CRM table.');
    }
}
