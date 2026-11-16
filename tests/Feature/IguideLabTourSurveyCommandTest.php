<?php

namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TourAnalysis\SeedsTourAnalyses;
use Tests\TestCase;

/** Operator command iguide-lab:tour-survey (spec 11.4, D9): required --limit, strict input, one JSON receipt without secrets. */
class IguideLabTourSurveyCommandTest extends TestCase
{
    use RefreshDatabase, SeedsTourAnalyses;

    private IguideLabProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        $this->installTourDoubles();
        $this->project = $this->tourProject();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_tour_survey_without_limit_fails(): void
    {
        $import = $this->tourImport($this->project, 2);
        foreach ([[], ['--limit' => '0'], ['--limit' => '81'], ['--limit' => '05'], ['--limit' => '3.0'], ['--limit' => 'abc'], ['--limit' => '']] as $options) {
            [$code, $output] = $this->run_(['project' => $this->project->id, 'import' => $import->id] + $options);
            $this->assertSame([1, 'A --limit between 1 and 80 is required.'], [$code, trim($output)], json_encode($options));
        }
        $this->assertSame(0, IguideLabTourAnalysis::count());
        $this->assertSame([], $this->monitorPushes());
    }

    public function test_tour_survey_limit_receipt_has_no_secrets(): void
    {
        $import = $this->tourImport($this->project, ['Kitchen', 'Front yard', 'Hall', 'Bath']);
        DB::table('iguide_lab_imports')->where('id', $import->id)->update(['source_kind' => 'iguide_link',
            'private_input' => encrypt(['tour' => ['pageUrl' => 'https://youriguide.com/sample/', 'accessToken' => 'fixture-access-token-value']])]);
        $this->seedSurvey($this->project, $this->caseIds($import)[3]);
        $this->seedAttempts($this->project, 5);

        [$code, $output] = $this->run_(['project' => strtoupper($this->project->id), 'import' => $import->id, '--limit' => '2', '--preset' => 'grass']);
        $this->assertSame(0, $code, $output);
        $lines = array_values(array_filter(explode("\n", trim($output))));
        $this->assertCount(1, $lines, 'one JSON line');
        $receipt = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $analysis = IguideLabTourAnalysis::firstOrFail();
        $this->assertSame(['version' => 'tour-survey-receipt/1', 'analysisId' => $analysis->id, 'importId' => $import->id, 'status' => 'analysing',
            'captures' => 4, 'dispatchAllowed' => 2, 'cachedSurveys' => 1, 'pending' => 3, 'surveyCallsLeftProject' => 235, 'surveyCallsLeftToday' => 155], $receipt);
        // Grass puts the exterior first: the yard and the kitchen may be surveyed; the hall waits.
        $this->assertSame([true, true, false, true], IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('dispatch_allowed')
            ->map(fn ($v) => (bool) $v)->all());
        foreach (['token', 'youriguide', 'http', 'private', 'secret', 'requestId', 'hash'] as $secret) $this->assertStringNotContainsStringIgnoringCase($secret, $output);
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $output);
        $this->assertCount(1, $this->monitorPushes());
        $this->assertSame('grass', $analysis->priority_preset);

        // Again with --limit=1 while those captures are still pending: exactly the next one stays allowed, on the same record.
        [$code, $output] = $this->run_(['project' => $this->project->id, 'import' => $import->id, '--limit' => '1']);
        $this->assertSame(0, $code, $output);
        $this->assertSame([$analysis->id, 1], [json_decode(trim($output), true)['analysisId'], json_decode(trim($output), true)['dispatchAllowed']]);
        $this->assertSame(1, IguideLabTourAnalysis::count());
        Http::assertNothingSent();
    }

    public function test_tour_survey_refuses_bad_input_disabled_flag_and_inactive_projects(): void
    {
        $import = $this->tourImport($this->project, 2);
        $cases = [
            [['project' => 'not-a-uuid', 'import' => $import->id, '--limit' => '1'], 'The project and import must be UUIDs.'],
            [['project' => $this->project->id, 'import' => '8c1a6a2e-0f3b-0c4d-9e5f-1a2b3c4d5e6f', '--limit' => '1'], 'The project and import must be UUIDs.'],
            [['project' => $this->project->id, 'import' => $import->id, '--limit' => '1', '--preset' => 'sky'], 'The preset must be golden-hour, twilight, grass or declutter.'],
            [['project' => (string) Str::uuid(), 'import' => $import->id, '--limit' => '1'], 'The lab project was not found.'],
            [['project' => $this->project->id, 'import' => (string) Str::uuid(), '--limit' => '1'], 'The import was not found.'],
            [['project' => $this->project->id, 'import' => $this->tourImport($this->project, 1, 'importing')->id, '--limit' => '1'], 'The import has not finished yet.'],
        ];
        foreach ($cases as [$arguments, $message]) {
            [$code, $output] = $this->run_($arguments);
            $this->assertSame([1, $message], [$code, trim($output)], json_encode($arguments));
        }
        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $this->assertSame([1, 'Tour analysis is disabled.'], $this->trimmed($this->run_(['project' => $this->project->id, 'import' => $import->id, '--limit' => '1'])));
        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        $this->project->update(['revoked_at' => now()]);
        $this->assertSame([1, 'The lab project is not active.'], $this->trimmed($this->run_(['project' => $this->project->id, 'import' => $import->id, '--limit' => '1'])));
        $this->assertSame(0, IguideLabTourAnalysis::count());
        $this->assertSame([], $this->monitorPushes());
    }

    /** @return array{0:int,1:string} */
    private function run_(array $arguments): array
    {
        $code = Artisan::call('iguide-lab:tour-survey', $arguments);

        return [$code, Artisan::output()];
    }

    private function trimmed(array $result): array
    {
        return [$result[0], trim($result[1])];
    }
}
