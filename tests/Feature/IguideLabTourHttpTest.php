<?php

namespace Tests\Feature;

use App\Console\Commands\SweepIguideLabTourMonitors;
use App\Http\Middleware\AuthorizeIguideLab;
use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourCapture;
use App\Models\IguideLabTourRun;
use App\Models\IguideLabTourRunChunk;
use App\Models\IguideLabTourRunTarget;
use App\Services\IguideLab\LabTourCapabilities;
use App\Services\IguideLab\LabTourErrors;
use App\Services\IguideLab\LabTourRecords;
use App\Services\IguideLab\LabWorkflowPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsTourContract;
use Tests\Support\RecordingQueueFake;
use Tests\Support\TourLayerDoubles;
use Tests\Support\TourPolicySpy;
use Tests\TestCase;

/**
 * HTTP layer of the tour analysis layer (spec 10, 13.6): routes, guards, capabilities, the record and progress
 * assembly from one read snapshot, and the scheduled monitor sweeper (9.9). WP2-WP4 services are bound as
 * TourLayerDoubles (spec 14), so these tests cover the WP5 code only; the end-to-end tests come with integration.
 */
class IguideLabTourHttpTest extends TestCase
{
    use AssertsTourContract;
    use RefreshDatabase;

    /** tourContract.ts TOUR_CONFLICT_MESSAGES as amended (spec 12.1, requestReused included). */
    private const CONTRACT_MESSAGES = [
        'staleSelection' => 'The selection changed since it was loaded. Reload and try again.',
        'selectionMismatch' => 'The selection on screen does not match the saved selection. Reload and try again.',
        'duplicateActiveRun' => 'A run for this preset is already in progress for this tour.',
        'runsPaused' => 'Runs are paused right now. Your selection is saved.',
        'analysisNotReady' => 'The tour is still being analysed.',
        'nothingFits' => 'No rooms fit within the current limits.',
        'notHeld' => 'Only a room that was kept original can be accepted.',
        'importNotReady' => 'The import has not finished yet.',
        'surveyCapReached' => 'The tour analysis limit for this lab has been reached. Try again later.',
        'runLimitReached' => 'This tour has reached its limit of runs.',
        'presetNotRunnable' => 'This preset cannot be run on this tour yet.',
        'requestReused' => 'This request was already used with different settings. Reload and try again.',
    ];
    private const BRIDGE_SAFE_MESSAGE = '#\A[\w\s.,:;\'"()!?%/+-]+\z#D';
    private const SHA = 'cdcbc8989a2a851648e51d6aca88b5fe93f607b572c004d85e9bb8d703bd46e2';

    private TourLayerDoubles $doubles;
    private TourPolicySpy $policy;
    private RecordingQueueFake $queue;
    private IguideLabProject $project;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        TourLayerDoubles::aliasMissingMonitorJobs();
        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        $this->queue = RecordingQueueFake::install($this);
        $this->doubles = TourLayerDoubles::install();
        $this->policy = new TourPolicySpy(DB::transactionLevel());
        $this->app->instance(LabWorkflowPolicy::class, $this->policy);
        [$this->project, $this->token] = $this->project();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_flag_off_index_is_disabled_and_other_routes_404(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed', 'pending']);
        config(['iguide-lab-tour.tour_analysis_enabled' => false, 'iguide-lab-tour.tour_runs_paused' => true]);

        $index = $this->api('GET', 'tour-analyses')->assertOk();
        $this->assertTourResponse($index, 'GET', 'tour-analyses');
        $data = $index->json('data');
        $this->assertFalse($data['capabilities']['enabled']);
        $this->assertTrue($data['capabilities']['runsPaused']);
        $this->assertSame([], $data['analyses']);
        $this->assertSame(array_map(fn ($p) => ['preset' => $p, 'ready' => false, 'reason' => 'tour_disabled'], ['golden-hour', 'twilight', 'grass', 'declutter']),
            $data['capabilities']['presets']);
        $this->assertSame(0, $this->policy->capabilitiesCalls + $this->policy->snapshotCalls, 'LabWorkflowPolicy is not called while the tour layer is off.');

        $id = $analysis->id;
        foreach ($this->routes($id) as [$method, $path, $body]) {
            if ($path === 'tour-analyses' && $method === 'GET') continue;
            $response = $this->api($method, $path, $body)->assertNotFound();
            $this->assertSame('The requested item was not found.', $response->json('message'), "$method $path");
            $this->assertTourResponse($response, $method, $path);
        }
        $this->assertSame([], array_values(array_filter($this->doubles->calls, fn ($call) => ! in_array($call[0], ['attemptsUsedByProject', 'attemptsUsedToday'], true))),
            'No tour service runs while the flag is off.');
        $this->queue->assertNothingPushed();
    }

    public function test_routes_need_the_lab_token_and_use_lab_throttles(): void
    {
        $analysis = $this->analysis($this->project, ['pending']);
        foreach ($this->routes($analysis->id) as [$method, $path, $body]) {
            $this->json($method, '/api/iguide-lab/'.$path, $body ?? [])->assertUnauthorized();
            $this->withToken(str_repeat('a', 64))->json($method, '/api/iguide-lab/'.$path, $body ?? [])->assertUnauthorized();
        }
        $expired = $this->project();
        IguideLabProject::whereKey($expired[0]->id)->update(['expires_at' => now()->subMinute()]);
        $this->withToken($expired[1])->getJson('/api/iguide-lab/tour-analyses')->assertUnauthorized();

        $tour = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/iguide-lab/tour-analyses'));
        $editor = ['GET' => Route::getRoutes()->match(\Illuminate\Http\Request::create('/api/iguide-lab/workflows', 'GET')),
            'POST' => Route::getRoutes()->match(\Illuminate\Http\Request::create('/api/iguide-lab/workflows', 'POST'))];
        $expected = ['GET api/iguide-lab/tour-analyses' => ['index', 'read'], 'POST api/iguide-lab/tour-analyses' => ['store', 'write'],
            'GET api/iguide-lab/tour-analyses/{id}' => ['show', 'read'], 'GET api/iguide-lab/tour-analyses/{id}/progress' => ['progress', 'read'],
            'POST api/iguide-lab/tour-analyses/{id}/selection' => ['selection', 'write'], 'POST api/iguide-lab/tour-analyses/{id}/runs' => ['runs', 'write'],
            'POST api/iguide-lab/tour-analyses/{id}/accept' => ['accept', 'write'], 'POST api/iguide-lab/tour-analyses/{id}/cancel' => ['cancel', 'write']];
        $seen = [];
        foreach ($tour as $route) {
            $method = in_array('POST', $route->methods(), true) ? 'POST' : 'GET';
            $key = $method.' '.$route->uri();
            $this->assertArrayHasKey($key, $expected);
            [$action, $limiter] = $expected[$key];
            $seen[] = $key;
            $this->assertSame('App\Http\Controllers\API\IguideLabTourController@'.$action, $route->getActionName());
            // Exactly the middleware of the pinned editor routes (routes/iguide-lab.php:11-15) and nothing inherited from another group.
            $this->assertSame(['api', AuthorizeIguideLab::class, 'throttle:iguide-lab-'.$limiter], $route->gatherMiddleware(), $key);
            $this->assertSame($editor[$method]->gatherMiddleware(), $route->gatherMiddleware(), $key);
            if (str_contains($route->uri(), '{id}')) $this->assertSame('[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}', $route->wheres['id']);
        }
        sort($seen);
        $keys = array_keys($expected);
        sort($keys);
        $this->assertSame($keys, $seen);
        // Non-UUID path segments do not match a route.
        $this->api('GET', 'tour-analyses/not-a-uuid')->assertNotFound();
        $this->api('POST', 'tour-analyses/'.$analysis->id.'/export', [])->assertNotFound();
    }

