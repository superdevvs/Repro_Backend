<?php

namespace Tests\Feature;

use App\Database\DatabaseEngineMismatch;
use App\Services\ListingStudio\ListingStudioTrialMonitoring;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseHealthCheckTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['health.expected_engine' => '', 'health.mysql_server_uuid' => '']);
        $this->createMigrationsTable();
    }

    protected function tearDown(): void
    {
        if ($this->app?->maintenanceMode()->active()) {
            $this->app->maintenanceMode()->deactivate();
        }
        DB::purge('health_side_file');
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_up_reads_the_database_and_names_the_engine(): void
    {
        $engine = DB::connection()->getDriverName();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->get('/up')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['status' => 'ok', 'engine' => $engine]);

        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'migrations')),
            'The health check must read a real table, not only run SELECT 1.');
    }

    public function test_up_is_not_cached_by_proxies(): void
    {
        $this->assertStringContainsString('no-store', (string) $this->get('/up')->headers->get('Cache-Control'));
    }

    public function test_up_passes_when_the_expected_engine_matches(): void
    {
        $engine = DB::connection()->getDriverName();
        config(['health.expected_engine' => strtoupper($engine)]);

        $this->get('/up')->assertOk()->assertJson(['status' => 'ok', 'engine' => $engine]);
    }

    public function test_up_fails_with_503_when_the_engine_differs_from_the_expected_one(): void
    {
        $engine = DB::connection()->getDriverName();
        $expected = $engine === 'mysql' ? 'sqlite' : 'mysql';
        config(['health.expected_engine' => $expected]);

        $this->get('/up')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'engine' => $engine, 'reason' => 'engine_mismatch']);
    }

    public function test_up_fails_with_503_when_the_database_file_is_missing(): void
    {
        $this->useSqliteFile('/nonexistent-health-check-dir/database.sqlite');

        $response = $this->get('/up')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'engine' => 'sqlite', 'reason' => 'database_unavailable']);

        $this->assertStringNotContainsString('nonexistent-health-check-dir', (string) $response->getContent());
    }

    public function test_up_fails_with_503_on_an_empty_database_without_the_schema(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'health-empty-');
        $this->temporaryFiles[] = $file;
        $this->useSqliteFile($file);

        $this->get('/up')
            ->assertStatus(503)
            ->assertJson(['status' => 'fail', 'reason' => 'database_unavailable']);
    }

    public function test_up_still_answers_during_maintenance_mode(): void
    {
        $this->app->maintenanceMode()->activate(['retry' => 60]);

        $this->get('/up')->assertOk()->assertJson(['status' => 'ok']);
        $this->get('/')->assertStatus(503);
    }

    public function test_queries_stop_on_the_default_connection_when_the_engine_is_wrong(): void
    {
        $engine = DB::connection()->getDriverName();
        config(['health.expected_engine' => $engine === 'mysql' ? 'sqlite' : 'mysql']);
        DB::purge();

        $this->expectException(DatabaseEngineMismatch::class);
        DB::select('select 1');
    }

    public function test_queries_run_when_the_engine_matches_or_no_engine_is_expected(): void
    {
        $engine = DB::connection()->getDriverName();

        config(['health.expected_engine' => $engine]);
        DB::purge();
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);

        config(['health.expected_engine' => '']);
        DB::purge();
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);
    }

    public function test_code_that_takes_the_pdo_itself_cannot_write_on_the_wrong_engine(): void
    {
        // ListingStudioTrialMonitoring writes its counters through the default
        // connection's PDO, which never passes Laravel's beforeExecuting hooks.
        $file = tempnam(sys_get_temp_dir(), 'health-guard-');
        $this->temporaryFiles[] = $file;
        $this->useSqliteFile($file);
        Schema::create('listing_studio_trial_counters', function (Blueprint $table) {
            $table->date('day');
            $table->string('event', 64);
            $table->string('service', 32);
            $table->unsignedInteger('count')->default(0);
            $table->primary(['day', 'event', 'service']);
        });
        config([
            'health.expected_engine' => 'mysql',
            'database.connections.health_side_file' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => ''],
        ]);
        DB::purge('sqlite');

        app(ListingStudioTrialMonitoring::class)->count('trial_rate_limit');

        $this->assertSame(0, DB::connection('health_side_file')->table('listing_studio_trial_counters')->count(),
            'A process on the wrong engine wrote to the old database.');
        $this->expectException(DatabaseEngineMismatch::class);
        DB::connection()->getPdo();
    }

    public function test_engine_guard_leaves_other_named_connections_alone(): void
    {
        config([
            'health.expected_engine' => 'mysql',
            'database.connections.health_side_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        $this->assertSame(1, (int) DB::connection('health_side_sqlite')->selectOne('select 1 as ok')->ok);
        DB::purge('health_side_sqlite');
    }

    public function test_expected_engine_and_server_uuid_are_off_by_default(): void
    {
        $config = require config_path('health.php');

        $this->assertSame('', $config['expected_engine']);
        $this->assertSame('', $config['mysql_server_uuid']);
    }

    public function test_up_compares_the_server_uuid_on_mysql_and_ignores_it_elsewhere(): void
    {
        $engine = DB::connection()->getDriverName();
        if ($engine !== 'mysql') {
            // CI runs with --fail-on-skipped, so the SQLite run checks the other half.
            config(['health.mysql_server_uuid' => '00000000-0000-0000-0000-000000000000']);
            $this->get('/up')->assertOk()->assertExactJson(['status' => 'ok', 'engine' => $engine]);

            return;
        }
        $uuid = (string) DB::selectOne('select @@server_uuid as uuid')->uuid;

        config(['health.mysql_server_uuid' => strtoupper($uuid)]);
        $this->get('/up')->assertOk()
            ->assertExactJson(['status' => 'ok', 'engine' => 'mysql', 'server_identity' => 'match']);

        config(['health.mysql_server_uuid' => '00000000-0000-0000-0000-000000000000']);
        $this->get('/up')->assertStatus(503)
            ->assertExactJson(['status' => 'fail', 'engine' => 'mysql', 'reason' => 'server_identity_mismatch']);
    }

    private function useSqliteFile(string $path): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
        ]);
        DB::purge('sqlite');
    }

    private function createMigrationsTable(): void
    {
        $table = config('database.migrations.table', 'migrations');
        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->increments('id');
                $blueprint->string('migration');
                $blueprint->integer('batch');
            });
        }
    }
}
