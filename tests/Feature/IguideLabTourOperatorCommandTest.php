<?php

namespace Tests\Feature;

use App\Console\Commands\SurveyIguideLabTour;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Services\IguideLab\LabTourAnalyses;
use App\Services\IguideLab\LabTourErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Tests\Support\RecordingQueueFake;
use Tests\TestCase;

/** Operator commands of spec 11.4: iguide-lab:tour-workers (WP6) and the iguide-lab:tour-survey checks of 13.7. */
class IguideLabTourOperatorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_tour_workers_count_prints_the_clamped_integer(): void
    {
        // LabTourConfig::workers() clamps to 1..8 and uses the default 1 for a value that is not an integer.
        foreach ([[1, '1'], [3, '3'], [8, '8'], [9, '8'], [20, '8'], [0, '1'], [-4, '1'], ['5', '5'], ['abc', '1'], [null, '1'], [2.5, '1']] as [$value, $printed]) {
            config(['iguide-lab-tour.worker_count' => $value]);
            $this->assertSame(0, Artisan::call('iguide-lab:tour-workers', ['--count' => true]), var_export($value, true));
            $output = Artisan::output();
            // Exactly the integer and one newline: the installer captures it with $(...) and accepts only [1-8].
            $this->assertSame($printed."\n", $output, var_export($value, true));
            $this->assertMatchesRegularExpression('/\A[1-8]\z/', rtrim($output, "\n"));
        }
    }

    public function test_tour_workers_reports_the_queue_target(): void
    {
        $cases = [
            [['studio', 'studio', 1], ['connection' => 'studio', 'queue' => 'studio', 'workers' => 1, 'dedicated' => false]],
            [['iguide-lab-tour', 'iguide-lab-tour', 3], ['connection' => 'iguide-lab-tour', 'queue' => 'iguide-lab-tour', 'workers' => 3, 'dedicated' => true]],
            // The installed program listens only on the iguide-lab-tour queue of the iguide-lab-tour connection.
            [['iguide-lab-tour', 'studio', 2], ['connection' => 'iguide-lab-tour', 'queue' => 'studio', 'workers' => 2, 'dedicated' => false]],
            // An unknown connection falls back to studio/studio (spec 3.2), so it is not dedicated.
            [['missing-connection', 'iguide-lab-tour', 12], ['connection' => 'studio', 'queue' => 'studio', 'workers' => 8, 'dedicated' => false]],
        ];
        foreach ($cases as [[$connection, $queue, $workers], $expected]) {
            config(['iguide-lab-tour.queue_connection' => $connection, 'iguide-lab-tour.queue' => $queue, 'iguide-lab-tour.worker_count' => $workers]);
            $this->assertSame(0, Artisan::call('iguide-lab:tour-workers'));
            $output = Artisan::output();
            $this->assertSame(1, substr_count($output, "\n"));
            $this->assertSame($expected, json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    /** Tests WP3's command (SurveyIguideLabTour, spec 11.4); it runs once that package is merged. */
    #[RequiresMethod(SurveyIguideLabTour::class, 'handle')]
    public function test_tour_survey_without_limit_fails(): void
    {
        RecordingQueueFake::install($this);
        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        [$project, $analysis] = $this->seedAnalysis();
        $this->mock(LabTourAnalyses::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $before = $this->rowCounts();

        $this->artisan('iguide-lab:tour-survey', ['project' => $project->id, 'import' => $analysis->import_id])
            ->expectsOutputToContain('A --limit between 1 and 80 is required.')
            ->assertFailed();
        foreach (['0', '81', '100', '08', '-1', '1.5', '3 ', ' 3', 'abc', ''] as $limit) {
            $this->artisan('iguide-lab:tour-survey', ['project' => $project->id, 'import' => $analysis->import_id, '--limit' => $limit])->assertFailed();
        }
        $this->assertSame($before, $this->rowCounts());
    }

    /** Tests WP3's command (SurveyIguideLabTour, spec 11.4); it runs once that package is merged. */
    #[RequiresMethod(SurveyIguideLabTour::class, 'handle')]
    public function test_tour_survey_limit_receipt_has_no_secrets(): void
    {
        RecordingQueueFake::install($this);
        $keyFile = tempnam(sys_get_temp_dir(), 'tour-operator-key-');
        file_put_contents($keyFile, 'fixture-primary');
        config(['iguide-lab-tour.tour_analysis_enabled' => true, 'iguide-lab.openai_primary_key_path' => $keyFile, 'services.openai.api_key' => 'fixture-fallback']);
        $token = str_repeat('c', 64);
        [$project, $analysis] = $this->seedAnalysis($token);
        $this->mock(LabTourAnalyses::class, function (MockInterface $mock) use ($project, $analysis) {
            $mock->shouldReceive('create')->once()->with(
                Mockery::on(fn ($given) => $given instanceof IguideLabProject && $given->id === $project->id),
                Mockery::on(fn ($input) => is_array($input) && array_keys($input) === ['requestId', 'importId', 'preset']
                    && preg_match(LabTourErrors::UUID_RULE, $input['requestId']) === 1
                    && $input['importId'] === $analysis->import_id && $input['preset'] === 'golden-hour'),
                3,
            )->andReturn([$analysis, true]);
        });

        try {
            $exit = Artisan::call('iguide-lab:tour-survey', ['project' => $project->id, 'import' => $analysis->import_id, '--limit' => '3']);
            $output = Artisan::output();
        } finally {
            unlink($keyFile);
        }

        $this->assertSame(0, $exit, $output);
        $this->assertSame(1, substr_count($output, "\n"), 'one JSON line');
        $receipt = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['version', 'analysisId', 'importId', 'status', 'captures', 'dispatchAllowed', 'cachedSurveys', 'pending',
            'surveyCallsLeftProject', 'surveyCallsLeftToday'], array_keys($receipt));
        $this->assertSame(['tour-survey-receipt/1', $analysis->id, $analysis->import_id], [$receipt['version'], $receipt['analysisId'], $receipt['importId']]);
        $this->assertIsString($receipt['status']);
        foreach (['captures', 'dispatchAllowed', 'cachedSurveys', 'pending', 'surveyCallsLeftProject', 'surveyCallsLeftToday'] as $count) {
            $this->assertIsInt($receipt[$count], $count);
            $this->assertGreaterThanOrEqual(0, $receipt[$count], $count);
        }
        foreach ([$token, hash('sha256', $token), 'fixture-primary', 'fixture-fallback', $keyFile, str_repeat('b', 64), 'private-input-fixture',
            storage_path(), 'token', 'Bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    /** @return array{0: IguideLabProject, 1: IguideLabTourAnalysis} a project with one completed import and a 3-capture analysis */
    private function seedAnalysis(string $token = ''): array
    {
        $project = IguideLabProject::create(['name' => 'Tour operator fixture', 'token_hash' => hash('sha256', $token !== '' ? $token : Str::random(64)),
            'expires_at' => now()->addDays(10), 'max_runs' => 60]);
        $importId = (string) Str::uuid();
        DB::table('iguide_lab_imports')->insert(['id' => $importId, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'source_kind' => 'zip', 'private_input' => 'private-input-fixture', 'status' => 'completed',
            'sources' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $analysis = IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'import_id' => $importId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'golden-hour', 'status' => 'analysing',
            'record_revision' => 1, 'selections' => ['golden-hour' => ['revision' => 0, 'changes' => new \stdClass]]]);
        for ($position = 0; $position < 3; $position++) {
            $case = (string) Str::uuid();
            DB::table('iguide_lab_cases')->insert(['id' => $case, 'project_id' => $project->id, 'label' => 'Room '.$position, 'projection' => 'equirectangular',
                'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $case), 'source_path' => 'fixture/'.$case.'.png',
                'source_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
            IguideLabTourCapture::create(['id' => (string) Str::uuid(), 'analysis_id' => $analysis->id, 'project_id' => $project->id, 'case_id' => $case,
                'position' => $position, 'source_sha256' => hash('sha256', $case), 'preview_sha256' => str_repeat('2', 64), 'dispatch_allowed' => $position < 2]);
        }

        return [$project, $analysis];
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['iguide_lab_tour_analyses', 'iguide_lab_tour_analysis_requests', 'iguide_lab_tour_captures', 'iguide_lab_tour_surveys', 'jobs'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
