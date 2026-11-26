<?php

namespace Tests\Feature;

use App\Models\IguideLabProject;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

/** Tour tables (spec 4.1-4.7): reversible, isolated from the existing lab tables, keys as specified. */
class IguideLabTourMigrationTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    private const FILES = [
        '2026_11_16_100000_create_iguide_lab_tour_surveys.php' => ['iguide_lab_tour_surveys', 'iguide_lab_tour_survey_attempts'],
        '2026_11_16_100100_create_iguide_lab_tour_analyses.php' => ['iguide_lab_tour_analyses', 'iguide_lab_tour_analysis_requests'],
        '2026_11_16_100200_create_iguide_lab_tour_captures.php' => ['iguide_lab_tour_captures'],
        '2026_11_16_100300_create_iguide_lab_tour_runs.php' => ['iguide_lab_tour_runs', 'iguide_lab_tour_run_chunks', 'iguide_lab_tour_run_targets'],
        '2026_11_22_120200_preserve_iguide_lab_project_deletion.php' => [],
    ];

    private const COLUMNS = [
        'iguide_lab_tour_surveys' => ['id', 'project_id', 'case_id', 'source_import_id', 'case_source_sha256', 'preview_sha256', 'survey_version', 'generation',
            'status', 'error_code', 'parse_error', 'protocol', 'data', 'result', 'result_sha256', 'parser_version', 'provider_request_id', 'usage',
            'dispatch_count', 'dispatched_at', 'started_at', 'completed_at', 'created_at', 'updated_at'],
        'iguide_lab_tour_survey_attempts' => ['id', 'project_id', 'survey_id', 'attempt_index', 'credential_source', 'requested_model', 'status', 'http_status',
            'provider_request_id', 'duration_ms', 'started_at', 'completed_at', 'created_at', 'updated_at'],
        'iguide_lab_tour_analyses' => ['id', 'project_id', 'import_id', 'request_id', 'request_hash', 'priority_preset', 'status', 'record_revision', 'selections',
            'monitor_heartbeat_at', 'monitor_token', 'monitor_seq', 'monitor_idle', 'cancelled_at', 'completed_at', 'created_at', 'updated_at'],
        'iguide_lab_tour_analysis_requests' => ['id', 'project_id', 'analysis_id', 'request_id', 'request_hash', 'created_at', 'updated_at'],
        'iguide_lab_tour_captures' => ['id', 'analysis_id', 'project_id', 'case_id', 'position', 'room_name', 'floor_name', 'source_sha256', 'preview_sha256',
            'exterior_hint', 'status', 'error_code', 'survey_id', 'dispatch_allowed', 'paid_survey_requests', 'survey', 'applicability', 'candidates',
            'defaults_version', 'defaults_flags', 'surveyed_at', 'created_at', 'updated_at'],
        'iguide_lab_tour_runs' => ['id', 'project_id', 'analysis_id', 'request_id', 'request_hash', 'preset', 'status', 'selection_revision', 'selection_sha256',
            'estimate', 'chunk_size', 'error_code', 'consecutive_chunk_failures', 'not_ready_ticks', 'monitor_heartbeat_at', 'monitor_token', 'monitor_seq',
            'cancelled_at', 'completed_at', 'created_at', 'updated_at'],
        'iguide_lab_tour_run_chunks' => ['id', 'run_id', 'project_id', 'position', 'request_id', 'case_ids', 'status', 'workflow_id', 'start_attempts', 'error_code',
            'diagnostic', 'created_at', 'updated_at'],
        'iguide_lab_tour_run_targets' => ['id', 'run_id', 'project_id', 'case_id', 'position', 'candidate_ids', 'state', 'chunk_id', 'workflow_id', 'workflow_item_id',
            'has_attempt', 'reasons', 'accepted_at', 'acceptance_log', 'synced_fingerprint', 'created_at', 'updated_at'],
    ];

    /** table => [[columns, unique]] besides the primary key. */
    private const INDEXES = [
        'iguide_lab_tour_surveys' => [[['project_id', 'case_id', 'preview_sha256', 'survey_version', 'generation'], true], [['status', 'dispatched_at'], false], [['project_id', 'case_id'], false]],
        'iguide_lab_tour_survey_attempts' => [[['survey_id', 'attempt_index'], true], [['project_id'], false], [['started_at'], false]],
        'iguide_lab_tour_analyses' => [[['project_id', 'request_id'], true], [['project_id', 'import_id'], true], [['project_id', 'created_at'], false], [['status'], false]],
        'iguide_lab_tour_analysis_requests' => [[['project_id', 'request_id'], true], [['analysis_id'], false]],
        'iguide_lab_tour_captures' => [[['analysis_id', 'case_id'], true], [['analysis_id', 'position'], true], [['survey_id'], false], [['project_id', 'case_id'], false]],
        'iguide_lab_tour_runs' => [[['project_id', 'request_id'], true], [['analysis_id', 'preset', 'status'], false], [['project_id', 'status'], false], [['status'], false]],
        'iguide_lab_tour_run_chunks' => [[['run_id', 'position'], true], [['project_id', 'request_id'], true]],
        'iguide_lab_tour_run_targets' => [[['run_id', 'case_id'], true], [['project_id', 'case_id', 'state'], false], [['workflow_item_id'], false]],
    ];

    /** table => [column => [foreign table, on delete]]. Case foreign keys never cascade. */
    private const FOREIGN_KEYS = [
        'iguide_lab_tour_surveys' => ['project_id' => ['iguide_lab_projects', 'cascade'], 'case_id' => ['iguide_lab_cases', 'no action']],
        'iguide_lab_tour_survey_attempts' => ['project_id' => ['iguide_lab_projects', 'cascade'], 'survey_id' => ['iguide_lab_tour_surveys', 'cascade']],
        'iguide_lab_tour_analyses' => ['project_id' => ['iguide_lab_projects', 'cascade'], 'import_id' => ['iguide_lab_imports', 'cascade']],
        'iguide_lab_tour_analysis_requests' => ['project_id' => ['iguide_lab_projects', 'cascade'], 'analysis_id' => ['iguide_lab_tour_analyses', 'cascade']],
        'iguide_lab_tour_captures' => ['analysis_id' => ['iguide_lab_tour_analyses', 'cascade'], 'project_id' => ['iguide_lab_projects', 'cascade'], 'case_id' => ['iguide_lab_cases', 'no action']],
        'iguide_lab_tour_runs' => ['project_id' => ['iguide_lab_projects', 'cascade'], 'analysis_id' => ['iguide_lab_tour_analyses', 'cascade']],
        'iguide_lab_tour_run_chunks' => ['run_id' => ['iguide_lab_tour_runs', 'cascade'], 'project_id' => ['iguide_lab_projects', 'cascade']],
        'iguide_lab_tour_run_targets' => ['run_id' => ['iguide_lab_tour_runs', 'cascade'], 'project_id' => ['iguide_lab_projects', 'cascade'], 'case_id' => ['iguide_lab_cases', 'no action']],
    ];

    private const LAB_TABLES = ['iguide_lab_projects', 'iguide_lab_cases', 'iguide_lab_imports', 'iguide_lab_analyses', 'iguide_lab_workflows',
        'iguide_lab_workflow_items', 'iguide_lab_tour_budgets', 'jobs'];

    public function test_tour_migrations_roll_back_and_reapply_without_touching_lab_tables(): void
    {
        $this->seedRows();
        $tourTables = array_merge(...array_values(self::FILES));
        $allTables = $this->tables();
        foreach ($tourTables as $table) $this->assertContains($table, $allTables);
        $before = $this->labSnapshot();
        $this->assertSame(1, DB::table('iguide_lab_tour_budgets')->count());

        $migrations = array_map(fn ($file) => require database_path('migrations/'.$file), array_combine(array_keys(self::FILES), array_keys(self::FILES)));
        $rolledBack = [];
        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
                $rolledBack[] = $migration;
            }
            $this->assertSame(array_values(array_diff($allTables, $tourTables)), $this->tables());
            $this->assertSame($before, $this->labSnapshot());
        } finally {
            foreach (array_reverse($rolledBack) as $migration) {
                $migration->up();
            }
        }

        $this->assertSame($allTables, $this->tables());
        $this->assertSame($before, $this->labSnapshot());
        $this->assertSame(0, DB::table('iguide_lab_tour_analyses')->count());

        $mysql = DB::getDriverName() === 'mysql';
        foreach (self::COLUMNS as $table => $columns) $this->assertSame($columns, Schema::getColumnListing($table), $table);
        foreach (self::INDEXES as $table => $expected) {
            if ($mysql) {
                $indexedPrefixes = array_column(array_column($expected, 0), 0);
                foreach (array_diff(array_keys(self::FOREIGN_KEYS[$table]), $indexedPrefixes) as $column) {
                    $expected[] = [[$column], false];
                }
            }
            $actual = array_map(fn ($index) => [$index['columns'], (bool) $index['unique']], array_values(array_filter(Schema::getIndexes($table), fn ($index) => ! $index['primary'])));
            $this->assertEqualsCanonicalizing($expected, $actual, $table);
        }
        foreach (self::FOREIGN_KEYS as $table => $expected) {
            $actual = [];
            foreach (Schema::getForeignKeys($table) as $key) {
                $onDelete = strtolower($key['on_delete']);
                $actual[$key['columns'][0]] = [$key['foreign_table'], $mysql && $onDelete === 'restrict' ? 'no action' : $onDelete];
            }
            ksort($actual);
            ksort($expected);
            $this->assertSame($expected, $actual, $table);
        }
    }

    public function test_unique_keys(): void
    {
        $rows = $this->seedRows();
        $duplicates = [
            'analysis (project, import)' => fn () => DB::table('iguide_lab_tour_analyses')->insert($this->analysisRow($rows, ['request_id' => (string) Str::uuid()])),
            'analysis (project, request)' => fn () => DB::table('iguide_lab_tour_analyses')->insert($this->analysisRow($rows, ['import_id' => $this->import($rows['project'])])),
            'analysis request (project, request)' => fn () => DB::table('iguide_lab_tour_analysis_requests')->insert($this->requestRow($rows)),
            'capture (analysis, position)' => fn () => DB::table('iguide_lab_tour_captures')->insert($this->captureRow($rows, ['case_id' => $rows['case2']])),
            'capture (analysis, case)' => fn () => DB::table('iguide_lab_tour_captures')->insert($this->captureRow($rows, ['position' => 5])),
            'run (project, request)' => fn () => DB::table('iguide_lab_tour_runs')->insert($this->runRow($rows, ['preset' => 'golden-hour'])),
            'survey identity' => fn () => DB::table('iguide_lab_tour_surveys')->insert($this->surveyRow($rows)),
            'attempt (survey, index)' => fn () => DB::table('iguide_lab_tour_survey_attempts')->insert($this->attemptRow($rows)),
            'chunk (run, position)' => fn () => DB::table('iguide_lab_tour_run_chunks')->insert($this->chunkRow($rows, ['request_id' => (string) Str::uuid()])),
            'chunk (project, request)' => fn () => DB::table('iguide_lab_tour_run_chunks')->insert($this->chunkRow($rows, ['position' => 1, 'request_id' => $rows['chunkRequest']])),
            'target (run, case)' => fn () => DB::table('iguide_lab_tour_run_targets')->insert($this->targetRow($rows, ['position' => 1])),
        ];
        foreach ($duplicates as $label => $insert) {
            try {
                $insert();
                $this->fail("Expected a unique violation for {$label}.");
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        // The next survey generation and a second chunk are distinct rows.
        DB::table('iguide_lab_tour_surveys')->insert($this->surveyRow($rows, ['generation' => 2]));
        DB::table('iguide_lab_tour_run_chunks')->insert($this->chunkRow($rows, ['position' => 1]));
        $this->assertSame([2, 2], [DB::table('iguide_lab_tour_surveys')->count(), DB::table('iguide_lab_tour_run_chunks')->count()]);
    }

    public function test_foreign_keys_cascade_from_projects_and_imports_but_never_from_cases(): void
    {
        $rows = $this->seedRows();
        foreach (['iguide_lab_tour_captures' => 'case_id', 'iguide_lab_tour_surveys' => 'case_id', 'iguide_lab_tour_run_targets' => 'case_id'] as $table => $column) {
            $this->assertSame(1, DB::table($table)->where($column, $rows['case'])->count(), $table);
        }
        try {
            DB::table('iguide_lab_cases')->where('id', $rows['case'])->delete();
            $this->fail('A case that a tour row references must not be deleted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FOREIGN KEY', $e->getMessage());
        }
        DB::table('iguide_lab_imports')->where('id', $rows['import'])->delete();
        foreach (['iguide_lab_tour_analyses', 'iguide_lab_tour_analysis_requests', 'iguide_lab_tour_captures', 'iguide_lab_tour_runs',
            'iguide_lab_tour_run_chunks', 'iguide_lab_tour_run_targets'] as $table) $this->assertSame(0, DB::table($table)->count(), $table);
        $this->assertSame([1, 1], [DB::table('iguide_lab_tour_surveys')->count(), DB::table('iguide_lab_tour_survey_attempts')->count()]);
        DB::table('iguide_lab_projects')->where('id', $rows['project'])->delete();
        $this->assertSame([0, 0, 0], [DB::table('iguide_lab_tour_surveys')->count(), DB::table('iguide_lab_tour_survey_attempts')->count(), DB::table('iguide_lab_cases')->count()]);
    }

    public function test_project_delete_removes_the_full_tour_without_touching_another_project(): void
    {
        $removed = $this->seedRows();
        $retained = $this->seedRows();

        $this->assertSame(1, DB::table('iguide_lab_projects')->where('id', $removed['project'])->delete());

        foreach (array_keys(self::COLUMNS) as $table) {
            $this->assertDatabaseMissing($table, ['project_id' => $removed['project']]);
            $this->assertDatabaseHas($table, ['project_id' => $retained['project']]);
        }
        $this->assertDatabaseMissing('iguide_lab_cases', ['id' => $removed['case']]);
        $this->assertDatabaseHas('iguide_lab_cases', ['id' => $retained['case']]);
    }

    private function seedRows(): array
    {
        $project = IguideLabProject::create(['name' => 'Tour migration fixture', 'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(10), 'max_runs' => 60])->id;
        [$case, $case2] = [$this->labCase($project, 'Kitchen'), $this->labCase($project, 'Hall')];
        $rows = ['project' => $project, 'case' => $case, 'case2' => $case2, 'import' => $this->import($project),
            'analysis' => (string) Str::uuid(), 'analysisRequest' => (string) Str::uuid(), 'run' => (string) Str::uuid(), 'runRequest' => (string) Str::uuid(),
            'survey' => (string) Str::uuid(), 'chunkRequest' => (string) Str::uuid()];
        DB::table('iguide_lab_tour_budgets')->insert(['id' => (string) Str::uuid(), 'project_id' => $project, 'max_items' => 4, 'reserved_items' => 0,
            'limits' => '{}', 'reserved' => '{}', 'authority_sha256' => str_repeat('e', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('iguide_lab_tour_surveys')->insert($this->surveyRow($rows));
        DB::table('iguide_lab_tour_survey_attempts')->insert($this->attemptRow($rows));
        DB::table('iguide_lab_tour_analyses')->insert($this->analysisRow($rows));
        DB::table('iguide_lab_tour_analysis_requests')->insert($this->requestRow($rows));
        DB::table('iguide_lab_tour_captures')->insert($this->captureRow($rows));
        DB::table('iguide_lab_tour_runs')->insert($this->runRow($rows));
        DB::table('iguide_lab_tour_run_chunks')->insert($this->chunkRow($rows));
        DB::table('iguide_lab_tour_run_targets')->insert($this->targetRow($rows));

        return $rows;
    }

    private function labCase(string $project, string $label): string
    {
        $id = (string) Str::uuid();
        DB::table('iguide_lab_cases')->insert(['id' => $id, 'project_id' => $project, 'label' => $label, 'projection' => 'equirectangular', 'width' => 2048,
            'height' => 1024, 'source_sha256' => hash('sha256', $label), 'source_path' => 'fixture/'.$id.'.png', 'source_mime' => 'image/png',
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function import(string $project): string
    {
        $id = (string) Str::uuid();
        DB::table('iguide_lab_imports')->insert(['id' => $id, 'project_id' => $project, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'source_kind' => 'zip', 'private_input' => 'fixture', 'status' => 'completed', 'sources' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function surveyRow(array $r, array $o = []): array
    {
        return $o + ['id' => (string) Str::uuid(), 'project_id' => $r['project'], 'case_id' => $r['case'], 'source_import_id' => $r['import'],
            'case_source_sha256' => str_repeat('1', 64), 'preview_sha256' => str_repeat('2', 64), 'survey_version' => 'tour-survey/1', 'generation' => 1,
            'status' => 'queued', 'protocol' => '{}', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()];
    }

    private function attemptRow(array $r): array
    {
        $survey = DB::table('iguide_lab_tour_surveys')->where('project_id', $r['project'])->where('generation', 1)->value('id');

        return ['id' => (string) Str::uuid(), 'project_id' => $r['project'], 'survey_id' => $survey, 'attempt_index' => 0, 'credential_source' => 'lab-primary',
            'requested_model' => 'gpt-6-astra', 'status' => 'submitting', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }

    private function analysisRow(array $r, array $o = []): array
    {
        return $o + ['id' => $o === [] ? $r['analysis'] : (string) Str::uuid(), 'project_id' => $r['project'], 'import_id' => $r['import'],
            'request_id' => $r['analysisRequest'], 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'twilight', 'record_revision' => 1,
            'selections' => '{}', 'created_at' => now(), 'updated_at' => now()];
    }

    private function requestRow(array $r): array
    {
        return ['id' => (string) Str::uuid(), 'project_id' => $r['project'], 'analysis_id' => $r['analysis'], 'request_id' => $r['analysisRequest'],
            'request_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()];
    }

    private function captureRow(array $r, array $o = []): array
    {
        return $o + ['id' => (string) Str::uuid(), 'analysis_id' => $r['analysis'], 'project_id' => $r['project'], 'case_id' => $r['case'], 'position' => 0,
            'source_sha256' => str_repeat('3', 64), 'preview_sha256' => str_repeat('2', 64), 'created_at' => now(), 'updated_at' => now()];
    }

    private function runRow(array $r, array $o = []): array
    {
        return $o + ['id' => $o === [] ? $r['run'] : (string) Str::uuid(), 'project_id' => $r['project'], 'analysis_id' => $r['analysis'], 'request_id' => $r['runRequest'],
            'request_hash' => str_repeat('c', 64), 'preset' => 'twilight', 'selection_revision' => 1, 'selection_sha256' => str_repeat('d', 64), 'estimate' => '{}',
            'chunk_size' => 8, 'created_at' => now(), 'updated_at' => now()];
    }

    private function chunkRow(array $r, array $o = []): array
    {
        return $o + ['id' => (string) Str::uuid(), 'run_id' => $r['run'], 'project_id' => $r['project'], 'position' => 0,
            'request_id' => $o === [] ? $r['chunkRequest'] : (string) Str::uuid(), 'case_ids' => json_encode([$r['case']]), 'created_at' => now(), 'updated_at' => now()];
    }

    private function targetRow(array $r, array $o = []): array
    {
        return $o + ['id' => (string) Str::uuid(), 'run_id' => $r['run'], 'project_id' => $r['project'], 'case_id' => $r['case'], 'position' => 0,
            'candidate_ids' => '["l1"]', 'state' => 'waiting', 'reasons' => '[]', 'created_at' => now(), 'updated_at' => now()];
    }

    private function tables(): array
    {
        $schema = DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null;
        $names = Schema::getTableListing($schema, schemaQualified: false);
        sort($names);

        return $names;
    }

    private function labSnapshot(): array
    {
        $snapshot = [];
        foreach (self::LAB_TABLES as $table) {
            $snapshot[$table] = ['columns' => Schema::getColumnListing($table), 'indexes' => Schema::getIndexes($table),
                'foreignKeys' => Schema::getForeignKeys($table), 'rows' => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
        }

        return $snapshot;
    }
}
