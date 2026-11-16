<?php

namespace Tests\Feature;

use App\Jobs\MonitorIguideLabTourAnalysis;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TourAnalysis\SeedsTourAnalyses;
use Tests\TestCase;

/** Analysis monitor (spec 9.7-9.8, D4, D8): dispatch bound, status rule, chain claims, idle, cancel races. */
class IguideLabTourAnalysisMonitorTest extends TestCase
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

    public function test_dispatch_respects_worker_bound_and_exterior_priority(): void
    {
        $rooms = array_map(fn ($i) => 'Room '.$i, range(0, 79));
        [$rooms[10], $rooms[40], $rooms[70]] = ['Back yard', 'Deck', 'Garden'];
        $import = $this->tourImport($this->project, $rooms);
        $cases = $this->caseIds($import);
        foreach ([5, 6] as $cached) $this->seedSurvey($this->project, $cases[$cached]);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import, 'golden-hour'));
        $this->assertSame(78, IguideLabTourCapture::where('analysis_id', $analysis->id)->where('status', 'pending')->count(), 'cached captures are surveyed at create');

        $pushed = [];
        foreach ([10, 40, 70] as $expected) {
            $before = count($this->surveyPushes());
            $this->assertTrue($this->analyses()->tick($analysis->id));
            $new = array_slice($this->surveyPushes(), $before);
            $this->assertCount(1, $new, 'one worker: exactly one survey push per tick');
            $this->assertSame($cases[$expected], IguideLabTourSurvey::findOrFail($new[0])->case_id, 'exterior photos go first');
            $this->assertSame(1, $this->surveys->inFlightCount());
            // While the slot is busy, nothing else is pushed and no survey row is reserved for waiting captures.
            $this->assertTrue($this->analyses()->tick($analysis->id));
            $this->assertCount($before + 1, $this->surveyPushes());
            $this->assertSame(count($pushed) + 1 + 2, IguideLabTourSurvey::count());
            $pushed[] = $new[0];
            $this->assertSame(1, $this->completeDispatchedSurveys());
        }
        // Surveys completed elsewhere (another analysis) are reused without a slot while the next capture takes it.
        $this->seedSurvey($this->project, $cases[1]);
        $this->seedSurvey($this->project, $cases[2]);
        $before = count($this->surveyPushes());
        $this->analyses()->tick($analysis->id);
        $new = array_slice($this->surveyPushes(), $before);
        $this->assertCount(1, $new);
        $this->assertSame($cases[0], IguideLabTourSurvey::findOrFail($new[0])->case_id);
        $statuses = array_values($this->captureStatuses($analysis));
        $this->assertSame(['surveying', 'surveyed', 'surveyed', 'pending'], array_slice($statuses, 0, 4));
        $this->assertSame(7, count(array_filter($statuses, fn ($s) => $s === 'surveyed')));
        $this->assertSame([$this->queue->baselineLevel], array_values(array_unique($this->queue->levels)));
        $this->assertRecordParts($analysis);
        Http::assertNothingSent();
    }

    public function test_stale_queued_survey_is_redispatched_then_marked_lost(): void
    {
        $this->surveys->applySweepRules = true;
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 1)));
        $this->analyses()->tick($analysis->id);
        $survey = IguideLabTourSurvey::firstOrFail();
        $this->assertSame([$survey->id], $this->surveyPushes());

        // Stale and no job pending: re-dispatched (bounded by max_survey_dispatches).
        $this->travel(901)->seconds();
        $this->assertTrue($this->analyses()->tick($analysis->id));
        $this->assertCount(2, $this->surveyPushes());
        $this->assertSame(2, $survey->fresh()->dispatch_count);

        // A pending job (waiting behind other work on the shared worker) is neither re-dispatched nor marked lost.
        $jobId = DB::table('jobs')->insertGetId(['queue' => 'studio', 'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessIguideLabTourSurvey',
            'data' => ['command' => 'O:33:"App\\Jobs\\ProcessIguideLabTourSurvey":1:{s:8:"surveyId";s:36:"'.$survey->id.'";}']]),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        for ($i = 0; $i < 7; $i++) {
            $this->travel(901)->seconds();
            $this->analyses()->tick($analysis->id);
        }
        $this->assertCount(2, $this->surveyPushes());
        $this->assertSame(['queued', 2], [$survey->fresh()->status, $survey->fresh()->dispatch_count]);

        // Without the job: re-dispatched until max_survey_dispatches (5), then queue_unconfirmed.
        DB::table('jobs')->where('id', $jobId)->delete();
        for ($i = 0; $i < 3; $i++) {
            $this->travel(901)->seconds();
            $this->analyses()->tick($analysis->id);
        }
        $this->assertSame([5, 5], [count($this->surveyPushes()), $survey->fresh()->dispatch_count]);
        $this->travel(901)->seconds();
        $this->assertFalse($this->analyses()->tick($analysis->id));
        $this->assertSame(['needs_attention', 'queue_unconfirmed'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $capture = IguideLabTourCapture::firstOrFail();
        $this->assertSame(['failed', 'survey_queue_lost'], [$capture->status, $capture->error_code]);
        $this->assertSame('failed', $analysis->fresh()->status);
        $this->assertCount(5, $this->surveyPushes());
        $this->assertRecordParts($analysis);
    }

    public function test_stale_submitting_survey_goes_to_attention_without_http(): void
    {
        $this->surveys->applySweepRules = true;
        config(['iguide-lab-tour.worker_count' => 8]);
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $this->analyses()->tick($analysis->id);
        [$sent, $unsent] = IguideLabTourSurvey::orderBy('created_at')->get()->sortBy(fn ($s) => array_search($s->case_id,
            IguideLabTourCapture::orderBy('position')->pluck('case_id')->all()))->values()->all();
        // Claimed by a worker that died: one with an attempt in flight, one before its first attempt row.
        IguideLabTourSurvey::whereKey($sent->id)->toBase()->update(['status' => 'submitting', 'started_at' => now()]);
        IguideLabTourSurvey::whereKey($unsent->id)->toBase()->update(['status' => 'submitting', 'started_at' => now()]);
        DB::table('iguide_lab_tour_survey_attempts')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'survey_id' => $sent->id,
            'attempt_index' => 0, 'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => 'submitting',
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $pushes = count($this->surveyPushes());

        $this->travel(901)->seconds();
        $this->assertTrue($this->analyses()->tick($analysis->id));
        $this->assertSame(['needs_attention', 'submission_outcome_unconfirmed'], [$sent->fresh()->status, $sent->fresh()->error_code]);
        $this->assertSame(['failed', 'survey_outcome_unknown'], [IguideLabTourCapture::where('survey_id', $sent->id)->value('status'),
            IguideLabTourCapture::where('survey_id', $sent->id)->value('error_code')]);
        // Zero attempts: back to queued for free, and the same tick pushes it again (released marker).
        $this->assertSame('queued', $unsent->fresh()->status);
        $this->assertNotNull($unsent->fresh()->dispatched_at);
        $this->assertSame([$unsent->id], array_slice($this->surveyPushes(), $pushes));
        $this->assertSame('surveying', IguideLabTourCapture::where('survey_id', $unsent->id)->value('status'));
        $this->assertSame('analysing', $analysis->fresh()->status);
        $this->assertRecordParts($analysis);
        Http::assertNothingSent();
    }

    public function test_status_transitions_follow_status_issue(): void
    {
        config(['iguide-lab-tour.worker_count' => 8]);
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 3)));
        $this->assertSame(['queued', null], [$analysis->fresh()->status, $analysis->fresh()->completed_at]);
        $this->assertRecordParts($analysis);

        $this->analyses()->tick($analysis->id);
        $this->assertSame(['analysing', null], [$analysis->fresh()->status, $analysis->fresh()->completed_at]);
        $this->assertRecordParts($analysis);

        $surveys = IguideLabTourSurvey::all()->keyBy('case_id');
        $cases = IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('case_id')->all();
        $this->finish($surveys[$cases[0]], 'completed');
        $this->assertSame('analysing', $analysis->fresh()->status);
        $this->assertRecordParts($analysis);
        $this->finish($surveys[$cases[1]], 'failed', 'survey_rejected');
        $this->finish($surveys[$cases[2]], 'failed', 'survey_cap_reached');
        $fresh = $analysis->fresh();
        $this->assertSame(['ready', now()->toIso8601String()], [$fresh->status, $fresh->completed_at?->toIso8601String()]);
        $this->assertSame(['surveyed', 'failed', 'skipped'], array_values($this->captureStatuses($analysis)));
        $this->assertSame([null, 'survey_rejected', 'survey_cap_reached'], IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')
            ->pluck('error_code')->all());
        $this->assertRecordParts($analysis);
        $this->assertFalse($this->analyses()->tick($analysis->id), 'a ready analysis needs no monitor');

        // Nothing surveyed: failed, with completedAt.
        [$failed] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $this->analyses()->tick($failed->id);
        foreach (IguideLabTourSurvey::where('status', 'queued')->get() as $survey) $this->finish($survey, 'needs_attention', 'survey_outcome_unknown');
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertNotNull($failed->fresh()->completed_at);
        $this->assertRecordParts($failed);
    }

    public function test_idle_monitor_stops_and_create_restarts_it(): void
    {
        $import = $this->tourImport($this->project, 3);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import), 1);
        $this->runJob($this->monitorPushes()[0]);
        $this->assertCount(1, $this->surveyPushes());
        $this->assertCount(2, $this->monitorPushes(), 'a successor while work is in flight');
        $this->completeDispatchedSurveys();

        $this->travel(60)->seconds();
        $this->runJob($this->monitorPushes()[1]);
        $fresh = $analysis->fresh();
        $this->assertSame(['analysing', true, null, null], [$fresh->status, $fresh->monitor_idle, $fresh->monitor_heartbeat_at, $fresh->getRawOriginal('monitor_token')]);
        $this->assertCount(2, $this->monitorPushes(), 'an idle monitor schedules no successor');
        $this->assertFalse($this->analyses()->needsMonitor($fresh));
        $this->travel(301)->seconds();
        $this->analyses()->kickIfStale($fresh);
        $this->assertCount(2, $this->monitorPushes(), 'GET does not kick an idle monitor');

        [, $queued] = $this->analyses()->create($this->project, $this->input($import));
        $this->assertTrue($queued);
        $this->assertFalse($analysis->fresh()->monitor_idle);
        $this->assertCount(3, $this->monitorPushes());
        $this->assertNull($this->monitorPushes()[2]->seq, 'a new chain');
        $this->runJob($this->monitorPushes()[2]);
        $this->assertCount(2, $this->surveyPushes(), 'the restarted chain dispatches the released capture (one worker)');
        $this->assertCount(4, $this->monitorPushes());
    }

    public function test_duplicate_monitor_chain_exits_without_successor(): void
    {
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $first = $this->monitorPushes()[0];
        $this->runJob($first);
        $successor = $this->monitorPushes()[1];
        $this->assertSame([$first->token, 2], [$successor->token, $successor->seq]);
        $this->assertSame(60, (int) round(now()->diffInSeconds($successor->delay)));

        // A second new chain while this one is live exits at its claim.
        $this->runJob(new MonitorIguideLabTourAnalysis($analysis->id));
        $this->assertCount(2, $this->monitorPushes());

        $this->travel(60)->seconds();
        $duplicate = clone $successor;
        $this->runJob($successor);
        $this->assertCount(3, $this->monitorPushes());
        $sweeps = $this->surveys->sweeps;
        $this->runJob($duplicate);
        $this->assertCount(3, $this->monitorPushes(), 'the duplicate with the same (token, seq) exits');
        $this->assertSame($sweeps, $this->surveys->sweeps, 'and never ticks');
        $this->assertSame([$first->token, 2], [DB::table('iguide_lab_tour_analyses')->value('monitor_token'), $analysis->fresh()->monitor_seq]);
    }

    public function test_tick_that_throws_once_is_retried_and_schedules_exactly_one_successor(): void
    {
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $this->runJob($this->monitorPushes()[0]);
        $successor = $this->monitorPushes()[1];
        $throws = 1;
        $this->surveys->beforeSweep = function () use (&$throws) {
            if ($throws-- > 0) throw new \RuntimeException('Injected tick failure');
        };
        $this->travel(60)->seconds();
        try {
            $this->runJob($successor);
            $this->fail('The tick failure reaches the worker, which retries the job.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected tick failure', $e->getMessage());
        }
        $this->assertCount(2, $this->monitorPushes(), 'no successor from the failed attempt');

        $successor->withFakeQueueInteractions();
        $successor->job->attempts = 2;
        $this->runJob($successor);
        $this->assertCount(3, $this->monitorPushes());
        $this->assertSame([$successor->token, 3], [$this->monitorPushes()[2]->token, $this->monitorPushes()[2]->seq]);
        // A third run of the same job (not a retry) exits.
        $again = clone $successor;
        $again->job->attempts = 1;
        $this->runJob($again);
        $this->assertCount(3, $this->monitorPushes());
    }

    public function test_two_kicked_chains_with_40_second_latency_collapse_to_one(): void
    {
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $a = $this->monitorPushes()[0];
        $b = new MonitorIguideLabTourAnalysis($analysis->id); // a second kick (sweeper or GET) queued before A ran
        $this->runJob($a);
        $this->travel(40)->seconds();
        $this->runJob($b);
        $this->assertCount(2, $this->monitorPushes(), 'B exits: the heartbeat is fresh and the token is A\'s');
        $this->travel(20)->seconds();
        $this->runJob($this->monitorPushes()[1]);
        $this->travel(60)->seconds();
        $this->runJob($this->monitorPushes()[2]);
        $tokens = array_values(array_unique(array_map(fn ($job) => $job->token, $this->monitorPushes())));
        $this->assertSame([$a->token], $tokens);
        $this->assertSame([1, 2, 3, 4], array_map(fn ($job) => $job->seq ?? 1, $this->monitorPushes()));
        $this->assertSame($a->token, DB::table('iguide_lab_tour_analyses')->value('monitor_token'));
    }

    public function test_flag_off_tick_clears_the_heartbeat_and_the_sweeper_restarts_it(): void
    {
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $this->runJob($this->monitorPushes()[0]);
        $this->assertNotNull($analysis->fresh()->monitor_heartbeat_at);

        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $this->travel(60)->seconds();
        $sweeps = $this->surveys->sweeps;
        $this->runJob($this->monitorPushes()[1]);
        $fresh = $analysis->fresh();
        $this->assertSame([null, null, false], [$fresh->monitor_heartbeat_at, $fresh->getRawOriginal('monitor_token'), $fresh->monitor_idle]);
        $this->assertCount(2, $this->monitorPushes(), 'no successor while the flag is off');
        $this->assertSame($sweeps, $this->surveys->sweeps, 'a flag-off tick does no work');
        $this->assertTrue($this->analyses()->needsMonitor($fresh), 'still active, so the sweeper restarts it');

        // Flag back on: the sweeper's new chain (heartbeat null) proceeds at once.
        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        $restart = new MonitorIguideLabTourAnalysis($analysis->id);
        $this->runJob($restart);
        $this->assertCount(3, $this->monitorPushes());
        $this->assertSame([$restart->token, 2], [$this->monitorPushes()[2]->token, $this->monitorPushes()[2]->seq]);
    }

    public function test_kick_if_stale_needs_a_stale_heartbeat_no_pending_job_and_the_gate(): void
    {
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $this->assertCount(1, $this->monitorPushes());
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertCount(1, $this->monitorPushes(), 'created_at is fresh');

        $this->travel(301)->seconds();
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertCount(2, $this->monitorPushes());
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertCount(2, $this->monitorPushes(), 'at most one kick per gate period');

        $this->travel(61)->seconds();
        $jobId = DB::table('jobs')->insertGetId(['queue' => 'studio', 'payload' => json_encode(['displayName' => 'App\\Jobs\\MonitorIguideLabTourAnalysis',
            'data' => ['analysisId' => $analysis->id]]), 'attempts' => 0, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp,
            'created_at' => now()->timestamp]);
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertCount(2, $this->monitorPushes(), 'a reserved job for this monitor is waiting, not dead');
        DB::table('jobs')->where('id', $jobId)->delete();

        // A failing push never fails the GET.
        $this->queue->failNextPush(new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
        $this->queue->failNextPush(new \RuntimeException('queue down'));
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertCount(2, $this->monitorPushes());

        // The heartbeat decides once a chain has claimed.
        $this->travel(61)->seconds();
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['monitor_heartbeat_at' => now()->subSeconds(10)]);
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertCount(2, $this->monitorPushes());
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['monitor_heartbeat_at' => now()->subSeconds(301)]);
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertCount(3, $this->monitorPushes());
        $this->assertSame([], $this->queue->pullViolations());
    }

    public function test_cancel_between_a_ticks_read_and_its_write_stays_cancelled(): void
    {
        config(['iguide-lab-tour.worker_count' => 8]);
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 3)));
        $chain = $this->monitorPushes()[0];
        $this->assertSame(1, \App\Services\IguideLab\LabTourQueue::claimMonitor('iguide_lab_tour_analyses', $analysis->id, $chain->token, null, false));
        $once = true;
        $this->surveys->beforeSweep = function () use (&$once, $analysis) {
            if ($once) { $once = false; $this->analyses()->cancel($analysis); }
        };
        $revision = $analysis->fresh()->record_revision;
        $this->assertFalse($this->analyses()->tick($analysis->id));

        $fresh = $analysis->fresh();
        $this->assertSame(['cancelled', $revision + 1], [$fresh->status, $fresh->record_revision], 'only the cancel bumped');
        $this->assertSame(array_fill(0, 3, 'skipped'), array_values($this->captureStatuses($analysis)));
        $this->assertSame([], $this->surveys->reserved);
        $this->assertSame([], $this->surveyPushes());
        $this->assertSame(0, IguideLabTourSurvey::count());
        $this->assertNull($fresh->getRawOriginal('monitor_token'));
        $this->assertSame([$this->queue->baselineLevel + 1], $this->runs->levels, 'cancelWaiting runs inside the cancel transaction');
        $this->assertRecordParts($analysis);
    }

    public function test_revision_bumps_only_on_visible_change(): void
    {
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $this->assertSame(1, $analysis->fresh()->record_revision);
        $created = $analysis->fresh()->updated_at->toIso8601String();

        $this->travel(10)->seconds();
        $this->runJob($this->monitorPushes()[0]);          // first capture -> surveying
        $this->assertSame(2, $analysis->fresh()->record_revision);
        $touched = $analysis->fresh()->updated_at->toIso8601String();
        $this->assertNotSame($created, $touched);

        $this->travel(60)->seconds();
        $this->runJob($this->monitorPushes()[1]);          // slot busy: nothing visible changes
        $fresh = $analysis->fresh();
        $this->assertSame([2, $touched], [$fresh->record_revision, $fresh->updated_at->toIso8601String()]);
        $this->assertSame(now()->toIso8601String(), $fresh->monitor_heartbeat_at->toIso8601String(), 'the heartbeat moved without a bump');

        $this->travel(5)->seconds();
        $this->completeDispatchedSurveys();                // surveyed
        $this->assertSame(3, $analysis->fresh()->record_revision);
        $this->analyses()->tick($analysis->id);           // second capture -> surveying
        $this->assertSame(4, $analysis->fresh()->record_revision);
        $this->analyses()->tick($analysis->id);           // in flight: nothing
        $this->analyses()->kickIfStale($analysis->fresh());
        $this->assertSame(4, $analysis->fresh()->record_revision);
        $this->completeDispatchedSurveys();                // last capture surveyed and the analysis ready: one bump
        $this->assertSame([5, 'ready'], [$analysis->fresh()->record_revision, $analysis->fresh()->status]);
        $this->assertFalse($this->analyses()->tick($analysis->id));
        $this->assertSame(5, $analysis->fresh()->record_revision);
    }

    public function test_late_survey_result_does_not_overwrite_cancelled_capture(): void
    {
        config(['iguide-lab-tour.worker_count' => 8]);
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 2)));
        $this->analyses()->tick($analysis->id);
        [$claimed, $waiting] = IguideLabTourSurvey::all()->sortBy(fn ($s) => IguideLabTourCapture::where('survey_id', $s->id)->value('position'))->values()->all();
        IguideLabTourSurvey::whereKey($claimed->id)->toBase()->update(['status' => 'submitting', 'started_at' => now()]);
        DB::table('iguide_lab_tour_survey_attempts')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'survey_id' => $claimed->id,
            'attempt_index' => 0, 'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => 'submitting',
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->analyses()->cancel($analysis);
        $this->assertSame(['submitting', 'cancelled'], [$claimed->fresh()->status, $waiting->fresh()->status],
            'an unclaimed row is cancelled for free; a submitted one finishes and stays cached');
        $revision = $analysis->fresh()->record_revision;

        IguideLabTourSurvey::whereKey($claimed->id)->toBase()->update(['status' => 'completed', 'result' => json_encode($this->kitchenResult()), 'completed_at' => now()]);
        $this->analyses()->surveyFinished($claimed->fresh());
        $this->assertSame(['skipped', 'skipped'], array_values($this->captureStatuses($analysis)));
        $this->assertSame(['cancelled', 'cancelled'], IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('error_code')->all());
        $this->assertSame(['cancelled', $revision], [$analysis->fresh()->status, $analysis->fresh()->record_revision]);
        $this->assertFalse($this->analyses()->tick($analysis->id));
        $this->assertSame($revision, $analysis->fresh()->record_revision);
        // Cancel is idempotent.
        $this->analyses()->cancel($analysis);
        $this->assertSame($revision, $analysis->fresh()->record_revision);
        $this->assertRecordParts($analysis);

        // A later resume reuses the completed survey for free and re-queues the cancelled row.
        [, $queued] = $this->analyses()->create($this->project, $this->input(\App\Models\IguideLabImport::findOrFail($analysis->import_id)));
        $this->assertTrue($queued);
        $this->analyses()->tick($analysis->id);
        $this->assertSame(['surveyed', 'surveying'], array_values($this->captureStatuses($analysis)));
        $this->assertSame(2, IguideLabTourSurvey::count());
    }

    public function test_cancel_stops_analysing_work_and_cancels_returned_workflows_after_commit(): void
    {
        config(['iguide-lab-tour.worker_count' => 1]);
        $import = $this->tourImport($this->project, 3);
        $this->seedSurvey($this->project, $this->caseIds($import)[2]);
        [$analysis] = $this->analyses()->create($this->project, $this->input($import));
        $this->analyses()->tick($analysis->id);                    // capture 0 surveying, capture 1 waits for the slot
        $this->assertSame(['surveying', 'pending', 'surveyed'], array_values($this->captureStatuses($analysis)));
        $workflowId = (string) Str::uuid();
        DB::table('iguide_lab_workflows')->insert(['id' => $workflowId, 'project_id' => $this->project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('e', 64), 'status' => 'queued', 'policy' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->runs->result = ['changed' => true, 'cancelWorkflowIds' => [$workflowId, $workflowId]];
        $workflows = new class extends \App\Services\IguideLab\LabWorkflows {
            public array $levels = [];
            public function cancel(\App\Models\IguideLabWorkflow $workflow): \App\Models\IguideLabWorkflow
            {
                $this->levels[] = DB::transactionLevel();
                return parent::cancel($workflow);
            }
        };
        $this->app->instance(\App\Services\IguideLab\LabWorkflows::class, $workflows);
        $revision = $analysis->fresh()->record_revision;

        $this->travel(2)->seconds();
        $cancelled = $this->analyses()->cancel($analysis);
        $this->assertSame(['cancelled', $revision + 1, now()->toIso8601String()], [$cancelled->status, $cancelled->record_revision,
            $cancelled->cancelled_at->toIso8601String()]);
        $this->assertSame(['skipped', 'skipped', 'surveyed'], array_values($this->captureStatuses($analysis)), 'a surveyed capture stays surveyed');
        $this->assertSame(['cancelled', 'cancelled', null], IguideLabTourCapture::where('analysis_id', $analysis->id)->orderBy('position')->pluck('error_code')->all());
        $this->assertSame('cancelled', IguideLabTourSurvey::where('case_id', $this->caseIds($import)[0])->value('status'), 'a queued row without attempts is cancelled');
        $this->assertSame([$this->queue->baselineLevel], $workflows->levels, 'each returned workflow is cancelled once, after the commit');
        $this->assertSame('cancelled', DB::table('iguide_lab_workflows')->where('id', $workflowId)->value('status'));
        $this->assertRecordParts($analysis);

        // A ready analysis keeps its status; only its runs are cancelled (and only a change bumps the revision).
        $this->runs->result = ['changed' => false, 'cancelWorkflowIds' => []];
        $this->analyses()->cancel($cancelled);
        $this->assertSame($revision + 1, $analysis->fresh()->record_revision);
        [$ready] = $this->analyses()->create($this->project, $this->input($this->readyImport(2)));
        $this->runs->result = ['changed' => true, 'cancelWorkflowIds' => []];
        $this->assertSame('ready', $this->analyses()->cancel($ready)->status);
        $this->assertSame(2, $ready->fresh()->record_revision);
    }

    public function test_capture_dto_asserts_a_code_for_failed_and_skipped_captures(): void
    {
        [$analysis] = $this->analyses()->create($this->project, $this->input($this->tourImport($this->project, 1)));
        IguideLabTourCapture::where('analysis_id', $analysis->id)->update(['status' => 'failed', 'error_code' => null]);
        $this->expectException(\LogicException::class);
        $this->analyses()->capturesDto($this->tourView($analysis));
    }

    private function readyImport(int $captures): \App\Models\IguideLabImport
    {
        $import = $this->tourImport($this->project, $captures);
        foreach ($this->caseIds($import) as $caseId) $this->seedSurvey($this->project, $caseId);

        return $import;
    }

    private function runJob(MonitorIguideLabTourAnalysis $job): void
    {
        $job->handle($this->analyses());
    }

    private function finish(IguideLabTourSurvey $survey, string $status, ?string $error = null): void
    {
        IguideLabTourSurvey::whereKey($survey->id)->toBase()->update(['status' => $status, 'error_code' => $error, 'completed_at' => now(),
            'result' => $status === 'completed' ? json_encode($this->kitchenResult()) : null]);
        $this->analyses()->surveyFinished($survey->fresh());
    }
}