    public function test_unknown_keys_and_query_parameters_are_422(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed']);
        $calls = $this->doubles->calls;
        foreach ($this->routes($analysis->id) as [$method, $path, $body]) {
            if ($method !== 'POST') continue;
            $extra = $this->api('POST', $path, ($body ?? []) + ['debug' => true])->assertStatus(422);
            $this->assertSame(['tour_unsupported_field', 'This request has an unsupported field.'], [$extra->json('code'), $extra->json('message')], $path);
            $this->assertTourResponse($extra, 'POST', $path);
            $query = $this->api('POST', $path.'?x=1', $body ?? [])->assertStatus(422);
            $this->assertSame('tour_unsupported_field', $query->json('code'), "$path?x=1");
            // An allowed key in the query string is still a query string.
            $key = array_key_first($body ?: ['requestId' => 1]);
            $this->api('POST', $path.'?'.$key.'=1', $body ?? [])->assertStatus(422)->assertJsonPath('code', 'tour_unsupported_field');
        }
        $this->assertSame($calls, $this->doubles->calls, 'Rejected requests reach no tour service.');
        $this->queue->assertNothingPushed();
    }

    public function test_bodies_over_the_limit_are_413(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed']);
        $selection = $this->selectionBody(strtolower($analysis->captures()->first()->case_id));
        $big = json_encode($selection);
        $selectionPad = str_repeat(' ', 16384 - strlen($big) + 1);
        $response = $this->call('POST', '/api/iguide-lab/tour-analyses/'.$analysis->id.'/selection', [], [], [],
            $this->server(), $big.$selectionPad);
        $response->assertStatus(413)->assertJsonPath('code', 'tour_body_too_large')->assertJsonPath('message', 'The request is too large.');
        $this->assertTourResponse($response, 'POST', 'tour-analyses/'.$analysis->id.'/selection');
        // Exactly at the limit is accepted by the size guard (and then validated).
        $this->doubles->write = fn ($a, $input) => ['echo' => $input];
        $this->call('POST', '/api/iguide-lab/tour-analyses/'.$analysis->id.'/selection', [], [], [], $this->server(),
            $big.str_repeat(' ', 16384 - strlen($big)))->assertOk();

        foreach ($this->routes($analysis->id) as [$method, $path, $body]) {
            if ($method !== 'POST' || str_ends_with($path, '/selection')) continue;
            $json = json_encode($body ?? (object) []);
            $this->call('POST', '/api/iguide-lab/'.$path, [], [], [], $this->server(), $json.str_repeat(' ', 4097 - strlen($json)))
                ->assertStatus(413)->assertJsonPath('code', 'tour_body_too_large');
        }
        $this->assertSame([], $this->doubles->callsTo('create'));
        $this->assertSame([], $this->doubles->callsTo('cancel'));
    }

