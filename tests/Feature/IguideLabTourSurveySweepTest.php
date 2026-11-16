<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Services\IguideLab\LabTourSurveys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\TourSurveyFixtures;
use Tests\TestCase;

/** Lab-wide survey sweep (spec 9.9, tests 13.3): over all survey rows, independent of analysis status. */
class IguideLabTourSurveySweepTest extends TestCase
{
    use RefreshDatabase, TourSurveyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTourSurveys();
    }

    protected function tearDown(): void
    {
        $this->tearDownTourSurveys();
        parent::tearDown();
    }

    public function test_received_row_of_a_cancelled_analysis_is_parsed_by_another_projects_tick(): void
    {
        ['analysis' => $analysis, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);
        $service->process($survey);
        $this->assertSame('received', $survey->fresh()->status);
        // The analysis is cancelled and the received job is gone (no jobs row).
        $analysis->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        IguideLabTourCapture::whereKey($capture->id)->update(['status' => 'skipped', 'error_code' => 'cancelled']);
        ['analysis' => $other] = $this->seedTour();
        $this->assertNotSame($analysis->project_id, $other->project_id);
        $this->assertSame(1, $service->inFlightCount());

        $this->travel(301)->seconds();
        // The lab-wide sweep runs from every analysis tick (9.7 step 2), here the other project's.
        $this->assertTrue($service->sweep());

        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, fn ($job) => $job->surveyId === $survey->id);
        $this->assertSame(now()->toDateTimeString(), $survey->fresh()->dispatched_at->toDateTimeString());
        $this->runJob($this->queue->pushed(ProcessIguideLabTourSurvey::class)->first());
        $this->assertSame('completed', $survey->fresh()->status);
        $this->assertSame(0, $service->inFlightCount(), 'the slot is freed');
        $this->assertSame(['skipped', 'cancelled'], [$capture->fresh()->status, $capture->fresh()->error_code], 'a cancelled capture is never touched');
        Http::assertSentCount(1);
    }

    public function test_received_row_is_never_marked_lost(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);
        $service->process($survey);
        $updated = DB::table('iguide_lab_tour_surveys')->where('id', $survey->id)->value('updated_at');

        for ($i = 0; $i < 7; $i++) {
            $this->travel(301)->seconds();
            $this->assertTrue($service->sweep());
        }

        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, 7);
        $survey->refresh();
        $this->assertSame(['received', null, 1], [$survey->status, $survey->error_code, $survey->dispatch_count], 'never counted toward max_survey_dispatches');
        $this->assertSame($updated, DB::table('iguide_lab_tour_surveys')->where('id', $survey->id)->value('updated_at'), 'dispatch markers are bookkeeping');

        // A pending job means the row is waiting, not lost.
        $this->pendingJob($survey->id);
        $this->travel(301)->seconds();
        $this->assertFalse($service->sweep());
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, 7);
        // Once the job is gone it is pushed again, and a fresh marker is not stale.
        DB::table('jobs')->delete();
        $this->assertTrue($service->sweep());
        $this->assertFalse($service->sweep());
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, 8);
        Http::assertSentCount(1);
    }

    public function test_queued_row_with_a_pending_job_is_neither_redispatched_nor_lost(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->pendingJob($survey->id);
        $service = app(LabTourSurveys::class);

        for ($i = 0; $i < 20; $i++) {
            $this->travel(901)->seconds();
            $this->assertFalse($service->sweep());
        }

        $survey->refresh();
        $this->assertSame(['queued', null, 1], [$survey->status, $survey->error_code, $survey->dispatch_count]);
        $this->queue->assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_queued_row_without_a_job_is_redispatched_then_marked_lost(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $service = app(LabTourSurveys::class);

        $this->travel(899)->seconds();
        $this->assertFalse($service->sweep(), 'not stale yet');
        for ($i = 2; $i <= 5; $i++) {
            $this->travel(901)->seconds();
            $this->assertTrue($service->sweep());
            $this->assertSame(['queued', $i], [$survey->fresh()->status, $survey->fresh()->dispatch_count]);
        }
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, 4);
        $this->travel(901)->seconds();
        $this->assertTrue($service->sweep());

        $this->assertSame(['needs_attention', 'queue_unconfirmed'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $this->assertSame(['failed', 'survey_queue_lost'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, 4);
        $this->assertSame(0, IguideLabTourSurveyAttempt::count());
        Http::assertNothingSent();
    }

    public function test_stale_submitting_without_attempts_returns_to_queued(): void
    {
        ['analysis' => $analysis, 'captures' => [$free, $unknown, $quota, $fresh, $waiting]] = $this->seedTour(5);
        $rows = [];
        foreach ([$free, $unknown, $quota, $fresh, $waiting] as $capture) {
            $survey = $this->dispatchCapture($capture);
            IguideLabTourSurvey::whereKey($survey->id)->update(['status' => 'submitting', 'started_at' => now()]);
            $rows[] = $survey;
        }
        foreach ([[$rows[1], 'unknown', null], [$rows[2], 'quota_rejected', 429]] as [$survey, $status, $http]) {
            IguideLabTourSurveyAttempt::create(['id' => (string) Str::uuid(), 'project_id' => $analysis->project_id, 'survey_id' => $survey->id, 'attempt_index' => 0,
                'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => $status, 'http_status' => $http, 'started_at' => now()]);
        }
        $this->pendingJob($rows[4]->id);
        $this->travel(901)->seconds();
        IguideLabTourSurvey::whereKey($rows[3]->id)->update(['started_at' => now()->subSeconds(100)]);
        Http::fake(['https://api.openai.com/*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $this->assertTrue(app(LabTourSurveys::class)->sweep());

        $this->assertSame([['queued', null], ['needs_attention', 'submission_outcome_unconfirmed'], ['failed', 'survey_quota_exhausted'], ['submitting', null], ['submitting', null]],
            array_map(fn ($s) => [$s->fresh()->status, $s->fresh()->error_code], $rows));
        // The free row is queued again and, because only its surveying capture links it, pushed in the same sweep.
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, 1);
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, fn ($job) => $job->surveyId === $rows[0]->id);
        $this->assertSame([now()->toDateTimeString(), 2], [$rows[0]->fresh()->dispatched_at->toDateTimeString(), $rows[0]->fresh()->dispatch_count]);
        $this->assertSame(['surveying', 'failed', 'failed'], [$free->fresh()->status, $unknown->fresh()->status, $quota->fresh()->status]);
        $this->assertSame(['survey_outcome_unknown', 'survey_quota_exhausted'], [$unknown->fresh()->error_code, $quota->fresh()->error_code]);
        $this->assertSame(0, IguideLabTourSurveyAttempt::where('survey_id', $rows[0]->id)->count());
        Http::assertNothingSent();
    }

    public function test_unlinked_stale_queued_row_is_cancelled_for_free(): void
    {
        ['analysis' => $analysis, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $analysis->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->travel(901)->seconds();

        $this->assertTrue(app(LabTourSurveys::class)->sweep());

        $this->assertSame('cancelled', $survey->fresh()->status);
        $this->assertSame(0, app(LabTourSurveys::class)->inFlightCount());
        $this->queue->assertNothingPushed();
        $this->assertSame([[$survey->id, 'cancelled']], $this->analyses->finished);
    }

    public function test_flag_off_reset_row_is_pushed_again_by_the_sweep(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $this->runJob(new ProcessIguideLabTourSurvey($survey->id));
        $this->assertSame([null, 0], [$survey->fresh()->dispatched_at, $survey->fresh()->dispatch_count]);
        config(['iguide-lab-tour.tour_analysis_enabled' => true]);

        $this->assertTrue(app(LabTourSurveys::class)->sweep());

        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, fn ($job) => $job->surveyId === $survey->id);
        $this->assertSame(1, $survey->fresh()->dispatch_count);
        $this->assertFalse(app(LabTourSurveys::class)->sweep(), 'in flight again');
    }
}
