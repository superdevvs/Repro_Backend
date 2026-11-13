<?php

namespace Tests\Feature;

use App\Exceptions\OpenAiImageException;
use App\Jobs\ProcessIguideLabRun;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
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

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'test-openai-key', 'services.fal.key' => 'test-fal-key']);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory('');
        $disk->setVisibility('', 'private');
        Queue::fake();
        Http::preventStrayRequests();
        $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Private lab', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDays(30), 'max_runs' => 60]);
    }

    public function test_capability_is_required_and_expired_or_revoked_capabilities_fail_closed(): void
    {
        $this->getJson('/api/iguide-lab')->assertUnauthorized();
        $this->postJson('/api/iguide-lab/runs', [])->assertUnauthorized();
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->assertJsonCount(3, 'data.models')->assertJsonPath('data.project.remainingRuns', 60);
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
        $this->mock(LabProvider::class)->shouldReceive('submit')->once()->andReturn(['bytes' => $this->png(512, 512)]);
        app(LabService::class)->process($run);
        $this->assertSame('needs_attention', $run->fresh()->status);
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
