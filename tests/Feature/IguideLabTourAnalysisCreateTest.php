<?php

namespace Tests\Feature;

use App\Exceptions\PublicApiException;
use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Models\IguideLabImport;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourAnalysisRequest;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourSurvey;
use App\Services\IguideLab\LabTourAnalyses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\TourAnalysis\SeedsTourAnalyses;
use Tests\TestCase;

/** POST tour-analyses service (spec 10.3 E2, D8, D9): create, replay, shared record, resume, operator limit, caps. */
class IguideLabTourAnalysisCreateTest extends TestCase
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

    public function test_create_returns_202_with_captures_in_sources_order(): void
    {
        $import = $this->tourImport($this->project, ['KITCHEN', 'Front Yard', null, 'Back deck', str_repeat('Long room name ', 20)]);
        [$analysis, $queued] = $this->analyses()->create($this->project, $this->input($import, 'golden-hour', $requestId = (string) Str::uuid()));

        $this->assertTrue($queued, 'new survey work was queued (202)');
        $analysis = IguideLabTourAnalysis::findOrFail($analysis->id);
        $this->assertSame(['queued', 1, 'golden-hour', $import->id, $requestId, null], [$analysis->status, $analysis->record_revision,
            $analysis->priority_preset, $analysis->import_id, $analysis->request_id, $analysis->completed_at]);
        $this->assertSame(hash('sha256', json_encode(['importId' => $import->id])), DB::table('iguide_lab_tour_analyses')->value('request_hash'));
        $captures = IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->get();
        $this->assertSame($this->caseIds($import), $captures->pluck('case_id')->all());
        $this->assertSame([0, 1, 2, 3, 4], $captures->pluck('position')->all());
        $this->assertSame([false, true, false, true, false], $captures->pluck('exterior_hint')->all());
        $this->assertSame(['KITCHEN', 'Front Yard', null, 'Back deck'], $captures->take(4)->pluck('room_name')->all());
        $this->assertSame(200, mb_strlen($captures[4]->room_name));
        $this->assertSame([null, 'Main Floor'], [$captures[2]->floor_name, $captures[0]->floor_name]);
        foreach ($captures as $i => $capture) {
            $this->assertSame(['pending', null, null, true, 0], [$capture->status, $capture->error_code, $capture->survey_id, $capture->dispatch_allowed, $capture->paid_survey_requests]);
            $this->assertSame([$import->sources[$i]['sourceSha256'], $import->sources[$i]['previewSha256']], [$capture->source_sha256, $capture->preview_sha256]);
        }
        // Selections: all four presets at revision 0 with an empty changes object.
        $raw = json_decode(DB::table('iguide_lab_tour_analyses')->value('selections'));
        $this->assertSame(['golden-hour', 'twilight', 'grass', 'declutter'], array_keys((array) $raw));
        foreach ((array) $raw as $state) {
            $this->assertSame(0, $state->revision);
            $this->assertInstanceOf(\stdClass::class, $state->changes);
        }
        $this->assertSame([$requestId], IguideLabTourAnalysisRequest::where('analysis_id', $analysis->id)->pluck('request_id')->all());

        // After the commit: one new monitor chain, no survey job yet (the monitor dispatches within the worker bound).
        $this->assertCount(1, $this->monitorPushes());
        $this->assertSame([], $this->surveyPushes());
        $this->assertSame(['total' => 5, 'surveyed' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 5], $this->analyses()->countsDto($this->tourView($analysis)));
        $this->assertRecordParts($analysis);
        Http::assertNothingSent();
    }

    public function test_same_request_id_replays_with_200(): void
    {
        $import = $this->tourImport($this->project, 3);
        $input = $this->input($import);
        [$first] = $this->analyses()->create($this->project, $input);
        $before = DB::table('iguide_lab_tour_analyses')->first();

        $this->travel(5)->seconds();
        [$again, $queued] = $this->analyses()->create($this->project, ['requestId' => strtoupper($input['requestId'])] + $input);
        $this->assertFalse($queued);
        $this->assertSame($first->id, $again->id);
        $this->assertEquals($before, DB::table('iguide_lab_tour_analyses')->first(), 'a replay has no side effects');
        $this->assertSame([1, 1, 3], [IguideLabTourAnalysis::count(), IguideLabTourAnalysisRequest::count(), IguideLabTourCapture::count()]);
        $this->assertCount(1, $this->monitorPushes());
    }

    public function test_new_request_id_for_the_same_import_returns_the_shared_record(): void
    {
        $import = $this->tourImport($this->project, 3);
        [$first] = $this->analyses()->create($this->project, $this->input($import, 'golden-hour', $creator = (string) Str::uuid()));
        $before = IguideLabTourAnalysis::findOrFail($first->id);

        [$shared, $queued] = $this->analyses()->create($this->project, $this->input($import, 'declutter', $second = (string) Str::uuid()));
        $this->assertFalse($queued, 'nothing new to queue: 200');
        $this->assertSame($first->id, $shared->id);
        $after = IguideLabTourAnalysis::findOrFail($first->id);
        $this->assertSame([$creator, 'golden-hour', $before->record_revision], [$after->request_id, $after->priority_preset, $after->record_revision]);
        $this->assertEqualsCanonicalizing([$creator, $second], IguideLabTourAnalysisRequest::where('analysis_id', $first->id)->pluck('request_id')->all());
        // The alias requestId now replays too.
        [$replay, $queued] = $this->analyses()->create($this->project, $this->input($import, 'twilight', $second));
        $this->assertSame([$first->id, false], [$replay->id, $queued]);
        $this->assertSame(1, IguideLabTourAnalysis::count());
        $this->assertCount(1, $this->monitorPushes());
    }

    public function test_request_id_reused_for_another_import_is_409(): void
    {
        $a = $this->tourImport($this->project, 2);
        $b = $this->tourImport($this->project, 2);
        $creator = (string) Str::uuid();
        $this->analyses()->create($this->project, $this->input($a, 'golden-hour', $creator));
        $this->assertReused(fn () => $this->analyses()->create($this->project, $this->input($b, 'golden-hour', $creator)));

        // Also for a requestId that was first answered with the shared record (alias row, spec 4.3a).
        $alias = (string) Str::uuid();
        $this->analyses()->create($this->project, $this->input($a, 'twilight', $alias));
        $this->assertReused(fn () => $this->analyses()->create($this->project, $this->input($b, 'twilight', $alias)));
        $this->assertSame(0, IguideLabTourAnalysis::where('import_id', $b->id)->count());
        $this->assertSame(2, IguideLabTourAnalysisRequest::count());
    }

    public function test_client_uuid_must_match_the_reader_rule(): void
    {
        $import = $this->tourImport($this->project, 1);
        foreach (['8c1a6a2e-0f3b-0c4d-9e5f-1a2b3c4d5e6f', '8c1a6a2e-0f3b-4c4d-ce5f-1a2b3c4d5e6f', 'not-a-uuid', ''] as $bad) {
            try {
                $this->analyses()->create($this->project, $this->input($import, 'golden-hour', $bad));
                $this->fail('Rejected: '.$bad);
            } catch (PublicApiException $e) {
                $this->assertSame([422, 'tour_invalid_type'], [$e->getStatusCode(), $e->errorCode], $bad);
            }
        }
        foreach (['9f3d2c1b-7a6e-4d5c-8b4a-3f2e1d0c9b8a', '01890a5d-ac96-774b-bcce-b302099a8057', '1E4A0F2D-3C5B-4A69-B87E-9D0C1B2A3F4E'] as $i => $good) {
            $target = $i === 0 ? $import : $this->tourImport($this->project, 1);
            [$analysis] = $this->analyses()->create($this->project, ['requestId' => $good, 'importId' => strtoupper($target->id), 'preset' => 'twilight']);
            $this->assertSame(strtolower($good), IguideLabTourAnalysisRequest::where('analysis_id', $analysis->id)->value('request_id'));
            $this->assertSame([strtolower($good), $target->id], [IguideLabTourAnalysis::findOrFail($analysis->id)->request_id, $analysis->import_id]);
        }
        try {
            $this->analyses()->create($this->project, ['preset' => 'sky'] + $this->input($import));
            $this->fail('An unknown preset is refused.');
        } catch (PublicApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_inactive_project_cannot_create_through_the_service(): void
    {
        $import = $this->tourImport($this->project, 2);
        foreach ([['revoked_at' => now()], ['revoked_at' => null, 'expires_at' => now()->subMinute()]] as $state) {
            $this->project->update($state);
            try {
                $this->analyses()->create($this->project->fresh(), $this->input($import));
                $this->fail('An inactive project cannot start paid work.');
            } catch (HttpException $e) {
                $this->assertSame(401, $e->getStatusCode());
            }
        }
        $this->assertSame([0, 0, 0], [IguideLabTourAnalysis::count(), IguideLabTourAnalysisRequest::count(), IguideLabTourCapture::count()]);
        $this->assertSame([], $this->monitorPushes());
    }

    public function test_unknown_import_404_and_unfinished_import_409(): void
    {
        $other = $this->tourImport($this->tourProject(), 1);
        foreach ([(string) Str::uuid(), $other->id] as $importId) {
            try {
                $this->analyses()->create($this->project, ['requestId' => (string) Str::uuid(), 'importId' => $importId, 'preset' => 'golden-hour']);
                $this->fail('Unknown import.');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
                $this->assertNotInstanceOf(PublicApiException::class, $e);
            }
        }
        $importing = $this->tourImport($this->project, 2, 'importing');
        try {
            $this->analyses()->create($this->project, $this->input($importing));
            $this->fail('Unfinished import.');
        } catch (PublicApiException $e) {
            $this->assertSame([409, 'tour_import_not_ready', 'The import has not finished yet.'], [$e->getStatusCode(), $e->errorCode, $e->getMessage()]);
        }
        $this->assertSame(0, IguideLabTourAnalysis::count());
    }

    public function test_more_than_80_sources_is_422(): void
    {
        $this->project->update(['max_cases' => 200]);
        $import = $this->tourImport($this->project, 81);
        $this->assertTooMany(fn () => $this->analyses()->create($this->project, $this->input($import)), 'This tour has more than 80 photos.');
        config(['iguide-lab-tour.caps.max_captures' => 3]);
        $small = $this->tourImport($this->project, 4);
        $this->assertTooMany(fn () => $this->analyses()->create($this->project, $this->input($small)), 'This tour has more than 3 photos.');
        $this->assertSame([0, 0], [IguideLabTourAnalysis::count(), IguideLabTourCapture::count()]);
        // Exactly 80 is accepted.
        config(['iguide-lab-tour.caps.max_captures' => 80]);
        [$analysis, $queued] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 80)));
        $this->assertSame([true, 80], [$queued, IguideLabTourCapture::where('analysis_id', $analysis->id)->count()]);
    }

    public function test_survey_cap_429_with_public_text(): void
    {
        $import = $this->tourImport($this->project, 2);
        // Old attempts count for the project lifetime cap only, not for the rolling 24 hours.
        $this->seedAttempts($this->project, 239, now()->subDays(2));
        // One call left in the project for two captures that need a paid survey.
        $this->assertCapped(fn () => $this->analyses()->create($this->project, $this->input($import)));
        $this->assertSame([0, 0, 0], [IguideLabTourAnalysis::count(), IguideLabTourAnalysisRequest::count(), IguideLabTourCapture::count()]);

        // Cached captures need no paid call, so they pass even with the project cap exhausted.
        $this->seedAttempts($this->project, 1, now()->subDays(2));
        foreach ($this->caseIds($import) as $caseId) $this->seedSurvey($this->project, $caseId);
        [$analysis, $queued] = $this->analyses()->create($this->project, $this->input($import));
        $this->assertSame(['ready', false], [$analysis->fresh()->status, $queued]);

        // The daily cap is lab-wide: 160 attempts of another project in the last 24 hours block a new import.
        $fresh = $this->tourProject();
        $this->seedAttempts($this->tourProject(), 160, now()->subHours(23));
        $this->assertCapped(fn () => $this->analyses()->create($fresh, $this->input($this->tourImport($fresh, 1))));
        $this->travel(2)->hours();
        [, $queued] = $this->analyses()->create($fresh, $this->input(IguideLabImport::where('project_id', $fresh->id)->firstOrFail()));
        $this->assertTrue($queued, 'the window rolled past the 160 attempts');
        Http::assertNothingSent();
    }

    public function test_all_cached_create_is_ready_with_200_and_no_http(): void
    {
        $import = $this->tourImport($this->project, ['Kitchen', 'Hall', 'Garden']);
        $surveys = array_map(fn ($caseId) => $this->seedSurvey($this->project, $caseId), $this->caseIds($import));
        [$analysis, $queued] = $this->analyses()->create($this->project, $this->input($import));

        $this->assertFalse($queued);
        $analysis = $analysis->fresh();
        $this->assertSame(['ready', 1], [$analysis->status, $analysis->record_revision]);
        $this->assertNotNull($analysis->completed_at);
        $captures = IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->get();
        $this->assertSame(array_column($surveys, 'id'), $captures->pluck('survey_id')->all());
        foreach ($captures as $i => $capture) {
            $this->assertSame(['surveyed', 'tour-defaults/1', ['grassRunsEnabled' => false, 'declutterRunsEnabled' => false]],
                [$capture->status, $capture->defaults_version, $capture->defaults_flags]);
            $this->assertSame(['g'.$i, 't'.$i], array_column($capture->candidates, 'id'));
            $this->assertNotNull($capture->surveyed_at);
        }
        $this->assertSame([], $this->monitorPushes());
        $this->assertSame([], $this->surveyPushes());
        $this->assertSame([], $this->surveys->reserved, 'the cached lookup is read-only');
        $this->assertRecordParts($analysis);
        Http::assertNothingSent();
    }

    public function test_cancelled_record_resumes_with_202_within_bounds(): void
    {
        $import = $this->tourImport($this->project, 6);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import));
        $cases = $this->caseIds($import);
        // 0: a paid answer is stored (survey_unreadable) -> stays failed (free re-parse only).
        $paid = $this->seedSurvey($this->project, $cases[0], 'failed', 'survey_unreadable', null, 1, ['received']);
        // 1: unknown outcome -> stays failed.
        $unknown = $this->seedSurvey($this->project, $cases[1], 'needs_attention', 'survey_outcome_unknown', null, 1, ['unknown']);
        // 2: skipped while its survey was never claimed (cancelled row) -> resumes.
        $cancelled = $this->seedSurvey($this->project, $cases[2], 'cancelled');
        // 3: never reserved -> resumes. 4: a rejected 4xx generation -> resumes once. 5: the paid bound is used up -> stays.
        $rejected = $this->seedSurvey($this->project, $cases[4], 'failed', 'survey_rejected', null, 1, ['rejected']);
        $spent = $this->seedSurvey($this->project, $cases[5], 'failed', 'survey_rejected', null, 2, ['rejected']);
        $rows = [[$paid, 'failed', 'survey_unreadable', 1], [$unknown, 'failed', 'survey_outcome_unknown', 1], [$cancelled, 'skipped', 'cancelled', 0],
            [null, 'skipped', 'cancelled', 0], [$rejected, 'failed', 'survey_rejected', 1], [$spent, 'failed', 'survey_rejected', 2]];
        foreach ($rows as $i => [$survey, $status, $error, $paidCount]) {
            IguideLabTourCapture::where('analysis_id', $analysis->id)->where('position', $i)->update(['status' => $status, 'error_code' => $error,
                'survey_id' => $survey?->id, 'paid_survey_requests' => $paidCount]);
        }
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'cancelled', 'cancelled_at' => now(), 'completed_at' => now(),
            'monitor_idle' => true, 'record_revision' => 5]);
        $this->assertRecordParts($analysis);

        $this->travel(3)->seconds();
        [$resumed, $queued] = $this->analyses()->create($this->project, $this->input($import, 'twilight'));
        $this->assertTrue($queued);
        $resumed = $resumed->fresh();
        $this->assertSame(['analysing', null, null, false, 6], [$resumed->status, $resumed->cancelled_at, $resumed->completed_at, $resumed->monitor_idle,
            $resumed->record_revision]);
        $captures = IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->get();
        $this->assertSame(['failed', 'failed', 'pending', 'pending', 'pending', 'failed'], $captures->pluck('status')->all());
        $this->assertSame(['survey_unreadable', 'survey_outcome_unknown', null, null, null, 'survey_rejected'], $captures->pluck('error_code')->all());
        $this->assertSame([true, true, true], $captures->slice(2, 3)->pluck('dispatch_allowed')->values()->all());
        $this->assertCount(2, $this->monitorPushes());
        $this->assertRecordParts($resumed);

        // The resumed captures are dispatched by the monitor without paying for rows that hold an answer or an unknown outcome.
        config(['iguide-lab-tour.worker_count' => 8]);
        $this->analyses()->tick($analysis->id);
        $this->assertSame(['failed', 'failed', 'surveying', 'surveying', 'surveying', 'failed'], array_values($this->captureStatuses($analysis)));
        $this->assertSame([1, 1], [IguideLabTourSurvey::where('case_id', $cases[0])->count(), IguideLabTourSurvey::where('case_id', $cases[1])->count()]);
        $this->assertSame(2, IguideLabTourSurvey::where('case_id', $cases[4])->max('generation'), 'a rejected generation is re-paid once');
        $this->assertSame('queued', $cancelled->fresh()->status, 'the cancelled row is relinked, not duplicated');
        $this->assertCount(3, $this->surveyPushes());
    }

    public function test_operator_limit_allows_n_and_ui_create_allows_the_rest(): void
    {
        $import = $this->tourImport($this->project, ['Kitchen', 'Hall', 'Bath', 'Front porch', 'Bedroom', 'Pool deck']);
        [$analysis, $queued] = $this->analyses()->create($this->project, $this->input($import, 'golden-hour'), 2);
        $this->assertTrue($queued);
        // Golden hour puts exterior photos first: positions 3 and 5.
        $this->assertSame([false, false, false, true, false, true], $this->allowed($analysis));

        config(['iguide-lab-tour.worker_count' => 8]);
        $this->analyses()->tick($analysis->id);
        $this->assertSame(['pending', 'pending', 'pending', 'surveying', 'pending', 'surveying'], array_values($this->captureStatuses($analysis)));

        [$again, $queued] = $this->analyses()->create($this->project, $this->input($import, 'twilight'));
        $this->assertSame([$analysis->id, true], [$again->id, $queued]);
        $this->assertSame([true, true, true, true, true, true], $this->allowed($analysis));
        $this->assertCount(2, $this->monitorPushes());
        // A UI create on a record that already allows everything changes nothing.
        [, $queued] = $this->analyses()->create($this->project, $this->input($import, 'twilight'));
        $this->assertFalse($queued);

        // Without the exterior priority (twilight creator), dispatch order is tour order.
        $plain = $this->tourImport($this->project, ['Front porch', 'Hall', 'Garden', 'Bath']);
        [$byOrder] = $this->analyses()->create($this->project, $this->input($plain, 'twilight'), 2);
        $this->assertSame([true, true, false, false], $this->allowed($byOrder));
    }

    public function test_second_operator_limit_releases_exactly_n_more(): void
    {
        config(['iguide-lab-tour.worker_count' => 8]);
        $import = $this->tourImport($this->project, 10);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import), 3);
        $this->analyses()->tick($analysis->id);
        $this->assertCount(3, $this->surveyPushes());

        [, $queued] = $this->analyses()->create($this->project, $this->input($import), 3);
        $this->assertTrue($queued);
        $this->analyses()->tick($analysis->id);
        $this->analyses()->tick($analysis->id);
        $this->assertCount(6, $this->surveyPushes());
        $this->assertCount(6, array_unique($this->surveyPushes()));
        $this->assertSame(4, IguideLabTourCapture::where('analysis_id', $analysis->id)->where('status', 'pending')->where('dispatch_allowed', false)->count());
    }

    public function test_operator_limit_on_a_cancelled_record_resumes_exactly_n(): void
    {
        config(['iguide-lab-tour.worker_count' => 8]);
        $import = $this->tourImport($this->project, 5);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import));
        $this->analyses()->cancel($analysis);
        $this->assertSame(array_fill(0, 5, 'skipped'), array_values($this->captureStatuses($analysis)));

        [, $queued] = $this->analyses()->create($this->project, $this->input($import), 2);
        $this->assertTrue($queued);
        $this->assertSame([true, true, false, false, false], $this->allowed($analysis));
        $this->assertSame(['queued', null], [$analysis->fresh()->status, $analysis->fresh()->cancelled_at]);
        $this->analyses()->tick($analysis->id);
        $this->assertCount(2, $this->surveyPushes());
        $this->assertSame(['surveying', 'surveying', 'pending', 'pending', 'pending'], array_values($this->captureStatuses($analysis)));
    }

    public function test_monitor_is_dispatched_after_commit_on_the_configured_queue(): void
    {
        $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        config(['iguide-lab-tour.queue_connection' => 'iguide-lab-tour', 'iguide-lab-tour.queue' => 'iguide-lab-tour']);
        $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));

        $pushes = $this->monitorPushes();
        $this->assertCount(2, $pushes);
        $this->assertSame([['studio', 'studio'], ['iguide-lab-tour', 'iguide-lab-tour']], array_map(fn ($j) => [$j->connection, $j->queue], $pushes));
        foreach ($pushes as $job) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $job->token, 'a new chain token');
            $this->assertNull($job->seq);
        }
        $this->assertSame([$this->queue->baselineLevel, $this->queue->baselineLevel], $this->queue->levels, 'pushed at the baseline level, after the commit');
        $this->assertSame([], $this->queue->pullViolations());
    }

    public function test_concurrent_creates_for_one_import_make_one_record(): void
    {
        $import = $this->tourImport($this->project, 3);
        $directory = sys_get_temp_dir().'/iguide-tour-create-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/isolated.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $tables = ['iguide_lab_projects', 'iguide_lab_imports', 'iguide_lab_cases', 'iguide_lab_tour_surveys', 'iguide_lab_tour_survey_attempts',
            'iguide_lab_tour_analyses', 'iguide_lab_tour_analysis_requests', 'iguide_lab_tour_captures'];
        $schema = DB::select("SELECT sql FROM sqlite_master WHERE tbl_name IN ('".implode("','", $tables)."') AND sql IS NOT NULL ORDER BY type DESC");
        foreach ($schema as $statement) $pdo->exec($statement->sql);
        foreach (['iguide_lab_projects' => $this->project->id, 'iguide_lab_imports' => $import->id] as $table => $id) $this->copyRows($pdo, $table, 'id', $id);
        $this->copyRows($pdo, 'iguide_lab_cases', 'project_id', $this->project->id);
        $workers = [];
        try {
            foreach (['a', 'b'] as $label) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/iguide-tour-analysis-create-worker.php'), $database, $this->project->id,
                    $import->id, (string) Str::uuid(), $directory.'/barrier', $label], base_path());
                $worker->setTimeout(60); $worker->start(); $workers[] = $worker;
            }
            $deadline = microtime(true) + 45;
            while (! is_file($directory.'/barrier.a') || ! is_file($directory.'/barrier.b')) {
                if (microtime(true) >= $deadline) $this->fail('Isolated create workers did not initialize.');
                foreach ($workers as $worker) if (! $worker->isRunning()) $this->fail($worker->getErrorOutput().$worker->getOutput());
                usleep(10000);
            }
            // Both child processes contend on this writer lock before either reads the shared record.
            $pdo->exec('BEGIN IMMEDIATE');
            file_put_contents($directory.'/barrier.go', 'go'); usleep(100000); $pdo->exec('COMMIT');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 202], $statuses);
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM iguide_lab_tour_analyses')->fetchColumn());
            $this->assertSame('2', (string) $pdo->query('SELECT COUNT(*) FROM iguide_lab_tour_analysis_requests')->fetchColumn());
            $this->assertSame('3', (string) $pdo->query('SELECT COUNT(*) FROM iguide_lab_tour_captures')->fetchColumn());
            $this->assertSame(0, IguideLabTourAnalysis::count(), 'the race uses only the separate temporary database');
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            $pdo = null;
            foreach (glob($directory.'/*') ?: [] as $path) unlink($path);
            rmdir($directory);
        }
    }

    private function copyRows(\PDO $pdo, string $table, string $column, string $value): void
    {
        foreach (DB::table($table)->where($column, $value)->get() as $row) {
            $row = (array) $row;
            $statement = $pdo->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
            $statement->execute(array_values($row));
        }
    }

    /** @return list<bool> */
    private function allowed(IguideLabTourAnalysis $analysis): array
    {
        return IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('dispatch_allowed')->map(fn ($v) => (bool) $v)->all();
    }

    private function assertReused(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected 409 tour_request_reused.');
        } catch (PublicApiException $e) {
            $this->assertSame([409, 'tour_request_reused', 'This request was already used with different settings. Reload and try again.'],
                [$e->getStatusCode(), $e->errorCode, $e->getMessage()]);
        }
    }

    private function assertTooMany(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Expected 422 tour_too_many_captures.');
        } catch (PublicApiException $e) {
            $this->assertSame([422, 'tour_too_many_captures', $message], [$e->getStatusCode(), $e->errorCode, $e->getMessage()]);
        }
    }

    private function assertCapped(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected 429 tour_survey_cap.');
        } catch (PublicApiException $e) {
            $this->assertSame([429, 'tour_survey_cap', 'The tour analysis limit for this lab has been reached. Try again later.'],
                [$e->getStatusCode(), $e->errorCode, $e->getMessage()]);
        }
    }
}
