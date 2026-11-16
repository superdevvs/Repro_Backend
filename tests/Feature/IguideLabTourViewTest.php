<?php

namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourAnalysisRequest;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunChunk;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Services\IguideLab\LabTourRevisions;
use App\Services\IguideLab\LabTourView;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tour models (spec 4.8), the read snapshot (10.7) and the revision bump (10.6). */
class IguideLabTourViewTest extends TestCase
{
    use RefreshDatabase;

    private IguideLabProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->project = $this->project();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_models_cast_hide_and_relate_as_specified(): void
    {
        [$analysis, $cases] = $this->analysis($this->project, 3);
        $survey = IguideLabTourSurvey::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'case_id' => $cases[0],
            'source_import_id' => $analysis->import_id, 'case_source_sha256' => str_repeat('1', 64), 'preview_sha256' => str_repeat('2', 64),
            'survey_version' => 'tour-survey/1', 'protocol' => ['version' => 'tour-survey/1'], 'data' => ['base' => 'x', 'warnings' => []],
            'result' => ['version' => 'tour-survey-result/1'], 'usage' => ['input_tokens' => 10], 'dispatched_at' => now()]);
        foreach ([1, 0] as $index) IguideLabTourSurveyAttempt::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'survey_id' => $survey->id,
            'attempt_index' => $index, 'credential_source' => $index ? 'dashboard-fallback' : 'lab-primary', 'requested_model' => 'gpt-6-astra',
            'http_status' => $index ? 200 : 429, 'duration_ms' => 1200, 'started_at' => now()]);
        IguideLabTourCapture::findOrFail($analysis->captures->first()->id)->update(['survey_id' => $survey->id, 'status' => 'surveyed',
            'survey' => ['space' => ['label' => 'Kitchen']], 'applicability' => ['golden-hour' => true], 'candidates' => [], 'defaults_flags' => ['grassRunsEnabled' => false],
            'surveyed_at' => now()]);
        $request = IguideLabTourAnalysisRequest::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'analysis_id' => $analysis->id,
            'request_id' => $analysis->request_id, 'request_hash' => str_repeat('b', 64)]);
        $run = $this->tourRun($analysis, 'twilight', $cases);

        $analysis = IguideLabTourAnalysis::findOrFail($analysis->id);
        $this->assertSame(['golden-hour' => ['revision' => 0, 'changes' => []]], $analysis->selections);
        $this->assertSame([1, 0, false], [$analysis->record_revision, $analysis->monitor_seq, $analysis->monitor_idle]);
        $this->assertArrayNotHasKey('request_hash', $analysis->toArray());
        $this->assertArrayNotHasKey('monitor_token', $analysis->toArray());
        $this->assertSame([0, 1, 2], $analysis->captures->pluck('position')->all());
        $this->assertSame([$run->id], $analysis->runs->pluck('id')->all());
        $this->assertArrayNotHasKey('request_hash', $request->fresh()->toArray());

        $capture = $analysis->captures->first();
        $this->assertSame([0, 0, true, false], [$capture->position, $capture->paid_survey_requests, $capture->dispatch_allowed, $capture->exterior_hint]);
        $this->assertSame(['space' => ['label' => 'Kitchen']], $capture->survey);
        $this->assertSame([], $capture->candidates);
        $this->assertInstanceOf(Carbon::class, $capture->surveyed_at);
        $this->assertSame($survey->id, $capture->surveyRecord->id);

        $survey = $survey->fresh();
        $this->assertSame([1, 0], [$survey->generation, $survey->dispatch_count]);
        $this->assertSame(['input_tokens' => 10], $survey->usage);
        $this->assertSame('queued', $survey->status);
        $this->assertInstanceOf(Carbon::class, $survey->dispatched_at);
        $this->assertArrayNotHasKey('data', $survey->toArray());
        $this->assertArrayNotHasKey('protocol', $survey->toArray());
        $this->assertSame([0, 1], $survey->attempts->pluck('attempt_index')->all());
        $attempt = $survey->attempts->first();
        $this->assertSame([429, 1200, 'submitting'], [$attempt->http_status, $attempt->duration_ms, $attempt->status]);
        $this->assertInstanceOf(Carbon::class, $attempt->started_at);

        $run = $run->fresh();
        $this->assertSame(['roomsSelected' => 3], $run->estimate);
        $this->assertSame([1, 8, 0, 0, 0, 'creating'], [$run->selection_revision, $run->chunk_size, $run->consecutive_chunk_failures, $run->not_ready_ticks,
            $run->monitor_seq, $run->status]);
        $this->assertArrayNotHasKey('request_hash', $run->toArray());
        $this->assertArrayNotHasKey('monitor_token', $run->toArray());
        $this->assertSame([0, 1, 2], $run->targets->pluck('position')->all());
        $this->assertSame([0, 1], $run->chunks->pluck('position')->all());
        $chunk = $run->chunks->first();
        $this->assertSame([[$cases[0], $cases[1]], 0, 'reserved', null], [$chunk->case_ids, $chunk->start_attempts, $chunk->status, $chunk->diagnostic]);
        $target = $run->targets->first();
        $this->assertSame([['l1'], [], [], false, 0], [$target->candidate_ids, $target->reasons, $target->acceptance_log, $target->has_attempt, $target->position]);
        $this->assertNull($target->accepted_at);
        IguideLabTourRunTarget::whereKey($target->id)->update(['accepted_at' => now(), 'has_attempt' => true]);
        $this->assertInstanceOf(Carbon::class, $target->fresh()->accepted_at);
        $this->assertTrue($target->fresh()->has_attempt);
    }

    public function test_view_loads_one_project_scoped_snapshot_with_ordered_children(): void
    {
        [$analysis, $cases] = $this->analysis($this->project, 4);
        $older = $this->tourRun($analysis, 'golden-hour', $cases);
        $this->travel(5)->seconds();
        $newer = $this->tourRun($analysis, 'twilight', array_slice($cases, 0, 2));
        $this->travel(5)->seconds();
        $tieA = $this->tourRun($analysis, 'grass', [$cases[0]]);
        $tieB = $this->tourRun($analysis, 'declutter', [$cases[1]]);
        [$aId, $bId] = strcmp($tieA->id, $tieB->id) > 0 ? [$tieA->id, $tieB->id] : [$tieB->id, $tieA->id];
        [$other] = $this->analysis($this->project(), 1);

        DB::enableQueryLog();
        $view = DB::transaction(fn () => LabTourView::load($this->project->id, strtoupper($analysis->id)));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) $this->assertMatchesRegularExpression('/\A\s*select\b/i', $sql);

        $this->assertSame($analysis->id, $view->analysis->id);
        $this->assertSame([0, 1, 2, 3], $view->captures->pluck('position')->all());
        $this->assertSame($cases, $view->captures->pluck('case_id')->all());
        $this->assertSame([$aId, $bId, $newer->id, $older->id], $view->runs->pluck('id')->all());
        foreach ($view->runs as $run) {
            $this->assertTrue($run->relationLoaded('targets') && $run->relationLoaded('chunks'));
            $this->assertSame(range(0, $run->targets->count() - 1), $run->targets->pluck('position')->all());
        }
        $this->assertSame(4, $view->runs->firstWhere('id', $older->id)->targets->count());
        $this->assertSame([0, 1], $view->runs->firstWhere('id', $older->id)->chunks->pluck('position')->all());

        // Rows written after the load do not change the snapshot object.
        IguideLabTourCapture::where('analysis_id', $analysis->id)->update(['status' => 'surveying']);
        $this->assertSame(['pending'], $view->captures->pluck('status')->unique()->values()->all());
        try {
            $view->captures = collect();
            $this->fail('The snapshot is read-only.');
        } catch (\Error $e) {
            $this->assertStringContainsString('readonly', $e->getMessage());
        }

        // Scoped by project: another project's analysis is not found.
        $this->expectException(ModelNotFoundException::class);
        DB::transaction(fn () => LabTourView::load($this->project->id, $other->id));
    }

    public function test_revision_bump_moves_updated_at_once_and_stops_at_the_maximum(): void
    {
        [$analysis] = $this->analysis($this->project, 1);
        $created = $analysis->fresh()->updated_at->toDateTimeString();
        $this->travel(7)->seconds();
        DB::transaction(function () use ($analysis) {
            LabTourRevisions::bump($analysis->id);
        });
        $fresh = $analysis->fresh();
        $this->assertSame(2, $fresh->record_revision);
        $this->assertSame(now()->toDateTimeString(), $fresh->updated_at->toDateTimeString());
        $this->assertNotSame($created, $fresh->updated_at->toDateTimeString());

        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['record_revision' => 2147483647, 'updated_at' => '2026-01-01 00:00:00']);
        DB::transaction(fn () => LabTourRevisions::bump($analysis->id));
        $this->assertSame([2147483647, '2026-01-01 00:00:00'], [$analysis->fresh()->record_revision, $analysis->fresh()->updated_at->toDateTimeString()]);
        // Unknown ids change nothing.
        DB::transaction(fn () => LabTourRevisions::bump((string) Str::uuid()));
        $this->assertSame(1, IguideLabTourAnalysis::count());
    }

    private function project(): IguideLabProject
    {
        return IguideLabProject::create(['name' => 'Tour view fixture', 'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(10), 'max_runs' => 60]);
    }

    /** @return array{0: IguideLabTourAnalysis, 1: list<string>} */
    private function analysis(IguideLabProject $project, int $captures): array
    {
        $importId = (string) Str::uuid();
        DB::table('iguide_lab_imports')->insert(['id' => $importId, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'source_kind' => 'zip', 'private_input' => 'fixture', 'status' => 'completed',
            'sources' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $analysis = IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'import_id' => $importId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'golden-hour',
            'record_revision' => 1, 'selections' => ['golden-hour' => ['revision' => 0, 'changes' => []]]]);
        $cases = [];
        for ($i = 0; $i < $captures; $i++) {
            $cases[] = $case = (string) Str::uuid();
            DB::table('iguide_lab_cases')->insert(['id' => $case, 'project_id' => $project->id, 'label' => 'Room '.$i, 'projection' => 'equirectangular',
                'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $case), 'source_path' => 'fixture/'.$case.'.png',
                'source_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        }
        // Inserted in reverse order so that ordering by position is proven.
        foreach (array_reverse($cases, true) as $position => $case) {
            IguideLabTourCapture::create(['id' => (string) Str::uuid(), 'analysis_id' => $analysis->id, 'project_id' => $project->id, 'case_id' => $case,
                'position' => $position, 'source_sha256' => hash('sha256', $case), 'preview_sha256' => str_repeat('2', 64)]);
        }

        return [$analysis, $cases];
    }

    private function tourRun(IguideLabTourAnalysis $analysis, string $preset, array $cases): IguideLabTourRun
    {
        $run = IguideLabTourRun::create(['id' => (string) Str::uuid(), 'project_id' => $analysis->project_id, 'analysis_id' => $analysis->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'preset' => $preset, 'selection_revision' => 1,
            'selection_sha256' => str_repeat('d', 64), 'estimate' => ['roomsSelected' => count($cases)], 'chunk_size' => 8]);
        foreach (array_reverse($cases, true) as $position => $case) {
            IguideLabTourRunTarget::create(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $analysis->project_id, 'case_id' => $case,
                'position' => $position, 'candidate_ids' => ['l1'], 'state' => 'waiting', 'reasons' => []]);
        }
        foreach (array_reverse(array_chunk($cases, 2), true) as $position => $chunk) {
            IguideLabTourRunChunk::create(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $analysis->project_id, 'position' => $position,
                'request_id' => (string) Str::uuid(), 'case_ids' => $chunk]);
        }

        return $run;
    }
}
