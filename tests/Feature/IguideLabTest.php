<?php

namespace Tests\Feature;

use App\Exceptions\OpenAiImageException;
use App\Jobs\ProcessIguideLabRun;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabReview;
use App\Services\IguideLab\LabProvider;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private IguideLabProject $project;
    private string $primaryKeyPath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'test-openai-key', 'services.fal.key' => 'test-fal-key']);
        $this->primaryKeyPath = tempnam(sys_get_temp_dir(), 'lab-key-');
        file_put_contents($this->primaryKeyPath, 'test-primary-key');
        config(['iguide-lab.openai_primary_key_path' => $this->primaryKeyPath]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory('');
        $disk->setVisibility('', 'private');
        Queue::fake();
        Http::preventStrayRequests();
        $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Private lab', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDays(30), 'max_runs' => 60]);
    }

    protected function tearDown(): void
    {
        if (isset($this->primaryKeyPath) && is_file($this->primaryKeyPath)) unlink($this->primaryKeyPath);
        parent::tearDown();
    }

    public function test_primary_key_is_used_and_explicit_quota_rejection_uses_one_fallback_in_the_same_run(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        Http::fakeSequence('api.openai.com/v1/images/edits')
            ->push(['error' => ['code' => 'credit_balance_exhausted', 'type' => 'insufficient_quota']], 429, ['x-request-id' => 'req-quota'])
            ->push(['data' => [['b64_json' => base64_encode($this->png(2048, 1024))]]], 200, ['x-request-id' => 'req-fallback']);
        app(LabService::class)->process($run);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('req-fallback', $run->provider_request_id);
        $this->assertSame(['lab-primary', 'dashboard-fallback'], array_column($run->options['providerAttempts'], 'credentialSource'));
        $this->assertSame(['quota_rejected', 'received'], array_column($run->options['providerAttempts'], 'status'));
        $this->assertSame('req-quota', $run->options['providerAttempts'][0]['providerRequestId']);
        $this->assertSame(1, $this->project->fresh()->used_runs);
        $requests = Http::recorded();
        $this->assertTrue($requests[0][0]->hasHeader('Authorization', 'Bearer test-primary-key'));
        $this->assertTrue($requests[1][0]->hasHeader('Authorization', 'Bearer test-openai-key'));
        $primaryFields = collect($requests[0][0]->data())->keyBy('name');
        $fallbackFields = collect($requests[1][0]->data())->keyBy('name');
        $this->assertSame('gpt-image-2.6-sunburst', $primaryFields['model']['contents']);
        foreach (['model', 'prompt', 'size', 'quality', 'complexity'] as $field) $this->assertSame($primaryFields[$field]['contents'], $fallbackFields[$field]['contents']);
        app(LabService::class)->process($run);
        Http::assertSentCount(2);
        $body = $this->withToken($this->token)->getJson('/api/iguide-lab/runs/'.$run->id)->assertOk()->getContent();
        foreach (['test-primary-key', 'test-openai-key', $this->primaryKeyPath] as $secret) $this->assertStringNotContainsString($secret, $body);
    }

    public function test_rate_limits_auth_errors_and_timeouts_never_switch_to_dashboard_credentials(): void
    {
        $sequence = Http::fakeSequence('api.openai.com/v1/images/edits');
        foreach ([['rate_limit_exceeded', 429], ['slow_down', 429], ['invalid_api_key', 401], ['server_error', 500]] as [$code, $status]) {
            $sequence->push(['error' => ['code' => $code, 'type' => 'insufficient_quota']], $status);
        }
        for ($index = 0; $index < 4; $index++) {
            $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
            app(LabService::class)->process($run);
            $this->assertCount(1, $run->fresh()->options['providerAttempts']);
        }
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-openai-key'));
    }

    public function test_transport_failure_is_ambiguous_without_fallback_or_automatic_replay(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        $attempts = 0;
        Http::fake(function () use (&$attempts) { $attempts++; throw new \Illuminate\Http\Client\ConnectionException('fixture timeout'); });
        app(LabService::class)->process($run);
        app(LabService::class)->process($run->fresh());
        $this->assertSame(1, $attempts);
        $this->assertSame('needs_attention', $run->fresh()->status);
        $this->assertSame('unknown', $run->fresh()->options['providerAttempts'][0]['status']);
    }

    public function test_fallback_failure_stops_after_two_attempts_and_missing_primary_fails_closed(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        Http::fakeSequence('api.openai.com/v1/images/edits')
            ->push(['error' => ['code' => 'insufficient_quota']], 429)
            ->push(['error' => ['code' => 'insufficient_quota']], 429);
        app(LabService::class)->process($run);
        app(LabService::class)->process($run->fresh());
        Http::assertSentCount(2);
        $this->assertSame('failed', $run->fresh()->status);
        $next = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        unlink($this->primaryKeyPath);
        app(LabService::class)->process($next);
        $this->assertSame('failed', $next->fresh()->status);
        Http::assertSentCount(2);
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($next->case_id))->assertStatus(503);
    }

    public function test_reviews_are_append_only_idempotent_and_latest_review_persists(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        $run->update(['status' => 'completed']);
        $input = $this->reviewInput();
        $url = '/api/iguide-lab/runs/'.$run->id.'/reviews';
        $first = $this->withToken($this->token)->postJson($url, $input)->assertCreated()->json('data');
        $this->withToken($this->token)->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->withToken($this->token)->postJson($url, [...$input, 'notes' => 'Changed'])->assertConflict();
        $next = [...$input, 'requestId' => (string) Str::uuid(), 'verdict' => 'needs_work', 'notes' => 'Sofa changes across views.'];
        $this->withToken($this->token)->postJson($url, $next)->assertCreated();
        $this->assertSame(2, IguideLabReview::where('run_id', $run->id)->count());
        $this->assertSame('accepted', IguideLabReview::oldest('id')->first()->verdict);
        $this->withToken($this->token)->getJson('/api/iguide-lab/runs/'.$run->id)->assertOk()
            ->assertJsonPath('data.latestReview.verdict', 'needs_work')->assertJsonPath('data.latestReview.requestId', $next['requestId']);
        $this->assertSame(1, $this->project->fresh()->used_runs);
    }

    public function test_review_auth_scope_completion_and_acceptance_are_enforced(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        $url = '/api/iguide-lab/runs/'.$run->id.'/reviews';
        $input = $this->reviewInput();
        $this->withHeader('Authorization', '')->postJson($url, $input)->assertUnauthorized();
        $other = str_repeat('b', 64);
        IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 1]);
        $this->withToken($other)->postJson($url, $input)->assertNotFound();
        $this->withToken($this->token)->postJson($url, $input)->assertUnprocessable();
        $run->update(['status' => 'completed']);
        foreach (['fail', 'unreviewed', 'not_applicable'] as $value) {
            $invalid = $input; $invalid['checks']['geometry'] = $value;
            $this->withToken($this->token)->postJson($url, $invalid)->assertUnprocessable();
        }
        $this->withToken($this->token)->postJson($url, [...$input, 'scope' => 'cross_view'])->assertUnprocessable();
        $invalid = $input; $invalid['checks']['crossView'] = 'pass';
        $this->withToken($this->token)->postJson($url, $invalid)->assertUnprocessable();
        $this->withToken($this->token)->postJson($url, [...$invalid, 'scope' => 'cross_view'])->assertCreated();
        $this->withToken($this->token)->postJson($url, [...$input, 'requestId' => (string) Str::uuid(), 'metrics' => ['oversized' => str_repeat('x', 8193)]])->assertUnprocessable();
        $this->assertSame(1, IguideLabReview::count());
        Http::assertNothingSent();
    }

    private function reviewInput(): array
    {
        return ['requestId' => (string) Str::uuid(), 'verdict' => 'accepted', 'checks' => ['edit' => 'pass', 'geometry' => 'pass', 'seam' => 'pass', 'appearance' => 'pass', 'crossView' => 'not_applicable'],
            'notes' => 'Reviewed matching camera view and wrap seam.', 'pipeline' => 'whole_panorama', 'scope' => 'single_panorama', 'batchId' => 'batch-1', 'metrics' => ['seam' => 0.02]];
    }

    public function test_capability_is_required_and_expired_or_revoked_capabilities_fail_closed(): void
    {
        $this->getJson('/api/iguide-lab')->assertUnauthorized();
        $this->postJson('/api/iguide-lab/runs', [])->assertUnauthorized();
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->assertJsonCount(5, 'data.models')->assertJsonPath('data.project.remainingRuns', 60);
        $this->project->update(['expires_at' => now()->subSecond()]);
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertUnauthorized();
        $this->project->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_source_provenance_round_trips_without_exposing_private_paths_or_credentials(): void
    {
        $case = $this->upload();
        $this->assertSame(256, $case['width']);
        $this->assertSame(11008, $case['metadata']['originalWidth']);
        $this->assertSame('room-1', $case['roomId']);
        $this->withToken($this->token)->get($case['assetPath'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $body = $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->getContent();
        foreach ([$this->token, 'test-openai-key', 'test-fal-key', 'source_path', 'token_hash'] as $secret) $this->assertStringNotContainsString($secret, $body);
    }

    public function test_other_capability_cannot_read_sources_runs_or_use_reference_images(): void
    {
        $case = $this->upload();
        $run = $this->start($case['id']);
        $other = str_repeat('b', 64);
        IguideLabProject::create(['name' => 'Other lab', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 2]);
        $this->withToken($other)->get($case['assetPath'])->assertNotFound();
        $this->withToken($other)->getJson('/api/iguide-lab/runs/'.$run['id'])->assertNotFound();
        $this->withToken($other)->postJson('/api/iguide-lab/runs', $this->input($case['id']))->assertNotFound();
        $own = $this->upload($other);
        $this->withToken($other)->postJson('/api/iguide-lab/runs', $this->input($own['id'], ['options' => ['referenceCaseIds' => [$case['id']]]]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_idempotency_replays_one_job_and_rejects_changed_settings(): void
    {
        $case = $this->upload();
        $input = $this->input($case['id']);
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->json('data');
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->assertJsonPath('data.id', $first['id'])->assertJsonPath('data.requestId', $input['requestId']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [...$input, 'preset' => 'twilight'])->assertConflict();
        Queue::assertPushed(ProcessIguideLabRun::class, 1);
        $this->assertSame(1, $this->project->fresh()->used_runs);
    }

    public function test_budget_and_model_allowlist_are_enforced_before_dispatch(): void
    {
        $case = $this->upload();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['modelId' => 'attacker/model']))->assertUnprocessable();
        $this->project->update(['max_runs' => 1]);
        $this->start($case['id']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id']))->assertStatus(429);
        Queue::assertPushed(ProcessIguideLabRun::class, 1);
        Http::assertNothingSent();
    }

    public function test_projection_shape_and_unknown_options_are_rejected(): void
    {
        $this->withToken($this->token)->post('/api/iguide-lab/cases', ['label' => 'Wrong panorama', 'projection' => 'equirectangular', 'image' => UploadedFile::fake()->image('source.jpg', 256, 256)], ['Accept' => 'application/json'])->assertUnprocessable();
        $case = $this->upload();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['options' => ['width' => 8192]]))->assertUnprocessable();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['options' => ['allowFinishChanges' => true]]))->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_controls_and_panorama_requirements_reach_the_saved_prompt(): void
    {
        $case = $this->upload();
        $run = $this->start($case['id'], ['preset' => 'interior-design', 'style' => 'modern-farmhouse', 'prompt' => 'Use warm timber and linen.', 'options' => ['furnitureDensity' => 'light', 'lightingIntensity' => 'dramatic', 'preserveFixedFinishes' => false, 'allowFinishChanges' => true]]);
        foreach (['Modern farmhouse', 'Furniture density: light', 'Lighting intensity: dramatic', 'change fixed surface finishes', 'Left and right edges join', 'exact orientation'] as $phrase) $this->assertStringContainsString($phrase, $run['prompt']);
        $this->assertSame(2048, $run['options']['width']);
        $this->assertSame(1024, $run['options']['height']);
        $this->assertSame('Use warm timber and linen.', $run['options']['customInstructions']);
        $this->assertSame('Use warm timber and linen.', $run['customPrompt']);
        $this->assertStringNotContainsString('Edit the supplied property image', $run['customPrompt']);
    }

    public function test_success_is_saved_privately_and_duplicate_processing_does_not_spend_again(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        $png = $this->png(2048, 1024);
        $this->mock(LabProvider::class)->shouldReceive('submit')->once()->andReturn(['bytes' => $png, 'requestId' => 'req-test', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50]]);
        $service = app(LabService::class);
        $this->assertTrue($service->process($run));
        $this->assertTrue($service->process($run->fresh()));
        $this->withToken($this->token)->getJson('/api/iguide-lab/runs/'.$run->id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.providerRequestId', 'req-test')->assertJsonPath('data.output.preview', true)->assertJsonPath('data.output.width', 2048);
        $this->withToken($this->token)->get('/api/iguide-lab/assets/run/'.$run->id)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_ambiguous_openai_attempt_is_never_automatically_resubmitted(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        $this->mock(LabProvider::class)->shouldReceive('submit')->once()->andThrow(new OpenAiImageException(ambiguous: true, reason: 'transport'));
        app(LabService::class)->process($run);
        app(LabService::class)->process($run->fresh());
        $this->assertSame('needs_attention', $run->fresh()->status);
        $this->assertSame(1, $this->project->fresh()->used_runs);
    }

    public function test_seedream_polling_resumes_known_request_instead_of_submitting_again(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'], ['modelId' => 'bytedance/seedream/v5/pro/edit'])['id']);
        $mock = $this->mock(LabProvider::class);
        $mock->shouldReceive('submit')->once()->andReturn(['requestId' => 'fal-known']);
        $mock->shouldReceive('poll')->once()->andReturn(null);
        $mock->shouldReceive('poll')->once()->andReturn(['bytes' => $this->png(2048, 1024), 'requestId' => 'fal-known']);
        $service = app(LabService::class);
        $this->assertFalse($service->process($run));
        $this->assertFalse($service->process($run->fresh()));
        $this->assertTrue($service->process($run->fresh()));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_wrong_result_dimensions_are_not_stretched_into_a_panorama(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'])['id']);
        $bytes = $this->png(512, 512);
        $this->mock(LabProvider::class)->shouldReceive('submit')->once()->andReturn(['bytes' => $bytes, 'requestId' => 'req-rejected', 'usage' => ['output_tokens' => 5]]);
        app(LabService::class)->process($run);
        $this->assertSame('needs_attention', $run->fresh()->status);
        $run->refresh();
        $this->assertSame($bytes, Storage::disk(LabService::DISK)->get($run->output_path));
        $this->assertSame(512, $run->output_width);
        $this->assertSame(512, $run->output_height);
        $this->assertSame('req-rejected', $run->provider_request_id);
        $this->assertSame(['output_tokens' => 5], $run->usage);
        $this->assertSame(hash('sha256', $bytes), $run->options['diagnosticOutput']['sha256']);
        $this->withToken($this->token)->get('/api/iguide-lab/assets/run/'.$run->id)->assertNotFound();
    }

    public function test_openai_request_uses_exact_model_settings_and_records_prepared_input_hash(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'], ['modelId' => 'gpt-image-2.6-flare'])['id']);
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(2048, 1024))]], 'usage' => ['input_tokens' => 12, 'output_tokens' => 34]], 200, ['x-request-id' => 'req-api'])]);
        app(LabService::class)->process($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertCount(1, $run->fresh()->options['preparedInputSha256']);
        Http::assertSent(function ($request) {
            $fields = collect($request->data())->keyBy('name');
            return $request->url() === 'https://api.openai.com/v1/images/edits'
                && $fields['model']['contents'] === 'gpt-image-2.6-flare' && $fields['complexity']['contents'] === 'medium'
                && $fields['quality']['contents'] === 'high' && $fields['size']['contents'] === '2048x1024' && (int) $fields['n']['contents'] === 1;
        });
        Http::assertSentCount(1);
    }

    public function test_generated_references_must_be_completed_and_belong_to_this_capability(): void
    {
        $case = $this->upload();
        $anchor = IguideLabRun::find($this->start($case['id'])['id']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['options' => ['referenceRunIds' => [$anchor->id]]]))->assertNotFound();
        $path = 'iguide-lab/'.$this->project->id.'/runs/'.$anchor->id.'.png';
        Storage::disk(LabService::DISK)->put($path, $this->png(2048, 1024));
        $anchor->update(['status' => 'completed', 'output_path' => $path]);
        $followup = $this->start($case['id'], ['options' => ['referenceRunIds' => [$anchor->id]]]);
        $this->assertSame([$anchor->id], $followup['options']['referenceRunIds']);
        $other = str_repeat('b', 64);
        IguideLabProject::create(['name' => 'Other lab', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 2]);
        $own = $this->upload($other);
        $this->withToken($other)->postJson('/api/iguide-lab/runs', $this->input($own['id'], ['options' => ['referenceRunIds' => [$anchor->id]]]))->assertNotFound();
    }

    public function test_seedream_request_and_result_follow_the_existing_queue_without_resubmission(): void
    {
        $run = IguideLabRun::find($this->start($this->upload()['id'], ['modelId' => 'bytedance/seedream/v5/pro/edit'])['id']);
        Http::fake([
            'https://queue.fal.run/bytedance/seedream/v5/pro/edit' => Http::response(['request_id' => 'fal-123']),
            'https://queue.fal.run/bytedance/seedream/requests/fal-123/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/bytedance/seedream/requests/fal-123/response' => Http::response(['images' => [['url' => 'https://v3b.fal.media/result.png']]]),
            'https://v3b.fal.media/result.png' => Http::response($this->png(2048, 1024)),
        ]);
        $this->assertFalse(app(LabService::class)->process($run));
        $this->assertTrue(app(LabService::class)->process($run->fresh()));
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['num_images'] === 1 && $request['image_size']['width'] === 2048 && $request['enhance_prompt_mode'] === 'standard' && count($request['image_urls']) === 1);
        Http::assertSentCount(4);
    }

    public function test_selected_object_recipe_omits_generic_removal_and_complexity_reaches_provider(): void
    {
        $case = $this->upload();
        foreach (['low', 'high'] as $complexity) {
            $data = $this->start($case['id'], ['preset' => 'declutter', 'options' => ['complexity' => $complexity,
                'declutterMode' => 'selected-object', 'selectedObjects' => ['flower vase on island'], 'preserveObjects' => ['knife block', 'coffee appliance']]]);
            $this->assertStringNotContainsString('Remove loose clutter', $data['prompt']);
            foreach (['Remove only', 'flower vase on island', 'knife block', 'coffee appliance', 'every other object'] as $phrase) $this->assertStringContainsString($phrase, $data['prompt']);
            $this->assertSame(2, $data['options']['recipeVersion']);
            Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(2048, 1024))]]])]);
            app(LabService::class)->process(IguideLabRun::find($data['id']));
            Http::assertSent(fn ($request) => collect($request->data())->keyBy('name')['complexity']['contents'] === $complexity);
        }
        $generic = $this->start($case['id'], ['preset' => 'declutter']);
        $this->assertStringContainsString('Remove loose clutter', $generic['prompt']);
        $this->assertSame(1, $generic['options']['recipeVersion']);
        foreach ([['declutterMode' => 'selected-object'], ['selectedObjects' => ['vase']]] as $options) {
            $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['preset' => 'declutter', 'options' => $options]))->assertUnprocessable();
        }
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['modelId' => 'bytedance/seedream/v5/pro/edit', 'options' => ['complexity' => 'low']]))->assertUnprocessable();
    }

    public function test_explicit_default_v2_controls_replay_existing_v1_reservation(): void
    {
        $input = $this->input($this->upload()['id'], ['preset' => 'declutter']);
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->json('data');
        $input['options'] = ['complexity' => 'medium', 'declutterMode' => 'generic', 'selectedObjects' => [], 'preserveObjects' => []];
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        Queue::assertPushed(ProcessIguideLabRun::class, 1);
        $this->assertSame(1, $this->project->fresh()->used_runs);
    }

    public function test_read_traffic_and_write_traffic_have_independent_capability_limits(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        for ($i = 0; $i < 35; $i++) $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk();
        for ($i = 0; $i < 30; $i++) $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [])->assertUnprocessable();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', [])->assertStatus(429);
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk();
        $other = str_repeat('b', 64);
        IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 1]);
        $this->withToken($other)->postJson('/api/iguide-lab/runs', [])->assertUnprocessable();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_composite_is_separate_idempotent_private_and_does_not_consume_generation_quota(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $url = '/api/iguide-lab/cases/'.$case['id'].'/composites';
        $this->withHeader('Authorization', '')->post($url, $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertUnauthorized();
        $first = $this->withToken($this->token)->post($url, $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertFalse($first['singleProviderOutput']);
        $this->assertNull($first['latestReview']);
        $this->assertNull($first['native']);
        foreach (['modelId', 'provider', 'usage'] as $key) $this->assertArrayNotHasKey($key, $first);
        $this->withToken($this->token)->post($url, $this->compositeUpload(array_reverse($manifest, true), $png), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', $first['id']);
        $changed = $manifest; $changed['label'] = 'Changed';
        $this->withToken($this->token)->post($url, $this->compositeUpload($changed, $png), ['Accept' => 'application/json'])->assertConflict();
        $response = $this->withToken($this->token)->get($first['preview']['assetPath'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(hash('sha256', $png), hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
        $this->withToken($this->token)->get('/api/iguide-lab/assets/composite-native/'.$first['id'])->assertNotFound();
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->assertJsonCount(1, 'data.composites')->assertJsonPath('data.capabilities.maskUpload', false);
        $other = str_repeat('b', 64);
        IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 1]);
        $this->withToken($other)->get($first['preview']['assetPath'])->assertNotFound();
        $this->withToken($other)->getJson('/api/iguide-lab/composites/'.$first['id'])->assertNotFound();
        $this->withToken($other)->post($url, $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(1, $this->project->fresh()->used_runs);
        $this->assertSame(1, IguideLabRun::count());
        Queue::assertPushed(ProcessIguideLabRun::class, 1);
        Http::assertNothingSent();
    }

    public function test_composite_rejects_forged_lineage_hashes_projection_and_acceptance_claims(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $url = '/api/iguide-lab/cases/'.$case['id'].'/composites';
        $invalid = [];
        foreach (['preview.sha256', 'source.sourceSha256', 'source.originalSha256', 'children.0.outputSha256'] as $key) {
            $value = $manifest; data_set($value, $key, str_repeat('f', 64)); $invalid[] = $value;
        }
        $value = $manifest; $value['children'][0]['maskSha256'] = null; $invalid[] = $value;
        $value = $manifest; $value['children'][0]['projection'] = 'rectilinear'; $invalid[] = $value;
        $value = $manifest; $value['generatedDetail']['width'] = 4096; $invalid[] = $value;
        $value = $manifest; $value['accepted'] = true; $invalid[] = $value;
        $value = $manifest; $value['preset'] = 'staging'; $invalid[] = $value;
        $value = $manifest; $value['resolutionStrategy'] = 'native_canvas'; $value['native'] = $value['preview']; $invalid[] = $value;
        foreach ($invalid as $value) $this->withToken($this->token)->post($url, $this->compositeUpload($value, $png), ['Accept' => 'application/json'])->assertUnprocessable();
        $run->update(['status' => 'needs_attention']);
        $this->withToken($this->token)->post($url, $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(0, \App\Models\IguideLabComposite::count());
    }

    public function test_composite_reviews_are_scoped_append_only_and_require_explicit_quality_checks(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $record = $this->withToken($this->token)->post('/api/iguide-lab/cases/'.$case['id'].'/composites', $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $url = '/api/iguide-lab/composites/'.$record['id'].'/reviews';
        $input = $this->reviewInput();
        $this->withHeader('Authorization', '')->postJson($url, $input)->assertUnauthorized();
        $other = str_repeat('b', 64);
        IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 1]);
        $this->withToken($other)->postJson($url, $input)->assertNotFound();
        $invalid = $input; $invalid['checks']['seam'] = 'unreviewed';
        $this->withToken($this->token)->postJson($url, $invalid)->assertUnprocessable();
        $this->withToken($this->token)->postJson($url, [...$input, 'scope' => 'cross_view'])->assertUnprocessable();
        $first = $this->withToken($this->token)->postJson($url, $input)->assertCreated()->json('data');
        $this->withToken($this->token)->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->withToken($this->token)->postJson($url, [...$input, 'notes' => 'changed'])->assertConflict();
        $this->withToken($this->token)->postJson($url, [...$input, 'requestId' => (string) Str::uuid(), 'verdict' => 'needs_work'])->assertCreated();
        $this->withToken($this->token)->getJson('/api/iguide-lab/composites/'.$record['id'])->assertOk()->assertJsonPath('data.latestReview.verdict', 'needs_work');
        $this->assertSame(2, \App\Models\IguideLabCompositeReview::count());
        $this->assertSame(0, IguideLabReview::count());
    }

    public function test_native_canvas_operator_import_retains_full_bytes_and_truthful_generated_resolution(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $parent = \App\Models\IguideLabCase::find($case['id']);
        $metadata = $parent->metadata; $metadata['originalWidth'] = 2048; $metadata['originalHeight'] = 1024; $parent->update(['metadata' => $metadata]);
        $manifest['source']['originalWidth'] = 2048; $manifest['source']['originalHeight'] = 1024;
        $manifest['resolutionStrategy'] = 'native_canvas'; $manifest['native'] = $manifest['preview'];
        $file = tempnam(sys_get_temp_dir(), 'lab-native-'); file_put_contents($file, $png);
        try {
            [$record] = app(\App\Services\IguideLab\LabComposites::class)->add($this->project, $parent, $file, $manifest, $file);
            $dto = app(\App\Services\IguideLab\LabComposites::class)->dto($record);
            $this->assertSame(2048, $dto['manifest']['generatedDetail']['width']);
            $response = $this->withToken($this->token)->get($dto['native']['assetPath'])->assertOk();
            $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertSame(hash('sha256', $png), hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
            $other = str_repeat('b', 64);
            IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 1]);
            $this->withToken($other)->get($dto['native']['assetPath'])->assertNotFound();
        } finally { unlink($file); }
        Http::assertNothingSent();
    }

    public function test_perspective_composite_requires_matching_parent_and_projection_manifest(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $source = $this->withToken($this->token)->post('/api/iguide-lab/cases', [
            'image' => UploadedFile::fake()->image('section.png', 256, 256), 'label' => 'Kitchen section', 'projection' => 'rectilinear',
            'metadata' => json_encode(['parentCaseId' => $case['id'], 'includeInBatch' => false]),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $child = IguideLabRun::find($this->start($source['id'])['id']);
        $square = $this->png(2048, 2048); $path = 'iguide-lab/'.$this->project->id.'/runs/'.$child->id.'.png';
        Storage::disk(LabService::DISK)->put($path, $square);
        $child->update(['status' => 'completed', 'output_path' => $path, 'output_width' => 2048, 'output_height' => 2048]);
        $manifest['compositionMode'] = 'perspective_sections';
        $manifest['generatedDetail']['height'] = 2048;
        $manifest['children'][0] = ['runId' => $child->id, 'requestId' => $child->request_id, 'outputSha256' => hash('sha256', $square),
            'projection' => 'rectilinear', 'editScope' => 'masked', 'maskSha256' => str_repeat('d', 64),
            'view' => ['yaw' => 20, 'pitch' => -15, 'hFov' => 100, 'vFov' => 100, 'coordinateConvention' => 'degrees; pixel centers']];
        $url = '/api/iguide-lab/cases/'.$case['id'].'/composites';
        $this->withToken($this->token)->post($url, $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertUnprocessable();
        $manifest['geometryManifestSha256'] = str_repeat('b', 64);
        $this->withToken($this->token)->post($url, $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertCreated();
        \App\Models\IguideLabCase::find($source['id'])->update(['metadata' => []]);
        $manifest['requestId'] = (string) Str::uuid();
        $this->withToken($this->token)->post($url, $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_failed_native_storage_cleans_up_preview_without_creating_an_artifact(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $parent = \App\Models\IguideLabCase::find($case['id']);
        $metadata = $parent->metadata; $metadata['originalWidth'] = 2048; $metadata['originalHeight'] = 1024; $parent->update(['metadata' => $metadata]);
        $manifest['source']['originalWidth'] = 2048; $manifest['source']['originalHeight'] = 1024;
        $manifest['resolutionStrategy'] = 'native_canvas'; $manifest['native'] = $manifest['preview'];
        $file = tempnam(sys_get_temp_dir(), 'lab-storage-'); file_put_contents($file, $png);
        $disk = Storage::disk(LabService::DISK);
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->twice()->andReturnUsing(function ($path, $contents, $options) use ($disk) {
            if (str_ends_with($path, '-native.png')) throw new \RuntimeException('Fixture disk failure');
            return $disk->put($path, $contents, $options);
        });
        Storage::shouldReceive('disk')->with(LabService::DISK)->andReturn($proxy);
        try {
            app(\App\Services\IguideLab\LabComposites::class)->add($this->project, $parent, $file, $manifest, $file);
            $this->fail('Storage failure must not report success.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fixture disk failure', $exception->getMessage());
        } finally { unlink($file); }
        $this->assertSame([], $disk->allFiles('iguide-lab/'.$this->project->id.'/composites'));
        $this->assertSame(0, \App\Models\IguideLabComposite::count());
        $this->assertSame(1, $this->project->fresh()->used_runs);
    }

    public function test_exact_25_models_omit_complexity_in_saved_options_and_actual_multipart_request(): void
    {
        $case = $this->upload();
        foreach (['gpt-image-2.5-sunburst', 'gpt-image-2.5-flare'] as $model) {
            $data = $this->start($case['id'], ['modelId' => $model]);
            $this->assertArrayNotHasKey('complexity', $data['options']); $this->assertSame(3, $data['options']['recipeVersion']);
            Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(2048, 1024))]]])]);
            app(LabService::class)->process(IguideLabRun::find($data['id']));
            Http::assertSent(function ($request) use ($model) {
                $fields = collect($request->data())->keyBy('name');
                return $fields['model']['contents'] === $model && ! $fields->has('complexity') && $fields['quality']['contents'] === 'high';
            });
            $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['modelId' => $model, 'options' => ['complexity' => 'medium']]))->assertUnprocessable();
        }
        $models = collect($this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->json('data.models'))->keyBy('id');
        $this->assertFalse($models['gpt-image-2.5-sunburst']['capabilities']['complexity']['supported']);
        $this->assertTrue($models['gpt-image-2.6-sunburst']['capabilities']['complexity']['supported']);
    }

    public function test_empty_composite_references_preserve_existing_26_and_fal_request_identity(): void
    {
        foreach (['gpt-image-2.6-sunburst', 'bytedance/seedream/v5/pro/edit'] as $model) {
            $input = $this->input($this->upload()['id'], ['modelId' => $model]);
            $first = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->json('data');
            $this->assertSame(1, $first['options']['recipeVersion']); $this->assertArrayNotHasKey('referenceCompositeIds', $first['options']);
            $input['options'] = ['referenceCompositeIds' => []];
            $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        }
        $this->assertSame(2, $this->project->fresh()->used_runs);
    }

    public function test_accepted_composite_reference_snapshots_provenance_and_checks_latest_review_before_paid_submit(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $record = $this->withToken($this->token)->post('/api/iguide-lab/cases/'.$case['id'].'/composites', $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $input = $this->input($case['id'], ['options' => ['referenceCompositeIds' => [$record['id']]]]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertUnprocessable();
        $reviewUrl = '/api/iguide-lab/composites/'.$record['id'].'/reviews';
        $review = $this->withToken($this->token)->postJson($reviewUrl, $this->reviewInput())->assertCreated()->json('data');
        $data = $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->json('data');
        $snapshot = $data['options']['referenceCompositeProvenance'][0];
        $this->assertSame($review['id'], $snapshot['reviewId']); $this->assertSame(hash('sha256', $png), $snapshot['sha256']);
        $this->assertSame('accepted_composite_reference', $snapshot['role']);
        $this->withToken($this->token)->postJson($reviewUrl, $this->reviewInput())->assertCreated();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->assertJsonPath('data.id', $data['id']);
        $this->withToken($this->token)->postJson($reviewUrl, [...$this->reviewInput(), 'verdict' => 'needs_work'])->assertCreated();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $input)->assertAccepted()->assertJsonPath('data.id', $data['id']);
        app(LabService::class)->process(IguideLabRun::find($data['id']));
        $this->assertSame('needs_attention', IguideLabRun::find($data['id'])->status); Http::assertNothingSent();
        $this->withToken($this->token)->postJson($reviewUrl, $this->reviewInput())->assertCreated();
        $next = $this->start($case['id'], ['options' => ['referenceCompositeIds' => [$record['id']]]]);
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($png)]]])]);
        app(LabService::class)->process(IguideLabRun::find($next['id']));
        Http::assertSent(fn ($request) => collect($request->data())->where('name', 'image[]')->count() === 2);
        $this->assertNotEmpty(IguideLabRun::find($next['id'])->options['referenceCompositeProvenance'][0]['submittedReviewId']);
        $other = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 3]);
        $foreign = $this->upload($other);
        $this->withToken($other)->postJson('/api/iguide-lab/runs', $this->input($foreign['id'], ['options' => ['referenceCompositeIds' => [$record['id']]]]))->assertNotFound();
        $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($case['id'], ['options' => ['referenceCompositeIds' => [$record['id']], 'referenceCaseIds' => [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()]]]))->assertUnprocessable();
    }

    public function test_display_corrections_are_bounded_append_only_and_do_not_change_manifest_hash_pixels_or_review(): void
    {
        [$case, $run, $manifest, $png] = $this->compositeFixture();
        $record = $this->withToken($this->token)->post('/api/iguide-lab/cases/'.$case['id'].'/composites', $this->compositeUpload($manifest, $png), ['Accept' => 'application/json'])->assertCreated()->json('data');
        $original = \App\Models\IguideLabComposite::find($record['id']);
        $input = ['requestId' => (string) Str::uuid(), 'label' => 'Green lawn · protected property details', 'generatedDetailDescription' => '2048×1024 preview', 'reason' => 'Correct transport encoding.'];
        $url = '/api/iguide-lab/composites/'.$record['id'].'/display-metadata';
        $this->withHeader('Authorization', '')->postJson($url, $input)->assertUnauthorized();
        $saved = $this->withToken($this->token)->postJson($url, $input)->assertCreated()->json('data');
        $this->withToken($this->token)->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $saved['id']);
        $this->withToken($this->token)->postJson($url, [...$input, 'label' => 'Changed'])->assertConflict();
        $this->withToken($this->token)->postJson($url, [...$input, 'accepted' => true])->assertUnprocessable();
        $other = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 3]);
        $this->withToken($other)->postJson($url, $input)->assertNotFound();
        $dto = $this->withToken($this->token)->getJson('/api/iguide-lab/composites/'.$record['id'])->assertOk()->json('data');
        $this->assertSame($input['label'], $dto['displayMetadata']['label']); $this->assertSame($original->label, $dto['label']);
        $this->assertSame($original->manifest, $original->fresh()->manifest); $this->assertSame($original->request_hash, $original->fresh()->request_hash);
        $this->assertNull($dto['latestReview']); $this->assertSame(1, \App\Models\IguideLabDisplayMetadata::count());
        $this->assertSame(1, \App\Models\IguideLabComposite::count()); $this->assertSame(1, $this->project->fresh()->used_runs);
    }

    private function compositeFixture(): array
    {
        $case = $this->upload();
        $parent = \App\Models\IguideLabCase::find($case['id']);
        $parent->update(['metadata' => $parent->metadata + ['originalSha256' => str_repeat('e', 64)]]);
        $run = IguideLabRun::find($this->start($case['id'])['id']);
        $png = $this->png(2048, 1024);
        $path = 'iguide-lab/'.$this->project->id.'/runs/'.$run->id.'.png';
        Storage::disk(LabService::DISK)->put($path, $png);
        $run->update(['status' => 'completed', 'output_path' => $path, 'output_width' => 2048, 'output_height' => 1024]);
        $hash = hash('sha256', $png);
        $manifest = ['schemaVersion' => 1, 'requestId' => (string) Str::uuid(), 'label' => 'Grass composition', 'kind' => 'locally_composited_panorama',
            'preset' => 'grass', 'style' => null,
            'projection' => 'equirectangular', 'resolutionStrategy' => 'preview_only', 'compositionMode' => 'masked_whole_panorama',
            'source' => ['caseId' => $case['id'], 'sourceSha256' => $case['sourceSha256'], 'originalSha256' => str_repeat('e', 64), 'originalWidth' => 11008, 'originalHeight' => 5504],
            'preview' => ['sha256' => $hash, 'width' => 2048, 'height' => 1024], 'generatedDetail' => ['width' => 2048, 'height' => 1024, 'description' => 'Preview generated detail; original pixels outside mask.'],
            'recipeSha256' => str_repeat('c', 64), 'children' => [['runId' => $run->id, 'requestId' => $run->request_id, 'outputSha256' => $hash,
                'projection' => 'equirectangular', 'editScope' => 'masked', 'maskSha256' => str_repeat('d', 64)]]];
        return [$case, $run, $manifest, $png];
    }

    private function compositeUpload(array $manifest, string $png): array
    {
        return ['preview' => UploadedFile::fake()->createWithContent('preview.png', $png), 'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)];
    }

    private function upload(?string $token = null): array
    {
        return $this->withToken($token ?? $this->token)->post('/api/iguide-lab/cases', [
            'image' => UploadedFile::fake()->image('source.jpg', 256, 128), 'label' => 'Living room', 'roomId' => 'room-1', 'panoId' => 'p1',
            'projection' => 'equirectangular', 'metadata' => json_encode(['originalWidth' => 11008, 'originalHeight' => 5504, 'provenance' => 'iGUIDE R1']),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    private function input(string $caseId, array $overrides = []): array
    {
        return array_replace(['caseId' => $caseId, 'requestId' => (string) Str::uuid(), 'modelId' => 'gpt-image-2.6-sunburst', 'preset' => 'grass'], $overrides);
    }

    private function start(string $caseId, array $overrides = []): array
    {
        return $this->withToken($this->token)->postJson('/api/iguide-lab/runs', $this->input($caseId, $overrides))->assertAccepted()->json('data');
    }

    private function png(int $width, int $height): string
    {
        return (string) \Intervention\Image\ImageManager::gd()->create($width, $height)->fill('88aa88')->toPng();
    }
}