    public function test_revisions_and_accepted_need_strict_json_types(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed']);
        $caseId = strtolower($analysis->captures()->first()->case_id);
        $path = 'tour-analyses/'.$analysis->id;
        $invalid = fn (TestResponse $r) => $r->assertStatus(422)->assertJsonPath('code', 'tour_invalid_type')->assertJsonPath('message', 'The request has an invalid value.');

        $invalid($this->api('POST', "$path/selection", ['baseRevision' => '3'] + $this->selectionBody($caseId)));
        $invalid($this->raw("$path/selection", '{"preset":"golden-hour","baseRevision":3.0,"changes":[{"caseId":"'.$caseId.'","add":[],"remove":["g0"]}]}'));
        $invalid($this->raw("$path/selection", '{"preset":"golden-hour","baseRevision":0,"changes":{"0":{"caseId":"'.$caseId.'","add":[],"remove":["g0"]}}}'));
        $invalid($this->raw("$path/selection", '{"preset":"golden-hour","baseRevision":0,"changes":[{"caseId":"'.$caseId.'","add":{},"remove":["g0"]}]}'));
        $invalid($this->raw("$path/selection", '{"preset":"golden-hour","baseRevision":0,"changes":[{"caseId":"'.$caseId.'","add":[],"remove":{"0":"g0"}}]}'));
        $invalid($this->api('POST', "$path/runs", ['selectionRevision' => '3'] + $this->runBody()));
        $invalid($this->raw("$path/runs", json_encode(['selectionRevision' => 1.0] + $this->runBody(), JSON_PRESERVE_ZERO_FRACTION)));
        $run = $this->tourRun($analysis, 'golden-hour', 'running', [[$caseId, 'g0', 'held']]);
        foreach ([1, '1', 0] as $accepted) {
            $invalid($this->api('POST', "$path/accept", ['runId' => $run->id, 'caseId' => $caseId, 'accepted' => $accepted]));
        }
        $this->api('POST', "$path/accept", ['runId' => $run->id, 'caseId' => $caseId, 'accepted' => 'true'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->api('POST', "$path/runs", ['selectionRevision' => -1] + $this->runBody())->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->api('POST', "$path/runs", ['selectionRevision' => 2147483648] + $this->runBody())->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame([], $this->doubles->callsTo('write'));
        $this->assertSame([], $this->doubles->callsTo('create'));
        $this->assertSame([], $this->doubles->callsTo('accept'));

        // The same bodies with JSON integers and booleans reach the services.
        $this->doubles->write = fn ($a, $input) => ['echo' => $input];
        $this->api('POST', "$path/selection", $this->selectionBody($caseId))->assertOk();
        $this->api('POST', "$path/accept", ['runId' => $run->id, 'caseId' => $caseId, 'accepted' => false])->assertOk();
        $this->assertSame(0, $this->doubles->callsTo('write')[0][1][1]['baseRevision']);
        $this->assertFalse($this->doubles->callsTo('accept')[0][1][1]['accepted']);
    }

    public function test_every_public_text_passes_the_bridge_rule(): void
    {
        $texts = [];
        foreach (LabTourErrors::MESSAGES as $key => [$status, $code, $text]) $texts[$code] = [$status, $text];
        foreach (LabTourErrors::UNPROCESSABLE as $code => [$status, $text]) $texts[$code] = [$status, LabTourErrors::unprocessable($code)->getMessage()];
        $this->assertCount(21, $texts);
        foreach ($texts as $code => [$status, $text]) {
            $this->assertMatchesRegularExpression(self::BRIDGE_SAFE_MESSAGE, $text, $code);
            $this->assertLessThanOrEqual(200, mb_strlen($text), $code);
            $this->assertContains($status, [409, 413, 422, 429], $code);
        }
        // The backend texts are the contract's TOUR_CONFLICT_MESSAGES, all twelve (requestReused included, spec 12.1).
        $this->assertSame(self::CONTRACT_MESSAGES, array_map(fn ($row) => $row[2], LabTourErrors::MESSAGES));
        // The default texts the tour routes can also produce (404, 401, throttle 429) pass too.
        foreach (['The requested item was not found.', 'Please sign in to continue.', 'Too many attempts. Please wait and try again.',
            'Please check the information you entered.'] as $text) $this->assertMatchesRegularExpression(self::BRIDGE_SAFE_MESSAGE, $text);
    }

    public function test_cancel_rejects_any_key_and_is_idempotent(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed', 'pending']);
        $path = 'tour-analyses/'.$analysis->id.'/cancel';
        foreach ([['requestId' => (string) Str::uuid()], ['x' => null], ['0' => 1]] as $body) {
            $this->api('POST', $path, $body)->assertStatus(422)->assertJsonPath('code', 'tour_unsupported_field');
        }
        $this->raw($path, '[1]')->assertStatus(422)->assertJsonPath('code', 'tour_unsupported_field');
        $this->assertSame([], $this->doubles->callsTo('cancel'));

        $this->doubles->cancel = function (IguideLabTourAnalysis $a) {
            // Idempotent stand-in for LabTourAnalyses::cancel (9.6 step 1): only analysing work changes, once.
            DB::transaction(function () use ($a) {
                $changed = IguideLabTourCapture::where('analysis_id', $a->id)->whereIn('status', ['pending', 'surveying'])->update(['status' => 'skipped', 'error_code' => 'cancelled']);
                if ($changed) IguideLabTourAnalysis::whereKey($a->id)->whereIn('status', ['queued', 'analysing'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'completed_at' => now(), 'record_revision' => DB::raw('record_revision + 1')]);
            });

            return $a->fresh();
        };
        $first = $this->api('POST', $path, [])->assertOk();
        $this->travel(5)->seconds();
        $second = $this->raw($path, '{}')->assertOk();
        $live = fn (TestResponse $r) => collect($r->json('data'))->replace(['presets' => collect($r->json('data.presets'))
            ->map(fn ($state) => ['estimate' => collect($state['estimate'])->except('computedAt')->all()] + $state)->all()])->all();
        $this->assertTourResponse($first, 'POST', $path);
        $this->assertTourResponse($second, 'POST', $path);
        $this->assertSame('cancelled', $first->json('data.status'));
        $this->assertSame($live($first), $live($second), 'Only the live estimate computedAt differs between the two answers.');
        $this->assertNotSame($first->json('data.presets.golden-hour.estimate.computedAt'), $second->json('data.presets.golden-hour.estimate.computedAt'));
        $this->assertCount(2, $this->doubles->callsTo('cancel'));
        // The cancel answer is built without a GET kick (no side effects beyond the cancel itself).
        $this->assertSame([], $this->doubles->callsTo('kickIfStale'));
        $this->queue->assertNothingPushed();
    }

    public function test_capabilities_reason_codes_and_30_second_cache(): void
    {
        config(['iguide-lab-tour.grass_runs_enabled' => true, 'iguide-lab-tour.declutter_runs_enabled' => true, 'iguide-lab.grass_workflow_enabled' => true,
            'iguide-lab-tour.caps.survey_calls_per_project' => 240, 'iguide-lab-tour.caps.survey_calls_per_day' => 160, 'iguide-lab-tour.worker_count' => 2]);
        $this->policy->ready = ['golden-hour' => true, 'twilight' => false, 'grass' => true, 'declutter' => true];
        $this->doubles->attemptsUsedByProject = 26;
        $this->doubles->attemptsUsedToday = 170;

        $first = $this->api('GET', 'tour-analyses')->assertOk();
        $this->assertTourResponse($first, 'GET', 'tour-analyses');
        $this->assertSame([['preset' => 'golden-hour', 'ready' => true, 'reason' => null], ['preset' => 'twilight', 'ready' => false, 'reason' => 'recipe_unavailable'],
            ['preset' => 'grass', 'ready' => false, 'reason' => 'runs_not_enabled'], ['preset' => 'declutter', 'ready' => false, 'reason' => 'runs_not_enabled']],
            $first->json('data.capabilities.presets'), 'D1: grass and declutter runs stay off whatever the env says.');
        $this->assertSame(['maxCaptures' => 80, 'workflowChunk' => 8, 'surveyCallsLeftProject' => 214, 'surveyCallsLeftToday' => 0], $first->json('data.capabilities.limits'));
        $this->assertSame(['surveyWorkers' => 2, 'surveyMinutesPerCapture' => ['min' => 1, 'max' => 2], 'editMinutesPerRoom' => ['min' => 3, 'max' => 5]],
            $first->json('data.capabilities.timing'));
        $this->assertSame(['version' => 'tour-analysis/1', 'enabled' => true, 'runsPaused' => false, 'surveyVersion' => 'tour-survey/1', 'defaultsVersion' => 'tour-defaults/1'],
            array_slice($first->json('data.capabilities'), 0, 5));
        $this->assertSame([1, 1], [$this->policy->capabilitiesCalls, $this->policy->snapshotCalls]);

        $this->travel(29)->seconds();
        $this->api('GET', 'tour-analyses')->assertOk();
        $this->assertSame(1, $this->policy->capabilitiesCalls, 'Two GETs within 30 s call LabWorkflowPolicy::capabilities once.');
        $this->assertTrue(app(LabTourCapabilities::class)->runnable('golden-hour'));
        $this->assertFalse(app(LabTourCapabilities::class)->runnable('twilight'));
        $this->assertFalse(app(LabTourCapabilities::class)->runnable('grass'));
        $this->assertFalse(app(LabTourCapabilities::class)->runnable('unknown'));
        $facts = app(LabTourCapabilities::class)->policyFacts();
        $this->assertSame(1, $this->policy->capabilitiesCalls);
        $this->assertSame(['schedulerEnabled', 'tourBudgetEnabled', 'policyKeys', 'limits'], array_keys($facts));
        $this->assertSame([false, false], [$facts['schedulerEnabled'], $facts['tourBudgetEnabled']]);
        $this->assertSame(['lightingRepair' => true, 'grassAssessment' => false, 'grassSourceAdmission' => false, 'declutterRemoval' => false], $facts['policyKeys']);
        $this->assertSame(['golden-hour', 'twilight', 'grass', 'declutter'], array_keys($facts['limits']));
        $this->assertSame(5, $facts['limits']['golden-hour']['evaluation'], 'lightingRepair limits (LabWorkflowSteps.php:17)');
        config(['iguide-lab.tour_scheduler_enabled' => true, 'iguide-lab.tour_processing_budget_enabled' => true]);
        $this->assertSame([true, true], array_values(array_slice(app(LabTourCapabilities::class)->policyFacts(), 0, 2)));

        // A fresh check (the run monitor's view, 9.4) bypasses the cache.
        $this->assertTrue(app(LabTourCapabilities::class)->runnable('golden-hour', fresh: true));
        $this->assertSame(2, $this->policy->capabilitiesCalls);

        $this->travel(2)->seconds();
        $this->api('GET', 'tour-analyses')->assertOk();
        $this->assertSame(3, $this->policy->capabilitiesCalls, 'The cache expires after 30 s.');
        $this->assertSame([], array_values(array_filter($this->policy->levels, fn ($level) => $level !== $this->policy->baseline)),
            'LabWorkflowPolicy is only called at the baseline transaction level.');

        // D18 mapping for grass and declutter once their runs are enabled (D3/D5 replace the accessors).
        $mapping = new class extends LabTourCapabilities { protected function presetRunsEnabled(string $preset): bool { return true; } };
        config(['iguide-lab.grass_workflow_enabled' => false, 'iguide-lab.declutter_workflow_enabled' => true]);
        $this->policy->ready['declutter'] = false;
        \Illuminate\Support\Facades\Cache::flush();
        $presets = collect($mapping->dto($this->project)['capabilities']['presets'])->keyBy('preset');
        $this->assertSame(['ready' => false, 'reason' => 'preset_disabled'], array_slice($presets['grass'], 1));
        $this->assertSame(['ready' => false, 'reason' => 'recipe_unavailable'], array_slice($presets['declutter'], 1));
        $this->policy->ready['declutter'] = true;
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame(['ready' => true, 'reason' => null], array_slice(collect($mapping->dto($this->project)['capabilities']['presets'])->keyBy('preset')['declutter'], 1));
        $this->assertTrue($mapping->runnable('declutter'));
    }

    public function test_index_lists_ten_newest_summaries_of_this_project(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses = $i % 3 === 0 ? ['surveyed', 'failed'] : ($i % 3 === 1 ? ['pending', 'surveying', 'surveyed'] : ['skipped', 'surveyed']);
            $status = $i % 3 === 1 ? 'analysing' : ($i % 3 === 0 ? 'ready' : 'cancelled');
            $ids[] = $this->analysis($this->project, $statuses, $status)->id;
            if ($i < 11) $this->travel(1)->seconds();
        }
        [$other] = $this->project();
        $this->analysis($other, ['pending'], 'queued');
        $summaries = $this->api('GET', 'tour-analyses')->assertOk();
        $this->assertTourResponse($summaries, 'GET', 'tour-analyses');
        $this->assertSame(array_slice(array_reverse($ids), 0, 10), array_column($summaries->json('data.analyses'), 'id'));
        $first = $summaries->json('data.analyses.0');
        $this->assertSame(['id', 'importId', 'status', 'recordRevision', 'counts', 'createdAt', 'updatedAt'], array_keys($first));
        $this->assertSame(['total' => 2, 'surveyed' => 1, 'failed' => 0, 'skipped' => 1, 'pending' => 0], $summaries->json('data.analyses.0.counts'));
        $this->assertSame(['total' => 3, 'surveyed' => 1, 'failed' => 0, 'skipped' => 0, 'pending' => 2], $summaries->json('data.analyses.1.counts'));
        $this->assertSame(['total' => 2, 'surveyed' => 1, 'failed' => 1, 'skipped' => 0, 'pending' => 0], $summaries->json('data.analyses.2.counts'));
        $this->assertSame(now()->toIso8601String(), $first['createdAt']);
    }

    public function test_record_assembles_one_view_with_facts_computed_before_it(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed', 'surveyed', 'failed'], 'ready');
        $cases = $analysis->captures()->pluck('case_id')->all();
        $this->policy->ready = ['golden-hour' => true, 'twilight' => false, 'grass' => true, 'declutter' => true];
        $older = $this->tourRun($analysis, 'golden-hour', 'completed', [[$cases[0], 'g0', 'ready'], [$cases[1], 'g1', 'held', true]]);
        $this->travel(3)->seconds();
        $active = $this->tourRun($analysis, 'golden-hour', 'running', [[$cases[0], 'g0', 'processing'], [$cases[1], 'g1', 'waiting']]);
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['selections' => json_encode($this->selections(['golden-hour' => [1, [strtolower($cases[1]) => ['add' => [], 'remove' => ['g1']]]]]))]);

        $response = $this->api('GET', 'tour-analyses/'.strtoupper($analysis->id))->assertOk();
        $this->assertTourResponse($response, 'GET', 'tour-analyses/'.$analysis->id);
        $data = $response->json('data');
        $this->assertSame(['version', 'id', 'requestId', 'importId', 'status', 'recordRevision', 'surveyVersion', 'defaultsVersion', 'counts', 'captures', 'presets',
            'runs', 'createdAt', 'updatedAt', 'completedAt'], array_keys($data));
        $this->assertSame([$analysis->id, $analysis->request_id, $analysis->import_id, 'ready', 1], [$data['id'], $data['requestId'], $data['importId'], $data['status'], $data['recordRevision']]);
        $this->assertSame([$active->id, $older->id], array_column($data['runs'], 'id'));
        $this->assertSame($active->id, $data['presets']['golden-hour']['activeRunId']);
        $this->assertNull($data['presets']['twilight']['activeRunId']);
        $this->assertSame(1, $data['presets']['golden-hour']['selection']['revision']);
        $this->assertSame(['selectionRevision' => 1, 'roomsSelected' => 1, 'itemsSelected' => 1, 'roomsThatFit' => 1], array_slice($data['presets']['golden-hour']['estimate'], 0, 4));
        // Twilight has no executable default in these rooms, so nothing is selected and nothing is limited.
        $this->assertSame([0, 0, null], [$data['presets']['twilight']['estimate']['roomsSelected'], $data['presets']['twilight']['estimate']['roomsThatFit'],
            $data['presets']['twilight']['estimate']['limitingReason']]);
        $this->assertSame($analysis->fresh()->completed_at->toIso8601String(), $data['completedAt']);

        // Facts: one policyFacts() and a runnable() per preset, computed before the read snapshot and passed per preset.
        $estimates = $this->doubles->callsTo('estimate');
        $this->assertSame(['golden-hour', 'twilight', 'grass', 'declutter'], array_map(fn ($c) => $c[1][0], $estimates));
        $this->assertSame([true, false, false, false], array_map(fn ($c) => $c[1][1]['runnable'], $estimates));
        $this->assertSame(['policy', 'runnable', 'schedulerEnabled', 'tourBudgetEnabled'], array_keys($estimates[0][1][1]));
        $this->assertSame(['schedulerEnabled', 'tourBudgetEnabled', 'policyKeys', 'limits'], array_keys($estimates[0][1][1]['policy']));
        $snapshotLevel = $this->policy->baseline + 1;
        foreach (['countsDto', 'capturesDto', 'stateDto', 'estimate', 'activeRunId', 'dto'] as $part) {
            $this->assertNotEmpty($this->doubles->callsTo($part), $part);
            foreach ($this->doubles->callsTo($part) as $call) $this->assertSame($snapshotLevel, $call[2], "$part is built inside the read snapshot");
        }
        foreach (['needsMonitor', 'kickIfStale'] as $kick) foreach ($this->doubles->callsTo($kick) as $call) $this->assertSame($this->policy->baseline, $call[2]);
        $this->assertSame([], array_values(array_filter($this->policy->levels, fn ($level) => $level !== $this->policy->baseline)));
        $order = array_column($this->doubles->calls, 0);
        $this->assertLessThan(array_search('countsDto', $order, true), max(array_keys($order, 'needsMonitor', true)), 'Kicks run before the snapshot opens.');
        // Kicks: the analysis is ready (no analysis kick); the running run is kicked, the completed one is not even checked.
        $this->assertSame([[$active->id]], array_map(fn ($c) => $c[1], $this->doubles->callsTo('kickIfStale')));

        // An analysing record has no estimates (estimate exists only once settled).
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'analysing', 'completed_at' => null]);
        IguideLabTourCapture::where('analysis_id', $analysis->id)->where('status', 'failed')->toBase()->update(['status' => 'surveying', 'error_code' => null]);
        $analysing = $this->api('GET', 'tour-analyses/'.$analysis->id)->assertOk();
        $this->assertTourResponse($analysing, 'GET', 'tour-analyses/'.$analysis->id);
        $this->assertSame([null, null, null, null], array_column($analysing->json('data.presets'), 'estimate'));
        $this->assertNull($analysing->json('data.completedAt'));
    }

    public function test_unknown_or_foreign_analysis_is_404(): void
    {
        [$other, $otherToken] = $this->project();
        $foreign = $this->analysis($other, ['surveyed'], 'ready');
        foreach ($this->routes($foreign->id) as [$method, $path, $body]) {
            if ($path === 'tour-analyses') continue;
            $this->api($method, $path, $body)->assertNotFound()->assertJsonPath('message', 'The requested item was not found.');
            $this->api($method, str_replace($foreign->id, (string) Str::uuid(), $path), $body)->assertNotFound();
        }
        $this->withToken($otherToken)->getJson('/api/iguide-lab/tour-analyses/'.$foreign->id)->assertOk();
        $this->assertSame([], array_values(array_filter($this->doubles->calls, fn ($c) => in_array($c[0], ['create', 'write', 'cancel', 'accept'], true))));
    }

    public function test_post_routes_pass_validated_lowercased_input_and_answer_with_the_right_status(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed', 'surveyed'], 'ready');
        $cases = $analysis->captures()->pluck('case_id')->all();
        $base = 'tour-analyses/'.$analysis->id;

        // E2: 202 when survey work was queued, 200 otherwise; the record is built without a kick.
        $queued = true;
        $this->doubles->create = function ($project, $input) use ($analysis, &$queued) { return [$analysis->fresh(), $queued]; };
        $request = ['requestId' => strtoupper((string) Str::uuid()), 'importId' => strtoupper($analysis->import_id), 'preset' => 'twilight'];
        $created = $this->api('POST', 'tour-analyses', $request)->assertStatus(202);
        $this->assertTourResponse($created, 'POST', 'tour-analyses');
        $this->assertSame([$this->project->id, ['requestId' => strtolower($request['requestId']), 'importId' => $analysis->import_id, 'preset' => 'twilight'], null],
            $this->doubles->callsTo('create')[0][1]);
        $queued = false;
        $this->api('POST', 'tour-analyses', $request)->assertOk();
        $this->assertSame([], $this->doubles->callsTo('kickIfStale'));
        $this->api('POST', 'tour-analyses', ['preset' => 'sunset'] + $request)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->api('POST', 'tour-analyses', ['importId' => 'not-a-uuid'] + $request)->assertStatus(422);
        // UUID variant nibble outside 8/9/a/b (Laravel's bare uuid rule would accept it, the reader would not).
        $this->api('POST', 'tour-analyses', ['requestId' => '8f3c1a2e-5b6d-4e7f-ca9b-0c1d2e3f4a5b'] + $request)->assertStatus(422);
        $this->assertCount(2, $this->doubles->callsTo('create'));

        // E5: caseIds lowercased; facts for the posted preset computed before the write.
        $selection = ['preset' => 'golden-hour', 'baseRevision' => 0, 'changes' => [['caseId' => strtoupper($cases[1]), 'add' => [], 'remove' => ['g1']]]];
        $written = $this->api('POST', "$base/selection", $selection)->assertOk();
        $this->assertTourResponse($written, 'POST', "$base/selection");
        [$id, $input, $facts] = $this->doubles->callsTo('write')[0][1];
        $this->assertSame([$analysis->id, 'golden-hour', strtolower($cases[1])], [$id, $input['preset'], $input['changes'][0]['caseId']]);
        $this->assertSame(['policy', 'runnable', 'schedulerEnabled', 'tourBudgetEnabled'], array_keys($facts));
        $this->assertTrue($facts['runnable']);
        $this->assertSame($this->policy->baseline, $this->doubles->callsTo('write')[0][2], 'The write runs at the baseline level (its own lab write transaction).');
        $duplicate = fn (array $changes) => $this->api('POST', "$base/selection", ['preset' => 'golden-hour', 'baseRevision' => 0, 'changes' => $changes]);
        $duplicate([['caseId' => $cases[0], 'add' => [], 'remove' => ['g0']], ['caseId' => strtoupper($cases[0]), 'add' => [], 'remove' => []]])
            ->assertStatus(422)->assertJsonPath('code', 'tour_duplicate_change')
            ->assertJsonPath('message', 'Each room can appear only once, and an item cannot be both added and removed.');
        $duplicate([['caseId' => $cases[0], 'add' => ['g0'], 'remove' => ['g0']]])->assertStatus(422)->assertJsonPath('code', 'tour_duplicate_change');
        $duplicate([['caseId' => $cases[0], 'add' => ['g0', 'g0'], 'remove' => []]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $duplicate([['caseId' => $cases[0], 'add' => ['G0'], 'remove' => []]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $duplicate([['caseId' => $cases[0], 'add' => [], 'remove' => [], 'note' => 'x']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $duplicate([])->assertStatus(422);
        $this->assertCount(1, $this->doubles->callsTo('write'));

        // E6: 202 created, 200 replay; the run DTO comes from a read snapshot.
        $run = $this->tourRun($analysis, 'golden-hour', 'creating', [[$cases[0], 'g0', 'waiting']]);
        $createdRun = true;
        $this->doubles->runCreate = function () use ($run, &$createdRun) { return [$run, $createdRun]; };
        $runBody = ['requestId' => strtoupper((string) Str::uuid()), 'preset' => 'golden-hour', 'selectionRevision' => 0, 'selectionSha256' => self::SHA];
        $runResponse = $this->api('POST', "$base/runs", $runBody)->assertStatus(202);
        $this->assertTourResponse($runResponse, 'POST', "$base/runs");
        $this->assertSame($run->id, $runResponse->json('data.id'));
        $this->assertSame(['requestId' => strtolower($runBody['requestId']), 'preset' => 'golden-hour', 'selectionRevision' => 0, 'selectionSha256' => self::SHA],
            $this->doubles->callsTo('create')[2][1][1]);
        $createdRun = false;
        $this->api('POST', "$base/runs", $runBody)->assertOk();
        $this->api('POST', "$base/runs", ['selectionSha256' => strtoupper(self::SHA)] + $runBody)->assertStatus(422);

        // E7: accept answers the run DTO from LabTourAcceptances.
        $accept = $this->api('POST', "$base/accept", ['runId' => strtoupper($run->id), 'caseId' => strtoupper($cases[0]), 'accepted' => true])->assertOk();
        $this->assertTourResponse($accept, 'POST', "$base/accept");
        $this->assertSame(['runId' => $run->id, 'caseId' => strtolower($cases[0]), 'accepted' => true], $this->doubles->callsTo('accept')[0][1][1]);
        $this->queue->assertNothingPushed();
    }

    public function test_progress_is_read_only_and_under_2_kib(): void
    {
        config(['iguide-lab-tour.timing.survey_minutes_per_capture' => ['min' => 125, 'max' => 125], 'iguide-lab-tour.timing.edit_minutes_per_room' => ['min' => 125, 'max' => 125]]);
        $statuses = array_merge(array_fill(0, 70, 'surveyed'), array_fill(0, 5, 'failed'), array_fill(0, 5, 'pending'));
        $analysis = $this->analysis($this->project, $statuses, 'analysing', ['record_revision' => 2147483647, 'monitor_heartbeat_at' => now()]);
        $cases = $analysis->captures()->pluck('case_id')->all();
        foreach (['golden-hour', 'twilight', 'grass', 'declutter'] as $i => $preset) {
            $this->tourRun($analysis, $preset, 'running', array_map(fn ($case, $k) => [$case, ['g', 't', 'l', 'd'][$i].$k, $k === 0 ? 'queued' : 'waiting'], $cases, array_keys($cases)),
                ['monitor_heartbeat_at' => now()]);
        }
        $this->tourRun($analysis, 'golden-hour', 'completed', [[$cases[0], 'g0', 'ready']]);

        DB::enableQueryLog();
        $progress = $this->api('GET', 'tour-analyses/'.$analysis->id.'/progress')->assertOk();
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertTourResponse($progress, 'GET', 'tour-analyses/'.$analysis->id.'/progress');
        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => ! preg_match('/\A\s*(select|savepoint|release savepoint)\b/i', $sql))),
            'GET progress writes nothing itself when every heartbeat is fresh.');
        $this->queue->assertNothingPushed();
        $this->assertLessThan(2048, strlen($progress->getContent()));
        $this->assertLessThan(2048, strlen(json_encode(['data' => $progress->json('data')])));
        $data = $progress->json('data');
        $this->assertSame(['id', 'status', 'recordRevision', 'counts', 'etaMinutes', 'runs', 'updatedAt'], array_keys($data));
        $this->assertSame(['min' => 625, 'max' => 625], $data['etaMinutes'], 'ceil(5 pending x 125 / 1 worker)');
        $this->assertCount(4, $data['runs']);
        $this->assertSame(['rooms' => 80, 'done' => 0, 'ready' => 0, 'held' => 0, 'failed' => 0, 'etaMinutes' => ['min' => 10000, 'max' => 10000]],
            array_slice($data['runs'][0], 3));
        // The kicks were asked for the analysis and each run that could need a monitor, before the snapshot.
        $this->assertSame([$analysis->id], $this->doubles->callsTo('kickIfStale')[0][1]);
        $this->assertCount(5, $this->doubles->callsTo('kickIfStale'));

        // A finished record has no ETA, and its active run ETAs count only rooms still to do.
        IguideLabTourCapture::where('analysis_id', $analysis->id)->where('status', 'pending')->toBase()->update(['status' => 'skipped', 'error_code' => 'cancelled']);
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['status' => 'ready', 'completed_at' => now()]);
        IguideLabTourRunTarget::where('state', 'waiting')->where('position', '>=', 70)->toBase()->update(['state' => 'not_admitted', 'reasons' => '["daily_image_limit"]']);
        $settled = $this->api('GET', 'tour-analyses/'.$analysis->id.'/progress')->assertOk();
        $this->assertTourResponse($settled, 'GET', 'tour-analyses/'.$analysis->id.'/progress');
        $this->assertNull($settled->json('data.etaMinutes'));
        $this->assertSame([70, 0], [$settled->json('data.runs.0.rooms'), $settled->json('data.runs.0.done')]);
    }

    public function test_kick_failures_never_fail_the_get(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed', 'pending']);
        $this->doubles->kickFailure = new \RuntimeException('database is locked');
        Log::spy();
        $this->api('GET', 'tour-analyses/'.$analysis->id)->assertOk();
        $this->api('GET', 'tour-analyses/'.$analysis->id.'/progress')->assertOk();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'kick') && $context['analysisId'] === $analysis->id
            && $context['exception'] === \RuntimeException::class && ! str_contains(json_encode($context), 'database is locked'))->twice();
    }

    public function test_record_and_progress_are_built_from_one_snapshot(): void
    {
        $analysis = $this->analysis($this->project, ['surveyed', 'surveyed', 'surveying'], 'analysing');
        $last = $analysis->captures()->where('position', 2)->first();
        $this->policy->ready = ['golden-hour' => true, 'twilight' => true, 'grass' => true, 'declutter' => true];
        $surveyed = $this->surveyedColumns(2);
        $finish = "UPDATE iguide_lab_tour_captures SET status = 'surveyed', survey = ".DB::getPdo()->quote(json_encode($surveyed['survey'])).', applicability = '
            .DB::getPdo()->quote(json_encode($surveyed['applicability'])).', candidates = '.DB::getPdo()->quote(json_encode($surveyed['candidates']))
            ." WHERE id = '{$last->id}';"
            ." UPDATE iguide_lab_tour_analyses SET status = 'ready', completed_at = '2026-11-16 12:00:00', record_revision = record_revision + 1,"
            ." updated_at = '2026-11-16 12:00:00' WHERE id = '{$analysis->id}';";
        $restart = "UPDATE iguide_lab_tour_captures SET status = 'surveying', survey = NULL, applicability = NULL, candidates = NULL WHERE id = '{$last->id}';"
            ." UPDATE iguide_lab_tour_analyses SET status = 'analysing', completed_at = NULL, record_revision = 1 WHERE id = '{$analysis->id}';";

        $this->withWalDatabase(function (\PDO $writer) use ($analysis, $finish, $restart) {
            foreach (['tour-analyses/'.$analysis->id, 'tour-analyses/'.$analysis->id.'/progress'] as $path) {
                $writer->exec($restart);
                $hook = $this->commitBetweenLoaderQueries($writer, $finish);
                $before = $this->api('GET', $path)->assertOk();
                $this->assertTrue($hook->fired, "$path: the monitor commit ran between two loader queries");
                $this->assertNotEmpty($hook->after, "$path: loader queries ran after the commit");
                $this->assertTrue(collect($hook->after)->contains(fn ($sql) => str_contains($sql, 'iguide_lab_tour_captures')), "$path: captures were read after the commit");
                $this->assertTourResponse($before, 'GET', $path);
                $this->assertSame(['analysing', 1, 1], [$before->json('data.status'), $before->json('data.counts.pending'), $before->json('data.recordRevision')],
                    "$path is entirely from before the commit");
                $hook->disarm();
                $after = $this->api('GET', $path)->assertOk();
                $this->assertTourResponse($after, 'GET', $path);
                $this->assertSame(['ready', 0, 2], [$after->json('data.status'), $after->json('data.counts.pending'), $after->json('data.recordRevision')],
                    "$path is entirely from after the commit");
            }
        });
    }

    public function test_sweeper_restarts_dead_chains_and_is_a_no_op_when_disabled(): void
    {
        $stale = now()->subSeconds(301);
        $dead = $this->analysis($this->project, ['pending'], 'queued');
        $neverClaimed = $this->analysis($this->project, ['surveying'], 'analysing');
        $fresh = $this->analysis($this->project, ['surveying'], 'analysing', ['monitor_heartbeat_at' => now()->subSeconds(299)]);
        $waiting = $this->analysis($this->project, ['surveying'], 'analysing', ['monitor_heartbeat_at' => $stale]);
        $idle = $this->analysis($this->project, ['pending'], 'analysing', ['monitor_heartbeat_at' => $stale, 'monitor_idle' => true]);
        $this->analysis($this->project, ['surveyed'], 'ready', ['monitor_heartbeat_at' => $stale]);
        IguideLabTourAnalysis::whereKey($dead->id)->toBase()->update(['monitor_heartbeat_at' => $stale]);
        $host = $this->analysis($this->project, ['surveyed', 'surveyed'], 'ready');
        $cases = $host->captures()->pluck('case_id')->all();
        $deadRun = $this->tourRun($host, 'golden-hour', 'running', [[$cases[0], 'g0', 'queued']], ['monitor_heartbeat_at' => $stale]);
        $freshRun = $this->tourRun($host, 'twilight', 'creating', [[$cases[0], 't0', 'waiting']], ['monitor_heartbeat_at' => now()]);
        $this->tourRun($host, 'golden-hour', 'completed', [[$cases[1], 'g1', 'ready']]);
        $this->tourRun($host, 'golden-hour', 'cancelled', [[$cases[1], 'g1', 'cancelled']], ['monitor_heartbeat_at' => $stale]);
        $boundCancelled = $this->tourRun($host, 'golden-hour', 'cancelled', [[$cases[1], 'g1', 'processing']]);
        DB::table('jobs')->insert(['queue' => 'studio', 'payload' => json_encode(['displayName' => 'App\\Jobs\\MonitorIguideLabTourAnalysis', 'data' => ['analysisId' => $waiting->id]]),
            'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);

        config(['iguide-lab-tour.tour_analysis_enabled' => false]);
        $this->assertSame(0, Artisan::call('iguide-lab:tour-monitors'));
        $this->assertSame(['version' => 'tour-monitor-sweep/1', 'surveysChanged' => false, 'analysesKicked' => 0, 'runsKicked' => 0], json_decode(trim(Artisan::output()), true));
        $this->queue->assertNothingPushed();
        $this->assertSame([], $this->doubles->callsTo('sweep'));

        config(['iguide-lab-tour.tour_analysis_enabled' => true]);
        $this->doubles->sweepResult = true;
        $this->assertSame(0, Artisan::call('iguide-lab:tour-monitors'));
        $output = trim(Artisan::output());
        $this->assertSame(1, substr_count($output, "\n") + 1, 'one JSON line');
        $this->assertSame(['version' => 'tour-monitor-sweep/1', 'surveysChanged' => true, 'analysesKicked' => 2, 'runsKicked' => 2], json_decode($output, true));
        $this->assertCount(1, $this->doubles->callsTo('sweep'));
        $analysisJobs = $this->pushed(\App\Jobs\MonitorIguideLabTourAnalysis::class);
        $runJobs = $this->pushed(\App\Jobs\MonitorIguideLabTourRun::class);
        $this->assertEqualsCanonicalizing([$dead->id, $neverClaimed->id], array_map(fn ($job) => $job->analysisId, $analysisJobs));
        $this->assertEqualsCanonicalizing([$deadRun->id, $boundCancelled->id], array_map(fn ($job) => $job->runId, $runJobs));
        foreach (array_merge($analysisJobs, $runJobs) as $job) $this->assertNull($job->token, 'a new chain');
        $this->assertSame([$this->queue->baselineLevel], array_values(array_unique($this->queue->levels)));
        $this->assertNotContains($fresh->id, array_map(fn ($job) => $job->analysisId, $analysisJobs));
        $this->assertNotContains($idle->id, array_map(fn ($job) => $job->analysisId, $analysisJobs));
        $this->assertNotContains($freshRun->id, array_map(fn ($job) => $job->runId, $runJobs));

        // A failed push is logged and does not stop the other kicks; a failed survey sweep is logged and exits 1.
        // Not a lock error, so LockedWrite does not retry it.
        $this->queue->failNextPush(new \RuntimeException('queue connection refused'));
        $this->doubles->sweepFailure = new \RuntimeException('sweep failed');
        Log::spy();
        $this->assertSame(1, Artisan::call('iguide-lab:tour-monitors'));
        $this->assertSame(['version' => 'tour-monitor-sweep/1', 'surveysChanged' => false, 'analysesKicked' => 1, 'runsKicked' => 2], json_decode(trim(Artisan::output()), true));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'push failed'))->once();
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'survey sweep failed'))->once();

        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'iguide-lab:tour-monitors'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertInstanceOf(SweepIguideLabTourMonitors::class, app(SweepIguideLabTourMonitors::class));
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<array{0:string,1:string,2:?array}> the 8 routes with a valid body */
    private function routes(string $id): array
    {
        return [['GET', 'tour-analyses', null],
            ['POST', 'tour-analyses', ['requestId' => (string) Str::uuid(), 'importId' => (string) Str::uuid(), 'preset' => 'golden-hour']],
            ['GET', "tour-analyses/$id", null], ['GET', "tour-analyses/$id/progress", null],
            ['POST', "tour-analyses/$id/selection", $this->selectionBody((string) Str::uuid())],
            ['POST', "tour-analyses/$id/runs", $this->runBody()],
            ['POST', "tour-analyses/$id/accept", ['runId' => (string) Str::uuid(), 'caseId' => (string) Str::uuid(), 'accepted' => true]],
            ['POST', "tour-analyses/$id/cancel", []]];
    }

    private function selectionBody(string $caseId): array
    {
        return ['preset' => 'golden-hour', 'baseRevision' => 0, 'changes' => [['caseId' => $caseId, 'add' => [], 'remove' => ['g0']]]];
    }

    private function runBody(): array
    {
        return ['requestId' => (string) Str::uuid(), 'preset' => 'golden-hour', 'selectionRevision' => 0, 'selectionSha256' => self::SHA];
    }

    private function server(?string $token = null): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }

    private function api(string $method, string $path, ?array $body = null): TestResponse
    {
        return $this->withToken($this->token)->json($method, '/api/iguide-lab/'.$path, $body ?? []);
    }

    private function raw(string $path, string $json): TestResponse
    {
        return $this->call('POST', '/api/iguide-lab/'.$path, [], [], [], $this->server(), $json);
    }

    /** @return list<object> pushed jobs that are instances of $class (an alias of a stand-in until WP3/WP4 land) */
    private function pushed(string $class): array
    {
        $jobs = [];
        foreach ($this->queue->pushedJobs() as $list) foreach ($list as $entry) if ($entry['job'] instanceof $class) $jobs[] = $entry['job'];

        return $jobs;
    }

    /** @return array{0: IguideLabProject, 1: string} */
    private function project(): array
    {
        $token = bin2hex(random_bytes(32));

        return [IguideLabProject::create(['name' => 'Tour HTTP fixture', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(10), 'max_runs' => 60]), $token];
    }

    private function selections(array $states = []): array
    {
        $out = [];
        foreach (['golden-hour', 'twilight', 'grass', 'declutter'] as $preset) {
            [$revision, $changes] = $states[$preset] ?? [0, []];
            $out[$preset] = ['revision' => $revision, 'changes' => $changes === [] ? new \stdClass : $changes];
        }

        return $out;
    }

    private function analysis(IguideLabProject $project, array $statuses, string $status = 'analysing', array $attributes = []): IguideLabTourAnalysis
    {
        $importId = (string) Str::uuid();
        DB::table('iguide_lab_imports')->insert(['id' => $importId, 'project_id' => $project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'source_kind' => 'zip', 'private_input' => 'fixture', 'status' => 'completed',
            'sources' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $settled = ! in_array($status, ['queued', 'analysing'], true);
        $analysis = IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'import_id' => $importId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'golden-hour', 'status' => $status,
            'record_revision' => 1, 'selections' => $this->selections(), 'completed_at' => $settled ? now() : null] + $attributes);
        foreach ($statuses as $position => $captureStatus) {
            $case = (string) Str::uuid();
            DB::table('iguide_lab_cases')->insert(['id' => $case, 'project_id' => $project->id, 'label' => 'Room '.$position, 'projection' => 'equirectangular',
                'width' => 2048, 'height' => 1024, 'source_sha256' => hash('sha256', $case), 'source_path' => 'fixture/'.$case.'.png',
                'source_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
            IguideLabTourCapture::create(['id' => (string) Str::uuid(), 'analysis_id' => $analysis->id, 'project_id' => $project->id, 'case_id' => $case,
                'position' => $position, 'room_name' => 'KITCHEN '.$position, 'floor_name' => $position % 2 ? null : 'Main', 'source_sha256' => hash('sha256', $case),
                'preview_sha256' => str_repeat('2', 64), 'status' => $captureStatus,
                'error_code' => match ($captureStatus) { 'failed' => 'survey_unreadable', 'skipped' => 'cancelled', default => null }]
                + ($captureStatus === 'surveyed' ? $this->surveyedColumns($position) : []));
        }

        return $analysis;
    }

    /** A contract-valid surveyed interior capture: golden hour recommended (g<i>), twilight not applicable (t<i>). */
    private function surveyedColumns(int $i): array
    {
        $full = ['yaw' => 0, 'pitch' => 0, 'hfov' => 360, 'vfov' => 180];
        $candidate = fn (string $id, string $preset, bool $on, array $reasons) => ['id' => $id, 'groupId' => null, 'preset' => $preset, 'kind' => 'whole_room',
            'label' => $preset === 'twilight' ? 'Twilight' : 'Golden hour', 'description' => '', 'origin' => 'rule', 'region' => $full, 'confidence' => 0.8,
            'executable' => $on, 'defaultSelected' => $on, 'reasons' => $reasons];

        return ['survey' => ['space' => ['setting' => 'interior', 'label' => 'Kitchen'], 'skyShare' => 0, 'light' => ['windows' => true, 'daylight' => true,
                'lamps' => false, 'hardShadows' => false, 'mirrorsOrGlass' => false], 'lawn' => ['status' => 'none', 'condition' => null, 'areas' => []],
                'clutter' => ['surfaces' => []], 'notes' => []],
            'applicability' => ['golden-hour' => ['status' => 'recommended', 'reasons' => ['daylight_windows']], 'twilight' => ['status' => 'not_applicable', 'reasons' => ['outdoor_twilight_pending']],
                'grass' => ['status' => 'not_applicable', 'reasons' => ['interior']], 'declutter' => ['status' => 'not_applicable', 'reasons' => ['no_loose_items']]],
            'candidates' => [$candidate("g$i", 'golden-hour', true, ['daylight_windows']), $candidate("t$i", 'twilight', false, ['outdoor_twilight_pending'])],
            'defaults_version' => 'tour-defaults/1', 'defaults_flags' => ['grassRunsEnabled' => false, 'declutterRunsEnabled' => false], 'surveyed_at' => now()];
    }

    /**
     * @param list<array{0:string,1:string,2:string,3?:bool}> $targets [caseId, candidateId, state, accepted]
     */
    private function tourRun(IguideLabTourAnalysis $analysis, string $preset, string $status, array $targets, array $attributes = []): IguideLabTourRun
    {
        $admitted = count(array_filter($targets, fn ($t) => $t[2] !== 'not_admitted'));
        $edit = ['min' => 3, 'max' => 5];
        $run = IguideLabTourRun::create(['id' => (string) Str::uuid(), 'project_id' => $analysis->project_id, 'analysis_id' => $analysis->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'preset' => $preset, 'status' => $status, 'selection_revision' => 0,
            'selection_sha256' => self::SHA, 'chunk_size' => 8, 'error_code' => $status === 'failed' ? 'chunk_start_failed' : null,
            'completed_at' => in_array($status, ['creating', 'running'], true) ? null : now(),
            'estimate' => ['selectionRevision' => 0, 'roomsSelected' => count($targets), 'itemsSelected' => count($targets), 'roomsThatFit' => $admitted,
                'worstCaseImages' => 3 * $admitted, 'limitingReason' => $admitted === count($targets) ? null : 'daily_image_limit',
                'estimatedMinutes' => ['min' => $edit['min'] * $admitted, 'max' => $edit['max'] * $admitted], 'computedAt' => now()->toIso8601String()]] + $attributes);
        $bound = array_values(array_filter($targets, fn ($t) => ! in_array($t[2], ['not_admitted', 'waiting'], true)));
        $workflowId = (string) Str::uuid();
        if ($bound) {
            IguideLabTourRunChunk::create(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $analysis->project_id, 'position' => 0,
                'request_id' => (string) Str::uuid(), 'case_ids' => array_map(fn ($t) => strtolower($t[0]), $bound), 'status' => 'started', 'workflow_id' => $workflowId]);
        }
        $rows = [];
        foreach ($targets as $position => $t) {
            $isBound = ! in_array($t[2], ['not_admitted', 'waiting'], true);
            $rows[] = ['id' => (string) Str::uuid(), 'run_id' => $run->id, 'project_id' => $analysis->project_id, 'case_id' => strtolower($t[0]), 'position' => $position,
                'candidate_ids' => json_encode([$t[1]]), 'state' => $t[2], 'chunk_id' => null, 'workflow_id' => $isBound ? $workflowId : null,
                'workflow_item_id' => $isBound ? (string) Str::uuid() : null, 'has_attempt' => in_array($t[2], ['ready', 'held'], true),
                'reasons' => json_encode($t[2] === 'not_admitted' ? ['daily_image_limit'] : ($t[2] === 'held' ? ['sky_edge_needs_review'] : [])),
                'accepted_at' => ($t[3] ?? false) ? now() : null, 'acceptance_log' => '[]', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) IguideLabTourRunTarget::insert($chunk);

        return $run->fresh();
    }

    /** Runs $test with the default connection on a WAL copy of the tables the tour routes read, and a second writer on that file. */
    private function withWalDatabase(callable $test): void
    {
        $directory = sys_get_temp_dir().'/tour-snapshot-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $file = $directory.'/tour.sqlite';
        $writer = new \PDO('sqlite:'.$file);
        $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA journal_mode=WAL');
        $tables = ['iguide_lab_projects', 'iguide_lab_imports', 'iguide_lab_cases', 'iguide_lab_tour_analyses', 'iguide_lab_tour_captures', 'iguide_lab_tour_runs',
            'iguide_lab_tour_run_chunks', 'iguide_lab_tour_run_targets', 'jobs'];
        foreach ($tables as $table) {
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE tbl_name = ? AND sql IS NOT NULL ORDER BY type DESC", [$table]) as $ddl) $writer->exec($ddl->sql);
            foreach (DB::table($table)->get() as $row) {
                $row = (array) $row;
                $writer->prepare('INSERT INTO "'.$table.'" ("'.implode('","', array_keys($row)).'") VALUES ('.implode(',', array_fill(0, count($row), '?')).')')
                    ->execute(array_values($row));
            }
        }
        config(['database.connections.tour_snapshot' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true,
            'busy_timeout' => 5000, 'journal_mode' => 'wal']]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('tour_snapshot');
        try {
            $test($writer);
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('tour_snapshot');
            $writer = null;
            foreach (glob($directory.'/*') ?: [] as $path) @unlink($path);
            @rmdir($directory);
        }
    }

    /**
     * Commit $sql through a second connection right after the loader's first query: the second select from the analyses
     * table in the request (the first is the controller's lookup). It does not depend on how the code under test frames
     * its reads, so a loader without one snapshot would read the captures after the commit and mix the two states.
     */
    private function commitBetweenLoaderQueries(\PDO $writer, string $sql): object
    {
        $hook = new class
        {
            public bool $armed = true;
            public bool $fired = false;
            public int $analysisSelects = 0;
            /** @var list<string> */
            public array $after = [];
            public function disarm(): void { $this->armed = false; }
        };
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($hook, $writer, $sql) {
            if (! $hook->armed || $query->connectionName !== 'tour_snapshot') return;
            if ($hook->fired) { $hook->after[] = $query->sql; return; }
            if (! preg_match('/\A\s*select .* from "iguide_lab_tour_analyses"/is', $query->sql) || ++$hook->analysisSelects < 2) return;
            $hook->fired = true;
            $writer->exec('BEGIN IMMEDIATE');
            $writer->exec($sql);
            $writer->exec('COMMIT');
        });

        return $hook;
    }
}

