<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabTourSurvey;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunTarget;
use App\Models\IguideLabTourSurvey;
use App\Models\IguideLabTourSurveyAttempt;
use App\Services\IguideLab\LabCredentials;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabTourConfig;
use App\Services\IguideLab\LabTourQueue;
use App\Services\IguideLab\LabTourSurveyProtocol;
use App\Services\IguideLab\LabTourSurveys;
use App\Services\IguideLab\LabTourSurveyStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\TourSurveyFixtures;
use Tests\TestCase;

/** Paid survey flow (spec 5.1-5.15, tests 13.3). Spend safety is checked with Http::fake request counts. */
class IguideLabTourSurveyTest extends TestCase
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

    public function test_paid_survey_sends_one_exact_request_and_records_usage(): void
    {
        ['captures' => [$capture]] = $this->seedTour(1, ['names' => ['Kitchen']]);
        $survey = $this->dispatchCapture($capture);
        $this->assertSame([1, 'queued', 1], [$survey->generation, $survey->status, $capture->fresh()->paid_survey_requests]);
        $this->fakeProvider('kitchen');

        app(LabTourSurveys::class)->process($survey);

        $survey->refresh();
        $this->assertSame('received', $survey->status);
        $disk = Storage::disk(LabService::DISK);
        $base = $this->base($survey);
        $payload = $disk->get($base.'/compiled/payload.json');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses' && $request->method() === 'POST'
            && $request->header('Authorization') === ['Bearer fixture-primary'] && $request->body() === $payload);
        $this->assertSame(['path' => $base.'/compiled/payload.json', 'sha256' => hash('sha256', $payload), 'bytes' => strlen($payload)], $survey->data['payload']);
        $this->assertSame(hash('sha256', $payload), $survey->data['providerBodySha256']);
        $this->assertSame('import_preview', $survey->data['preparedFrom']);
        $this->assertSame(['view_front', 'view_right', 'view_back', 'view_left'], $survey->data['views']['ids']);
        $wire = json_decode($payload, true);
        $this->assertSame(['roomName' => 'Kitchen', 'floorName' => 'Main Floor'], json_decode($wire['input'][0]['content'][0]['text'], true)['capture']);
        $this->assertSame($disk->get($base.'/source.png'), base64_decode(substr($wire['input'][0]['content'][2]['image_url'], 22)));

        $usage = DB::table('ai_provider_usage')->get();
        $this->assertCount(1, $usage);
        $this->assertSame(['tour_survey', 'gpt-6-astra', 'responses', 'success', 200, 'req_0', 9120, 1460],
            [$usage[0]->feature, $usage[0]->model, $usage[0]->endpoint, $usage[0]->status, (int) $usage[0]->http_status, $usage[0]->request_id,
                (int) $usage[0]->input_tokens, (int) $usage[0]->output_tokens]);
        $attempts = IguideLabTourSurveyAttempt::all();
        $this->assertCount(1, $attempts);
        $this->assertSame([0, 'lab-primary', 'gpt-6-astra', 'received', 200, 'req_0'], [$attempts[0]->attempt_index, $attempts[0]->credential_source,
            $attempts[0]->requested_model, $attempts[0]->status, $attempts[0]->http_status, $attempts[0]->provider_request_id]);
        $this->assertNotNull($attempts[0]->completed_at);
        $body = $this->response('kitchen');
        $this->assertSame($body, $disk->get($base.'/response-0.json'));
        $this->assertSame(['response' => ['path' => $base.'/response-0.json', 'sha256' => hash('sha256', $body), 'bytes' => strlen($body)],
            'providerResponseId' => 'resp_kitchen', 'model' => 'gpt-6-astra', 'status' => 'completed', 'incompleteReason' => null], $survey->data['providerReceipt']);
        $this->assertSame(['req_0', 9120], [$survey->provider_request_id, $survey->usage['input_tokens']]);
        $this->assertSame(['gpt-6-astra', 'medium', 16000, LabTourSurveyProtocol::RECIPE[1], 'iguide_tour_survey'], [$survey->protocol['model'],
            $survey->protocol['effort'], $survey->protocol['maxOutputTokens'], $survey->protocol['recipeSha256'], $survey->protocol['wireName']]);
        $this->assertNotNull($survey->started_at);
        $this->assertSame([], $this->analyses->finished, 'received is not terminal');
    }

    public function test_received_answer_is_parsed_on_the_next_tick(): void
    {
        ['analysis' => $analysis, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        $revision = $analysis->fresh()->record_revision;

        $first = $this->runJob(new ProcessIguideLabTourSurvey($survey->id));
        $this->assertSame('received', $survey->fresh()->status);
        $first->assertReleased(5);
        $this->assertSame('surveying', $capture->fresh()->status);

        $second = $this->runJob(new ProcessIguideLabTourSurvey($survey->id));
        $second->assertNotReleased();
        $survey->refresh();
        $this->assertSame(['completed', null, null, 'tour-survey-parser/1'], [$survey->status, $survey->error_code, $survey->parse_error, $survey->parser_version]);
        $raw = DB::table('iguide_lab_tour_surveys')->where('id', $survey->id)->value('result');
        $this->assertSame(hash('sha256', $raw), $survey->result_sha256);
        $this->assertSame(['Kitchen', 2, []], [$survey->result['space']['label'], count($survey->result['clutter']['surfaces']), $survey->result['warnings']]);
        $this->assertNotNull($survey->completed_at);
        $this->assertSame('surveyed', $capture->fresh()->status);
        $this->assertGreaterThan($revision, $analysis->fresh()->record_revision);
        $this->assertSame([[$survey->id, 'completed']], $this->analyses->finished);
        Http::assertSentCount(1);
    }

    public function test_quota_fallback_resends_identical_bytes_and_counts_two_attempts(): void
    {
        ['project' => $project, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $quota = ['error' => ['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']];
        $this->fakeProvider([json_encode($quota), 429], 'kitchen');
        $service = app(LabTourSurveys::class);
        $this->assertSame([0, 0], [$service->attemptsUsedByProject($project->id), $service->attemptsUsedToday()]);

        $service->process($survey);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $payload = Storage::disk(LabService::DISK)->get($this->base($survey).'/compiled/payload.json');
        $this->assertSame($payload, $recorded[0][0]->body());
        $this->assertSame($payload, $recorded[1][0]->body());
        $this->assertSame(['Bearer fixture-primary'], $recorded[0][0]->header('Authorization'));
        $this->assertSame(['Bearer fixture-fallback'], $recorded[1][0]->header('Authorization'));
        $this->assertSame([[0, 'lab-primary', 'quota_rejected', 429], [1, 'dashboard-fallback', 'received', 200]],
            IguideLabTourSurveyAttempt::orderBy('attempt_index')->get()->map(fn ($a) => [$a->attempt_index, $a->credential_source, $a->status, $a->http_status])->all());
        $this->assertSame(json_encode($quota), Storage::disk(LabService::DISK)->get($this->base($survey).'/response-0.json'));
        $this->assertSame($this->response('kitchen'), Storage::disk(LabService::DISK)->get($this->base($survey).'/response-1.json'));
        $this->assertSame([2, 2], [$service->attemptsUsedByProject($project->id), $service->attemptsUsedToday()]);
        $this->assertSame(238, LabTourConfig::surveyCallsPerProject() - $service->attemptsUsedByProject($project->id));
        $this->assertSame(['failed', 'success'], DB::table('ai_provider_usage')->orderBy('occurred_at')->orderBy('status')->pluck('status')->sort()->values()->all());
        $this->assertSame('received', $survey->fresh()->status);

        $service->process($survey->fresh());
        $this->assertSame('completed', $survey->fresh()->status, 'a fallback receipt with its explicit quota rejection verifies');
        Http::assertSentCount(2);
    }

    public function test_quota_fallback_is_refused_when_it_would_exceed_the_project_cap(): void
    {
        ['project' => $project, 'captures' => [$capture]] = $this->seedTour();
        $this->seedAttempts($project, 239, now()->subDays(3));
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider([json_encode(['error' => ['code' => 'insufficient_quota']]), 429], 'kitchen');

        app(LabTourSurveys::class)->process($survey);

        Http::assertSentCount(1);
        $this->assertSame(['failed', 'survey_cap_reached'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $this->assertSame(['quota_rejected'], IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->pluck('status')->all());
        $this->assertSame(240, app(LabTourSurveys::class)->attemptsUsedByProject($project->id));
        $this->assertSame(['skipped', 'survey_cap_reached'], [$capture->fresh()->status, $capture->fresh()->error_code]);
    }

    public function test_transport_failure_needs_attention_and_is_never_resent(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('fixture transport failure');
        });
        $service = app(LabTourSurveys::class);

        $service->process($survey);
        $service->process($survey->fresh());
        $this->runJob(new ProcessIguideLabTourSurvey($survey->id));

        $this->assertSame(1, $calls);
        $this->assertSame(['needs_attention', 'survey_outcome_unknown'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $attempt = IguideLabTourSurveyAttempt::sole();
        $this->assertSame('unknown', $attempt->status);
        $this->assertNotNull($attempt->completed_at);
        $this->assertSame(['unknown'], DB::table('ai_provider_usage')->pluck('status')->all());
        $this->assertSame(['failed', 'survey_outcome_unknown'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        $this->assertFalse(Storage::disk(LabService::DISK)->exists($this->base($survey).'/response-0.json'));
        // A resume never pays again for an unknown outcome.
        $this->assertFalse($service->retryable($capture->fresh()));
        $this->assertSame($survey->id, $this->dispatchCapture($capture)->id);
        $this->assertSame(1, IguideLabTourSurvey::count());
        $this->assertSame(1, $calls);
    }

    public function test_submitting_without_receipt_goes_to_attention_without_http(): void
    {
        ['captures' => [$a, $b, $c]] = $this->seedTour(3);
        Http::fake(['https://api.openai.com/*' => Http::response('', 500)]);
        $service = app(LabTourSurveys::class);
        $cases = [[$a, 'submitting', null], [$b, 'quota_rejected', 429], [$c, 'rejected', 400]];
        $surveys = [];
        foreach ($cases as [$capture, $attemptStatus, $http]) {
            $survey = $this->dispatchCapture($capture);
            IguideLabTourSurvey::whereKey($survey->id)->update(['status' => 'submitting', 'started_at' => now()]);
            IguideLabTourSurveyAttempt::create(['id' => (string) Str::uuid(), 'project_id' => $survey->project_id, 'survey_id' => $survey->id, 'attempt_index' => 0,
                'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => $attemptStatus, 'http_status' => $http, 'started_at' => now()]);
            $surveys[] = $survey;
            $service->process($survey);
        }

        Http::assertNothingSent();
        $this->assertSame([['needs_attention', 'submission_outcome_unconfirmed'], ['failed', 'survey_quota_exhausted'], ['failed', 'survey_rejected']],
            array_map(fn ($s) => [$s->fresh()->status, $s->fresh()->error_code], $surveys));
        $this->assertSame([['failed', 'survey_outcome_unknown'], ['failed', 'survey_quota_exhausted'], ['failed', 'survey_rejected']],
            array_map(fn ($capture) => [$capture->fresh()->status, $capture->fresh()->error_code], [$a, $b, $c]));
        $this->assertSame(3, IguideLabTourSurveyAttempt::count());
    }

    public function test_incomplete_answer_is_stored_and_marked(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('incomplete');
        $service = app(LabTourSurveys::class);

        $service->process($survey);
        $this->assertSame('received', $survey->fresh()->status);
        $this->assertSame(['incomplete', 'max_output_tokens'], [$survey->fresh()->data['providerReceipt']['status'], $survey->fresh()->data['providerReceipt']['incompleteReason']]);
        $service->process($survey->fresh());

        $survey->refresh();
        $this->assertSame(['failed', 'survey_incomplete', 'max_output_tokens', 'tour-survey-parser/1'], [$survey->status, $survey->error_code, $survey->parse_error, $survey->parser_version]);
        $this->assertNull($survey->result);
        $this->assertSame($this->response('incomplete'), Storage::disk(LabService::DISK)->get($this->base($survey).'/response-0.json'));
        $this->assertSame(['failed', 'survey_incomplete'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        // The paid answer is retained and never re-paid automatically (15.2 item 4).
        $this->assertFalse($service->retryable($capture->fresh()));
        Http::assertSentCount(1);
    }

    public function test_oversized_body_is_stored_before_the_size_check(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $envelope = json_decode($this->response('kitchen'), true);
        $envelope['metadata'] = ['padding' => str_repeat('x', 600 * 1024)];
        $body = json_encode($envelope);
        $this->fakeProvider($body);
        $service = app(LabTourSurveys::class);

        $service->process($survey);
        $this->assertSame(strlen($body), $survey->fresh()->data['providerReceipt']['response']['bytes']);
        $this->assertSame($body, Storage::disk(LabService::DISK)->get($this->base($survey).'/response-0.json'), 'stored before any size check');
        $service->process($survey->fresh());
        $this->assertSame(['failed', 'survey_too_large'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $this->assertSame(['failed', 'survey_too_large'], [$capture->fresh()->status, $capture->fresh()->error_code]);

        config(['iguide-lab-tour.survey.max_response_bytes' => 1024 * 1024]);
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => $survey->id])
            ->expectsOutput(json_encode(['version' => 'tour-survey-reparse/1', 'surveyId' => $survey->id, 'status' => 'completed', 'errorCode' => null,
                'parserVersion' => 'tour-survey-parser/1', 'capturesUpdated' => 1], JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        $this->assertSame(['completed', null, 'Kitchen'], [$survey->fresh()->status, $survey->fresh()->error_code, $survey->fresh()->result['space']['label']]);
        $this->assertSame([[$survey->id, false, 'completed']], $this->analyses->reparsed);
        Http::assertSentCount(1);
        $this->assertSame([1, 1], [IguideLabTourSurveyAttempt::count(), DB::table('ai_provider_usage')->count()]);
    }

    public function test_unreadable_answer_reparse_is_free(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('unknown-key');
        $service = app(LabTourSurveys::class);
        $service->process($survey);
        $service->process($survey->fresh());
        $this->assertSame(['failed', 'survey_unreadable', 'schema:light.skylight'], [$survey->fresh()->status, $survey->fresh()->error_code, $survey->fresh()->parse_error]);

        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => strtoupper($survey->id)])
            ->expectsOutput(json_encode(['version' => 'tour-survey-reparse/1', 'surveyId' => $survey->id, 'status' => 'failed', 'errorCode' => 'survey_unreadable',
                'parserVersion' => 'tour-survey-parser/1', 'capturesUpdated' => 0], JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame([1, 1], [IguideLabTourSurveyAttempt::count(), DB::table('ai_provider_usage')->count()]);
        $this->assertSame([], $this->analyses->reparsed);
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => 'not-a-uuid'])->assertFailed();
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => (string) Str::uuid()])->assertFailed();
    }

    public function test_unexpected_parse_error_fails_the_survey_without_resending(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);
        $service->process($survey);
        $this->assertSame('received', $survey->fresh()->status);
        Http::assertSentCount(1);
        Http::fake([]); // forget the one paid call: from here on nothing may be sent
        $this->bindParseDouble(new \RuntimeException('tour_survey_recipe_changed'));

        $job = $this->runJob(new ProcessIguideLabTourSurvey($survey->id));

        $job->assertNotReleased();
        $survey->refresh();
        $this->assertSame(['failed', 'survey_unreadable', 'internal', 'tour-survey-parser/1'], [$survey->status, $survey->error_code, $survey->parse_error,
            $survey->parser_version]);
        $this->assertNotNull($survey->completed_at);
        $this->assertSame(['failed', 'survey_unreadable'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        $this->assertSame(0, $service->inFlightCount(), 'the survey slot is freed');
        $this->travel(301)->seconds();
        $service->sweep();
        $this->queue->assertNotPushed(ProcessIguideLabTourSurvey::class);
        Http::assertNothingSent();

        // The stored answer is kept: without the double, the free re-parse completes it.
        $this->app->forgetInstance(LabTourSurveyProtocol::class);
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => $survey->id])
            ->expectsOutput(json_encode(['version' => 'tour-survey-reparse/1', 'surveyId' => $survey->id, 'status' => 'completed', 'errorCode' => null,
                'parserVersion' => 'tour-survey-parser/1', 'capturesUpdated' => 1], JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();
        $this->assertSame(['completed', 'Kitchen'], [$survey->fresh()->status, $survey->fresh()->result['space']['label']]);
        Http::assertNothingSent();
        $this->assertSame([1, 1], [IguideLabTourSurveyAttempt::count(), DB::table('ai_provider_usage')->count()]);
    }

    public function test_database_error_during_parse_keeps_the_survey_received(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);
        $service->process($survey);
        Http::fake([]); // forget the one paid call: from here on nothing may be sent
        $this->bindParseDouble(new QueryException('sqlite', 'select * from "iguide_lab_tour_survey_attempts"', [],
            new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked')));

        try {
            $this->runJob(new ProcessIguideLabTourSurvey($survey->id));
            $this->fail('A database error reaches the worker, which retries the job.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('database is locked', $error->getMessage());
        }

        $survey->refresh();
        $this->assertSame(['received', null, null, null], [$survey->status, $survey->error_code, $survey->parse_error, $survey->completed_at]);
        $this->assertSame('surveying', $capture->fresh()->status);
        $this->assertSame([], $this->analyses->finished);
        $this->assertSame(1, $service->inFlightCount());

        // The retry parses the stored answer for free.
        $this->app->forgetInstance(LabTourSurveyProtocol::class);
        $this->runJob(new ProcessIguideLabTourSurvey($survey->id))->assertNotReleased();
        $this->assertSame('completed', $survey->fresh()->status);
        $this->assertSame('surveyed', $capture->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_project_cap_counts_attempts_and_skips_without_http(): void
    {
        ['project' => $project, 'captures' => [$capture]] = $this->seedTour();
        $this->seedAttempts($project, 240, now()->subDays(3));
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');

        app(LabTourSurveys::class)->process($survey);

        Http::assertNothingSent();
        $this->assertSame(['failed', 'survey_cap_reached'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $this->assertSame(['skipped', 'survey_cap_reached'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        $this->assertSame(0, IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->count());
        $this->assertSame(0, DB::table('ai_provider_usage')->count());
    }

    public function test_daily_cap_is_lab_wide(): void
    {
        ['captures' => [$capture, $later]] = $this->seedTour(2);
        $this->seedAttempts($this->project(), 160, now()->subHour());
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');

        app(LabTourSurveys::class)->process($survey);

        Http::assertNothingSent();
        $this->assertSame(['failed', 'survey_daily_limit'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $this->assertSame(['skipped', 'survey_daily_limit'], [$capture->fresh()->status, $capture->fresh()->error_code]);

        // The window is a rolling 24 h: once the other project's attempts are older, the next survey is paid once.
        $this->travel(23)->hours();
        $this->travel(1)->seconds();
        $next = $this->dispatchCapture($later);
        app(LabTourSurveys::class)->process($next);
        $this->assertSame('received', $next->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_flag_off_job_claims_nothing(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        config(['iguide-lab-tour.tour_analysis_enabled' => false]);

        $job = $this->runJob(new ProcessIguideLabTourSurvey($survey->id));

        $job->assertNotReleased();
        Http::assertNothingSent();
        $this->assertSame('queued', $survey->fresh()->status);
        $this->assertSame(0, IguideLabTourSurveyAttempt::count());
        $this->assertFalse(Storage::disk(LabService::DISK)->exists($this->base($survey).'/compiled/payload.json'));
        $this->assertSame(0, $this->views->runs);
    }

    public function test_flag_off_job_releases_its_dispatch_marker(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->assertSame(1, $survey->dispatch_count);
        $updated = DB::table('iguide_lab_tour_surveys')->where('id', $survey->id)->value('updated_at');
        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $this->travel(5)->seconds();

        $this->runJob(new ProcessIguideLabTourSurvey($survey->id));

        $survey->refresh();
        $this->assertSame(['queued', null, 0], [$survey->status, $survey->dispatched_at, $survey->dispatch_count]);
        $this->assertSame($updated, DB::table('iguide_lab_tour_surveys')->where('id', $survey->id)->value('updated_at'), 'bookkeeping write');
        $this->assertSame(0, app(LabTourSurveys::class)->inFlightCount());
    }

    public function test_preview_mismatch_fails_free_and_recompute_fallback_works(): void
    {
        ['import' => $importId, 'captures' => [$a, $b, $c]] = $this->seedTour(3);
        $mismatch = $this->dispatchCapture($a);
        IguideLabTourSurvey::whereKey($mismatch->id)->update(['preview_sha256' => str_repeat('a', 64)]);
        $changedCase = $this->dispatchCapture($c);
        IguideLabTourSurvey::whereKey($changedCase->id)->update(['case_source_sha256' => str_repeat('c', 64)]);
        $recompute = $this->dispatchCapture($b);
        Storage::disk(LabService::DISK)->delete('iguide-lab/'.$recompute->project_id.'/imports/'.$importId.'/'.$b->case_id.'-preview.png');
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);

        $service->process($mismatch);
        $service->process($changedCase);
        Http::assertNothingSent();
        $this->assertSame([['failed', 'source_unavailable'], ['failed', 'source_unavailable']],
            [[$mismatch->fresh()->status, $mismatch->fresh()->error_code], [$changedCase->fresh()->status, $changedCase->fresh()->error_code]]);
        $this->assertSame(0, IguideLabTourSurveyAttempt::count());
        $this->assertSame(['failed', 'source_unavailable'], [$a->fresh()->status, $a->fresh()->error_code]);
        $this->assertTrue($service->retryable($a->fresh()), 'a free failure may be retried');

        $service->process($recompute);
        $this->assertSame(['received', 'recomputed'], [$recompute->fresh()->status, $recompute->fresh()->data['preparedFrom']]);
        $this->assertSame($recompute->preview_sha256, hash('sha256', Storage::disk(LabService::DISK)->get($this->base($recompute).'/source.png')));
        Http::assertSentCount(1);
    }

    public function test_view_failures_fail_the_survey_for_free(): void
    {
        ['captures' => [$a, $b]] = $this->seedTour(2);
        $broken = $this->dispatchCapture($a);
        $drifted = $this->dispatchCapture($b);
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);

        $this->views->status = 'failed';
        $service->process($broken);
        $this->views->status = 'completed';
        $this->views->adapterSha256 = str_repeat('0', 64);
        $service->process($drifted);

        $this->assertSame([['failed', 'source_unavailable'], ['failed', 'survey_preparation_failed']],
            [[$broken->fresh()->status, $broken->fresh()->error_code], [$drifted->fresh()->status, $drifted->fresh()->error_code]]);
        $this->assertSame([['failed', 'source_unavailable'], ['failed', 'survey_unavailable']],
            [[$a->fresh()->status, $a->fresh()->error_code], [$b->fresh()->status, $b->fresh()->error_code]]);
        $this->assertFalse(Storage::disk(LabService::DISK)->exists($this->base($drifted).'/compiled/payload.json'));
        $this->assertSame(0, IguideLabTourSurveyAttempt::count());
        Http::assertNothingSent();
        // Free failures may be retried once on resume.
        $this->assertTrue($service->retryable($a->fresh()));
        $this->assertTrue($service->retryable($b->fresh()));
    }

    public function test_payload_never_contains_private_input_or_urls(): void
    {
        $url = 'https://youriguide.com/abc-tour';
        ['captures' => [$capture]] = $this->seedTour(1, ['kind' => 'iguide_link', 'tourUrl' => $url, 'names' => ['Kitchen '.$url],
            'private' => ['tour' => ['pageUrl' => $url, 'publicUrl' => $url, 'accessToken' => 'fixture-access-token-value']]]);
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');

        app(LabTourSurveys::class)->process($survey);

        $payload = Storage::disk(LabService::DISK)->get($this->base($survey).'/compiled/payload.json');
        $context = Storage::disk(LabService::DISK)->get($this->base($survey).'/context.json');
        foreach ([$payload, $context] as $bytes) {
            foreach (['accessToken', 'fixture-access-token-value', 'youriguide', 'abc-tour'] as $secret) $this->assertStringNotContainsString($secret, $bytes);
        }
        $this->assertStringNotContainsString('http', preg_replace('#data:image/png;base64,[A-Za-z0-9+/=]+#', 'data:image/png;base64,', $payload));
        $content = json_decode($payload, true)['input'][0]['content'];
        $images = array_values(array_filter($content, fn ($part) => $part['type'] === 'input_image'));
        $this->assertCount(5, $images);
        foreach ($images as $image) $this->assertStringStartsWith('data:image/png;base64,', $image['image_url']);
        $this->assertSame(['roomName' => null, 'floorName' => 'Main Floor'], json_decode($content[0]['text'], true)['capture']);
        Http::assertSentCount(1);
    }

    public function test_cached_survey_is_reused_across_imports_without_http(): void
    {
        ['project' => $project, 'captures' => [$first], 'cases' => $cases] = $this->seedTour();
        $survey = $this->dispatchCapture($first);
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);
        $service->process($survey);
        $service->process($survey->fresh());
        $this->assertSame('completed', $survey->fresh()->status);

        // A re-import of the same tour (same uuid5 case, same preview) makes a new analysis and capture.
        ['captures' => [$second]] = $this->seedTour(1, ['project' => $project, 'cases' => $cases]);
        $this->assertSame(0, $second->fresh()->paid_survey_requests);
        $this->assertTrue($service->retryable($second));
        $reused = $this->dispatchCapture($second);

        $this->assertSame([$survey->id, 'completed'], [$reused->id, $reused->status]);
        $this->assertSame([$survey->id, 0], [$second->fresh()->survey_id, $second->fresh()->paid_survey_requests]);
        $this->assertSame(1, IguideLabTourSurvey::count());
        Http::assertSentCount(1);
    }

    public function test_worker_stopped_marks_attention_only_when_an_attempt_is_in_flight(): void
    {
        ['captures' => [$a, $b, $c, $d]] = $this->seedTour(4);
        Http::fake(['https://api.openai.com/*' => Http::response('', 500)]);
        $inFlight = $this->dispatchCapture($a);
        IguideLabTourSurvey::whereKey($inFlight->id)->update(['status' => 'submitting', 'started_at' => now()]);
        IguideLabTourSurveyAttempt::create(['id' => (string) Str::uuid(), 'project_id' => $inFlight->project_id, 'survey_id' => $inFlight->id, 'attempt_index' => 0,
            'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => 'submitting', 'started_at' => now()]);
        $claimed = $this->dispatchCapture($b);
        IguideLabTourSurvey::whereKey($claimed->id)->update(['status' => 'submitting', 'started_at' => now()]);
        $queued = $this->dispatchCapture($c);
        $received = $this->dispatchCapture($d);
        IguideLabTourSurvey::whereKey($received->id)->update(['status' => 'received']);
        $service = app(LabTourSurveys::class);

        foreach ([$inFlight, $claimed, $queued, $received] as $survey) $service->workerStopped($survey->fresh());

        $this->assertSame([['needs_attention', 'worker_stopped_unknown_outcome'], ['queued', null], ['failed', 'survey_preparation_failed'], ['received', null]],
            array_map(fn ($s) => [$s->fresh()->status, $s->fresh()->error_code], [$inFlight, $claimed, $queued, $received]));
        $this->assertSame(['failed', 'surveying', 'failed', 'surveying'], array_map(fn ($capture) => $capture->fresh()->status, [$a, $b, $c, $d]));
        Http::assertNothingSent();
    }

    public function test_failed_callback_does_nothing_while_another_job_holds_the_survey_lock(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        IguideLabTourSurvey::whereKey($survey->id)->update(['status' => 'submitting', 'started_at' => now()]);
        IguideLabTourSurveyAttempt::create(['id' => (string) Str::uuid(), 'project_id' => $survey->project_id, 'survey_id' => $survey->id, 'attempt_index' => 0,
            'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => 'submitting', 'started_at' => now()]);
        $live = new ProcessIguideLabTourSurvey($survey->id);
        $lock = Cache::store('database')->lock($live->lock()->getLockKey($live), 630);
        $this->assertTrue($lock->get());

        (new ProcessIguideLabTourSurvey($survey->id))->failed(new \RuntimeException('fixture worker stop'));
        $this->assertSame('submitting', $survey->fresh()->status);

        $lock->release();
        (new ProcessIguideLabTourSurvey($survey->id))->failed(new \RuntimeException('fixture worker stop'));
        $this->assertSame(['needs_attention', 'worker_stopped_unknown_outcome'], [$survey->fresh()->status, $survey->fresh()->error_code]);
    }

    public function test_duplicate_survey_jobs_send_one_request(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $duplicate = (new ProcessIguideLabTourSurvey($survey->id))->withFakeQueueInteractions();
        Http::fake(['https://api.openai.com/v1/responses' => function () use ($duplicate) {
            // The first job holds the survey lock during its paid call; the duplicate arrives now.
            $this->runJob($duplicate);

            return Http::response($this->response('kitchen'), 200, ['x-request-id' => 'req_live']);
        }]);

        $first = $this->runJob(new ProcessIguideLabTourSurvey($survey->id));

        $duplicate->assertReleased(15);
        $duplicate->assertNotFailed();
        $first->assertReleased(5);
        $this->assertSame('received', $survey->fresh()->status);
        $later = $this->runJob(new ProcessIguideLabTourSurvey($survey->id));
        $later->assertNotReleased();
        $this->assertSame('completed', $survey->fresh()->status);
        Http::assertSentCount(1);
        $this->assertSame(1, IguideLabTourSurveyAttempt::count());
    }

    public function test_storage_failure_after_a_200_needs_attention_and_resume_does_not_repay(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        app(LabTourSurveyStorage::class)->prepareSurvey($survey->project_id, $survey->id);
        mkdir(Storage::disk(LabService::DISK)->path($this->base($survey).'/response-0.json'), 0770);
        $this->fakeProvider('kitchen');
        $service = app(LabTourSurveys::class);

        $service->process($survey);

        $this->assertSame(['needs_attention', 'receipt_unconfirmed'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $this->assertSame(['failed', 'survey_outcome_unknown'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        $this->assertSame('submitting', IguideLabTourSurveyAttempt::sole()->status, 'the outcome stays unknown');
        // Resume: no new generation, no second payment.
        $this->assertFalse($service->retryable($capture->fresh()));
        $this->assertSame($survey->id, $this->dispatchCapture($capture)->id);
        $service->process($survey->fresh());
        $this->runJob(new ProcessIguideLabTourSurvey($survey->id));
        $this->assertSame(1, IguideLabTourSurvey::count());
        Http::assertSentCount(1);
    }

    public function test_body_is_stored_before_the_attempt_update(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        DB::statement("CREATE TRIGGER fixture_attempt_update_fails BEFORE UPDATE ON iguide_lab_tour_survey_attempts WHEN NEW.status = 'received'
            BEGIN SELECT RAISE(ABORT, 'fixture attempt update failure'); END");
        $this->fakeProvider('kitchen');

        app(LabTourSurveys::class)->process($survey);

        $this->assertSame($this->response('kitchen'), Storage::disk(LabService::DISK)->get($this->base($survey).'/response-0.json'));
        $this->assertSame(['needs_attention', 'receipt_unconfirmed'], [$survey->fresh()->status, $survey->fresh()->error_code]);
        $this->assertArrayNotHasKey('providerReceipt', $survey->fresh()->data);
        $this->assertSame('submitting', IguideLabTourSurveyAttempt::sole()->status);
        Http::assertSentCount(1);
    }

    public function test_claim_without_attempt_row_returns_to_queued_for_free(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->app->instance(LabCredentials::class, new class extends LabCredentials {
            public function primary(): string { throw new \RuntimeException('fixture key read failure'); }
        });
        $this->fakeProvider('kitchen');

        try {
            app(LabTourSurveys::class)->process($survey);
            $this->fail('The job must see the exception and retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture key read failure', $e->getMessage());
        }

        $this->assertSame('queued', $survey->fresh()->status);
        $this->assertSame(0, IguideLabTourSurveyAttempt::count());
        $this->assertSame(0, DB::table('ai_provider_usage')->count());
        Http::assertNothingSent();
        $this->assertSame([], $this->analyses->finished);
    }

    public function test_revoked_project_between_create_and_job_sends_nothing(): void
    {
        ['project' => $project, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $project->update(['revoked_at' => now()]);
        $this->fakeProvider('kitchen');

        $this->runJob(new ProcessIguideLabTourSurvey($survey->id));

        Http::assertNothingSent();
        $this->assertSame('cancelled', $survey->fresh()->status);
        $this->assertSame(['skipped', 'cancelled'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        $this->assertSame(0, IguideLabTourSurveyAttempt::count());
    }

    public function test_new_generation_needs_a_survey_without_paid_answer_or_unknown_outcome(): void
    {
        ['project' => $project, 'captures' => [$skipped, $unreadable, $rejected, $twice, $capSkip], 'cases' => $cases] = $this->seedTour(5);
        $rejection = [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_value']]), 400];
        $this->fakeProvider('unknown-key', $rejection, 'kitchen', $rejection, $rejection, 'kitchen');
        $service = app(LabTourSurveys::class);

        // (a) Captured while submitting, skipped by a cancel, the survey later needs attention: a resume pays nothing.
        $a = $this->dispatchCapture($skipped);
        IguideLabTourSurvey::whereKey($a->id)->update(['status' => 'submitting', 'started_at' => now()]);
        IguideLabTourSurveyAttempt::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'survey_id' => $a->id, 'attempt_index' => 0,
            'credential_source' => 'lab-primary', 'requested_model' => 'gpt-6-astra', 'status' => 'unknown', 'started_at' => now()]);
        IguideLabTourCapture::whereKey($skipped->id)->update(['status' => 'skipped', 'error_code' => 'cancelled']);
        $service->attention($a->fresh(), 'survey_outcome_unknown');
        $this->assertSame('needs_attention', $a->fresh()->status);
        IguideLabTourCapture::whereKey($skipped->id)->update(['status' => 'pending', 'error_code' => null]);
        $this->assertFalse($service->retryable($skipped->fresh()));
        $this->assertSame($a->id, $this->dispatchCapture($skipped)->id);

        // (b) A re-import of the same tour: the new capture has paid for nothing, but the survey rows decide.
        ['captures' => [$reimported]] = $this->seedTour(1, ['project' => $project, 'cases' => [$cases[0]]]);
        $this->assertFalse($service->retryable($reimported));
        $this->assertSame($a->id, $this->dispatchCapture($reimported)->id);

        // (c) An unreadable paid answer is recovered only by the free re-parse.
        $c = $this->dispatchCapture($unreadable);
        $service->process($c);
        $service->process($c->fresh());
        $this->assertSame('survey_unreadable', $c->fresh()->error_code);
        $this->assertFalse($service->retryable($unreadable->fresh()));
        $this->assertSame($c->id, $this->dispatchCapture($unreadable)->id);
        Http::assertSentCount(1);

        // (d) A rejected 4xx generation is paid again once on resume.
        $d = $this->dispatchCapture($rejected);
        $service->process($d);
        $this->assertSame(['failed', 'survey_rejected'], [$d->fresh()->status, $d->fresh()->error_code]);
        $this->assertTrue($service->retryable($rejected->fresh()));
        $d2 = $this->dispatchCapture($rejected);
        $this->assertNotSame($d->id, $d2->id);
        $this->assertSame([2, 2], [$d2->generation, $rejected->fresh()->paid_survey_requests]);
        $service->process($d2);
        $this->assertSame('received', $d2->fresh()->status);
        Http::assertSentCount(3);

        // ... but only up to max_paid_surveys_per_capture (2): a second rejection is final.
        $e = $this->dispatchCapture($twice);
        $service->process($e);
        $e2 = $this->dispatchCapture($twice);
        $service->process($e2);
        $this->assertSame([2, 'survey_rejected'], [$e2->generation, $e2->fresh()->error_code]);
        $this->assertFalse($service->retryable($twice->fresh()));
        $this->assertSame($e2->id, $this->dispatchCapture($twice)->id);
        Http::assertSentCount(5);

        // (e) A cap skip paid nothing, so a resume after the cap was raised pays once.
        config(['iguide-lab-tour.caps.survey_calls_per_project' => 5]);
        $f = $this->dispatchCapture($capSkip);
        $service->process($f);
        $this->assertSame(['failed', 'survey_cap_reached'], [$f->fresh()->status, $f->fresh()->error_code]);
        config(['iguide-lab-tour.caps.survey_calls_per_project' => 240]);
        $this->assertTrue($service->retryable($capSkip->fresh()));
        $f2 = $this->dispatchCapture($capSkip);
        $service->process($f2);
        $this->assertSame([2, 'received'], [$f2->generation, $f2->fresh()->status]);
        Http::assertSentCount(6);
    }

    public function test_cancelled_survey_relink_resets_dispatch_markers(): void
    {
        ['analysis' => $analysis, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        IguideLabTourSurvey::whereKey($survey->id)->toBase()->update(['dispatch_count' => 3]);
        // The analysis is cancelled before the job runs: the unclaimed row is cancelled for free.
        $analysis->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        IguideLabTourCapture::whereKey($capture->id)->update(['status' => 'skipped', 'error_code' => 'cancelled']);
        $this->fakeProvider('kitchen');
        app(LabTourSurveys::class)->process($survey);
        $this->assertSame('cancelled', $survey->fresh()->status);
        Http::assertNothingSent();

        // Resume relinks the row as a fresh reservation.
        $analysis->update(['status' => 'analysing', 'cancelled_at' => null]);
        IguideLabTourCapture::whereKey($capture->id)->update(['status' => 'pending', 'error_code' => null]);
        $relinked = DB::transaction(fn () => app(LabTourSurveys::class)->findOrReserve($capture->fresh(), $analysis->import_id, true));
        $this->assertSame([$survey->id, 'queued', null, 0, null], [$relinked->id, $relinked->status, $relinked->dispatched_at, $relinked->dispatch_count, $relinked->error_code]);
        $this->assertSame(0, app(LabTourSurveys::class)->inFlightCount(), 'a relinked row holds no slot until it is pushed');
        $this->assertSame(1, $capture->fresh()->paid_survey_requests);

        $pushed = $this->dispatchCapture($capture);
        LabTourQueue::push(new ProcessIguideLabTourSurvey($pushed->id));
        $this->assertSame([1, 1], [$pushed->dispatch_count, app(LabTourSurveys::class)->inFlightCount()]);
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, 1);
        $this->runJob($this->queue->pushed(ProcessIguideLabTourSurvey::class)->first());
        $this->assertSame('received', $survey->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_survey_job_uses_the_configured_connection_and_queue(): void
    {
        $id = (string) Str::uuid();
        LabTourQueue::push(new ProcessIguideLabTourSurvey($id));
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, fn ($job) => $job->connection === 'studio' && $job->queue === 'studio' && $job->surveyId === $id);

        config(['iguide-lab-tour.queue_connection' => 'iguide-lab-tour', 'iguide-lab-tour.queue' => 'iguide-lab-tour']);
        $other = (string) Str::uuid();
        LabTourQueue::push(new ProcessIguideLabTourSurvey($other));
        $this->queue->assertPushed(ProcessIguideLabTourSurvey::class, fn ($job) => $job->connection === 'iguide-lab-tour' && $job->queue === 'iguide-lab-tour'
            && $job->surveyId === $other);

        $job = new ProcessIguideLabTourSurvey($id);
        $this->assertSame([600, 0, 3, [10, 30, 60]], [$job->timeout, $job->tries, $job->maxExceptions, $job->backoff]);
        $lock = $job->middleware()[0];
        $this->assertSame([15, 630], [$lock->releaseAfter, $lock->expiresAfter]);
        $this->assertStringEndsWith(':iguide-tour-survey:'.$id, $lock->getLockKey($job));
        $this->assertSame($job->lock()->getLockKey($job), $lock->getLockKey($job));
    }

    public function test_reparse_of_completed_survey_with_runs_needs_force_and_is_refused(): void
    {
        ['analysis' => $analysis, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        app(LabTourSurveys::class)->process($survey);
        app(LabTourSurveys::class)->process($survey->fresh());
        $sha = $survey->fresh()->result_sha256;

        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => $survey->id])->expectsOutputToContain('--force')->assertFailed();
        $run = IguideLabTourRun::create(['id' => (string) Str::uuid(), 'project_id' => $analysis->project_id, 'analysis_id' => $analysis->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'preset' => 'golden-hour', 'selection_revision' => 0,
            'selection_sha256' => str_repeat('d', 64), 'estimate' => ['roomsSelected' => 1], 'chunk_size' => 8]);
        IguideLabTourRunTarget::create(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $analysis->project_id, 'case_id' => $capture->case_id,
            'position' => 0, 'candidate_ids' => ['g0'], 'state' => 'waiting', 'reasons' => []]);
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => $survey->id, '--force' => true])->expectsOutputToContain('run')->assertFailed();
        $this->assertSame([], $this->analyses->reparsed);

        $run->delete();
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => $survey->id, '--force' => true])->assertSuccessful();
        $this->assertSame([[$survey->id, true, 'completed']], $this->analyses->reparsed);
        $this->assertSame($sha, $survey->fresh()->result_sha256);
        Http::assertSentCount(1);
    }

    public function test_reparse_turns_failed_captures_surveyed_and_a_failed_analysis_ready(): void
    {
        ['analysis' => $analysis, 'captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $envelope = json_decode($this->response('kitchen'), true);
        $envelope['metadata'] = ['padding' => str_repeat('y', 560 * 1024)];
        $this->fakeProvider(json_encode($envelope));
        app(LabTourSurveys::class)->process($survey);
        app(LabTourSurveys::class)->process($survey->fresh());
        $this->assertSame(['failed', 'survey_too_large'], [$capture->fresh()->status, $capture->fresh()->error_code]);
        $completedAt = $survey->fresh()->completed_at;
        $revision = $analysis->fresh()->record_revision;

        config(['iguide-lab-tour.survey.max_response_bytes' => 1024 * 1024]);
        $result = app(LabTourSurveys::class)->reparse($survey->fresh(), false);

        $this->assertSame(['completed', null, null, 'tour-survey-parser/1'], [$result->status, $result->error_code, $result->parse_error, $result->parser_version]);
        $this->assertEquals($completedAt, $result->completed_at);
        // WP2's part ends with exactly one surveyReparsed call; the capture, analysis and revision moves are WP3's (5.13).
        $this->assertSame([[$survey->id, false, 'completed']], $this->analyses->reparsed);
        Http::assertSentCount(1);
        $this->assertSame([1, 1], [IguideLabTourSurveyAttempt::count(), DB::table('ai_provider_usage')->count()]);
        $this->assertSame($revision, $analysis->fresh()->record_revision, 'WP2 itself writes no capture or analysis row');
    }

    public function test_forced_reparse_that_fails_changes_nothing(): void
    {
        ['captures' => [$capture]] = $this->seedTour();
        $survey = $this->dispatchCapture($capture);
        $this->fakeProvider('kitchen');
        app(LabTourSurveys::class)->process($survey);
        app(LabTourSurveys::class)->process($survey->fresh());
        $before = (array) DB::table('iguide_lab_tour_surveys')->where('id', $survey->id)->first();
        $captureBefore = (array) DB::table('iguide_lab_tour_captures')->where('id', $capture->id)->first();
        $this->travel(5)->seconds();

        config(['iguide-lab-tour.survey.max_output_text_bytes' => 1024]);
        $this->artisan('iguide-lab:tour-survey-reparse', ['survey' => $survey->id, '--force' => true])
            ->expectsOutputToContain('survey_unreadable')->assertFailed();

        $this->assertSame($before, (array) DB::table('iguide_lab_tour_surveys')->where('id', $survey->id)->first());
        $this->assertSame($captureBefore, (array) DB::table('iguide_lab_tour_captures')->where('id', $capture->id)->first());
        $this->assertSame([], $this->analyses->reparsed);
        Http::assertSentCount(1);
    }

    public function test_a_payload_reserved_while_queued_keeps_its_model_after_the_survey_model_changes(): void
    {
        ['captures' => [$capture, $next]] = $this->seedTour(2, ['names' => ['Kitchen', 'Kitchen']]);
        $survey = $this->dispatchCapture($capture);
        $service = app(LabTourSurveys::class);
        // Prepared (payload and protocol saved) but never claimed, as when a submitting row resolves back to queued.
        $this->assertNotNull(\Closure::bind(fn ($row) => $this->prepare($row), $service, LabTourSurveys::class)($survey->fresh()));
        $this->assertSame(['queued', 'gpt-6-astra'], [$survey->fresh()->status, $survey->fresh()->protocol['model']]);
        $reserved = Storage::disk(LabService::DISK)->get($this->base($survey).'/compiled/payload.json');

        config(['iguide-lab-tour.survey.model' => 'gpt-6.1-terra']);
        $this->fakeProvider('kitchen', 'kitchen');
        $service->process($survey->fresh());
        $service->process($survey->fresh());
        $this->assertSame(['completed', 'gpt-6-astra'], [$survey->fresh()->status, $survey->fresh()->protocol['model']]);
        $this->assertSame(['gpt-6-astra'], IguideLabTourSurveyAttempt::where('survey_id', $survey->id)->pluck('requested_model')->all());
        Http::assertSent(fn (Request $request) => $request->body() === $reserved);

        // A survey prepared after the change uses the new model.
        $fresh = $this->dispatchCapture($next);
        $service->process($fresh);
        $this->assertSame('gpt-6.1-terra', $fresh->fresh()->protocol['model']);
        $this->assertSame('gpt-6.1-terra', json_decode(Storage::disk(LabService::DISK)->get($this->base($fresh).'/compiled/payload.json'), true)['model']);
        Http::assertSentCount(2);
    }

    /** Parse seam:LabTourSurveyProtocol resolved from the container is replaced by a double whose parse() throws $error. */
    private function bindParseDouble(\Throwable $error): void
    {
        $this->app->instance(LabTourSurveyProtocol::class, new class($error) {
            private static \Throwable $error;

            public function __construct(\Throwable $error)
            {
                self::$error = $error;
            }

            public static function parse(string $body, string $model, string $providerResponseId): array
            {
                throw self::$error;
            }
        });
    }
}
