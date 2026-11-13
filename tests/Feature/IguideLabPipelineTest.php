<?php

namespace Tests\Feature;

use App\Jobs\ProcessIguideLabPipeline;
use App\Jobs\ProcessIguideLabRun;
use App\Models\IguideLabCase;
use App\Models\IguideLabComposite;
use App\Models\IguideLabPipeline;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Services\IguideLab\LabCompositor;
use App\Services\IguideLab\LabImages;
use App\Services\IguideLab\LabPipelines;
use App\Services\IguideLab\LabSegmentation;
use App\Services\IguideLab\LabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private IguideLabProject $project;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = tempnam(sys_get_temp_dir(), 'lab-pipeline-key-'); file_put_contents($this->key, 'fixture-only-key');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.fal.key' => 'fixture-fal', 'iguide-lab.openai_primary_key_path' => $this->key]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory(''); $disk->setVisibility('', 'private');
        Queue::fake(); Http::preventStrayRequests();
        $this->token = str_repeat('a', 64);
        $this->project = IguideLabProject::create(['name' => 'Pipeline tests', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $this->mock(LabCompositor::class, function ($mock) { $mock->shouldReceive('ready')->andReturn(true); });
    }

    protected function tearDown(): void
    {
        if (is_file($this->key)) unlink($this->key);
        parent::tearDown();
    }

    public function test_reservation_replays_one_pipeline_and_scopes_all_routes_without_new_image_charge(): void
    {
        [$case, $run] = $this->fixture();
        $input = $this->input($case, $run);
        $this->withHeader('Authorization', '')->postJson('/api/iguide-lab/pipelines', $input)->assertUnauthorized();
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/pipelines', $input)->assertAccepted()->json('data');
        $this->withToken($this->token)->postJson('/api/iguide-lab/pipelines', $input)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        $changed = $input; unset($changed['existingRunId']); $changed['modelId'] = 'gpt-image-2.6-sunburst';
        $this->withToken($this->token)->postJson('/api/iguide-lab/pipelines', $changed)->assertConflict();
        $this->assertSame(['newImages' => 0, 'segmentations' => 1], $first['plannedCalls']);
        $this->assertSame(1, IguideLabPipeline::count()); $this->assertSame(1, $this->project->fresh()->used_runs);
        Queue::assertPushed(ProcessIguideLabPipeline::class, 1);
        $this->withToken($this->token)->getJson('/api/iguide-lab')->assertOk()->assertJsonCount(1, 'data.pipelines')->assertJsonPath('data.capabilities.pipelines.automaticAcceptance', false);
        $other = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $other), 'expires_at' => now()->addDay(), 'max_runs' => 1]);
        $this->withToken($other)->getJson('/api/iguide-lab/pipelines/'.$first['id'])->assertNotFound();
        $this->withToken($other)->get('/api/iguide-lab/assets/pipeline-alpha/'.$first['id'])->assertNotFound();
        $this->withToken($other)->postJson('/api/iguide-lab/pipelines', [...$input, 'requestId' => (string) Str::uuid()])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_known_segmentation_receipt_resumes_without_duplicate_post_and_publishes_only_unreviewed_composite(): void
    {
        [$case, $run, $png] = $this->fixture();
        $pipeline = $this->reserve($case, $run);
        $prepared = LabImages::prepared(Storage::disk(LabService::DISK)->get($case->source_path));
        $this->mock(LabSegmentation::class, function ($mock) use ($prepared, $png) {
            $mock->shouldReceive('submit')->once()->with($prepared)->andReturn('known-sam-receipt');
            $mock->shouldReceive('poll')->with('known-sam-receipt')->twice()->andReturn(null, ['masks' => [$png], 'metadata' => ['scores' => [0.9], 'boxes' => [[0,0,2048,1024]]]]);
        });
        $this->fakeCompositor($png);
        $service = app(LabPipelines::class);
        $this->assertFalse($service->process($pipeline));
        $this->assertSame('known-sam-receipt', $pipeline->fresh()->support_provider_request_id);
        $this->assertTrue($service->process($pipeline->fresh()));
        $this->assertTrue($service->process($pipeline->fresh()));
        $pipeline->refresh(); $this->assertSame('completed', $pipeline->status);
        $this->assertSame(1, IguideLabComposite::count());
        $dto = $service->dto($pipeline);
        $this->withToken($this->token)->getJson('/api/iguide-lab/composites/'.$dto['compositeId'])->assertOk()->assertJsonPath('data.latestReview', null)
            ->assertJsonPath('data.manifest.lineage.automaticAcceptance', false)->assertJsonPath('data.manifest.lineage.preservationVerifiedByWorker', true);
        $this->withToken($this->token)->get($dto['alphaAssetPath'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(hash('sha256', $prepared), $dto['sourcePreparedSha256']);
        $this->assertSame(1, $this->project->fresh()->used_runs); $this->assertNull($dto['support']['costUsd']);
        $body = json_encode($dto); foreach (['fixture-fal', $this->key, '/app/storage'] as $secret) $this->assertStringNotContainsString($secret, $body);
        Http::assertNothingSent();
    }

    public function test_ambiguous_segmentation_submission_is_never_replayed(): void
    {
        [$case, $run] = $this->fixture(); $pipeline = $this->reserve($case, $run);
        $this->mock(LabSegmentation::class, function ($mock) {
            $mock->shouldReceive('submit')->once()->andThrow(new \RuntimeException('lost response'));
            $mock->shouldNotReceive('poll');
        });
        $service = app(LabPipelines::class);
        $this->assertTrue($service->process($pipeline)); $this->assertTrue($service->process($pipeline->fresh()));
        $this->assertSame('needs_attention', $pipeline->fresh()->status); $this->assertSame('submitting', $pipeline->fresh()->support_status);
        $this->assertSame(0, IguideLabComposite::count());
    }

    public function test_crashed_submission_claim_and_mismatched_prepared_source_stop_before_any_paid_post(): void
    {
        [$case, $run] = $this->fixture(); $pipeline = $this->reserve($case, $run);
        $this->mock(LabSegmentation::class, function ($mock) { $mock->shouldNotReceive('submit'); $mock->shouldNotReceive('poll'); });
        $pipeline->update(['support_status' => 'submitting']);
        $this->assertTrue(app(LabPipelines::class)->process($pipeline));
        $this->assertSame('needs_attention', $pipeline->fresh()->status);
        $next = $this->reserve($case, $run); $options = $run->options; $options['preparedInputSha256'] = [str_repeat('f', 64)]; $run->update(['options' => $options]);
        $this->assertTrue(app(LabPipelines::class)->process($next)); $this->assertSame('needs_attention', $next->fresh()->status);
        $this->assertSame('pending', $next->fresh()->support_status); Http::assertNothingSent();
    }

    public function test_new_image_pipeline_reserves_and_reuses_one_child_run_before_segmentation(): void
    {
        [$case] = $this->fixture();
        $input = ['requestId' => (string) Str::uuid(), 'caseId' => $case->id, 'recipe' => LabPipelines::RECIPE, 'modelId' => 'gpt-image-2.6-sunburst', 'options' => ['complexity' => 'low']];
        $id = $this->withToken($this->token)->postJson('/api/iguide-lab/pipelines', $input)->assertAccepted()->json('data.id');
        $pipeline = IguideLabPipeline::find($id); $service = app(LabPipelines::class);
        $this->assertFalse($service->process($pipeline)); $this->assertFalse($service->process($pipeline->fresh()));
        $child = IguideLabRun::find($pipeline->fresh()->child_run_id);
        $this->assertSame($pipeline->data['editRequestId'], $child->request_id); $this->assertSame('low', $child->options['complexity']);
        $this->assertSame(2, $this->project->fresh()->used_runs); Queue::assertPushed(ProcessIguideLabRun::class, 2);
        Http::assertNothingSent();
    }

    public function test_compositor_failure_retains_support_receipt_and_does_not_create_or_accept_artifact(): void
    {
        [$case, $run, $png] = $this->fixture(); $pipeline = $this->reserve($case, $run);
        $this->mock(LabSegmentation::class, function ($mock) use ($png) {
            $mock->shouldReceive('submit')->once()->andReturn('known'); $mock->shouldReceive('poll')->once()->andReturn(['masks' => [$png], 'metadata' => []]);
        });
        $this->mock(LabCompositor::class, function ($mock) { $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('invalid pixels')); });
        $this->assertTrue(app(LabPipelines::class)->process($pipeline)); $pipeline->refresh();
        $this->assertSame('needs_attention', $pipeline->status); $this->assertSame('completed', $pipeline->support_status);
        $this->assertSame('known', $pipeline->support_provider_request_id); $this->assertCount(1, $pipeline->data['masks']);
        $this->assertSame(0, IguideLabComposite::count());
    }

    public function test_missing_corrupt_or_changed_child_stops_before_sam_or_composition(): void
    {
        [$case, $run, $png] = $this->fixture();
        $disk = Storage::disk(LabService::DISK);
        $this->mock(LabSegmentation::class, function ($mock) { $mock->shouldNotReceive('submit'); $mock->shouldNotReceive('poll'); });
        foreach ([null, 'not an image'] as $contents) {
            $pipeline = $this->reserve($case, $run);
            $contents === null ? $disk->delete($run->output_path) : $disk->put($run->output_path, $contents);
            $this->assertTrue(app(LabPipelines::class)->process($pipeline));
            $this->assertSame('needs_attention', $pipeline->fresh()->status); $this->assertSame('pending', $pipeline->fresh()->support_status);
            $disk->put($run->output_path, $png);
        }
        $pipeline = $this->reserve($case, $run);
        $data = $pipeline->data; $data['candidateSha256'] = str_repeat('f', 64);
        $pipeline->update(['data' => $data, 'support_status' => 'processing', 'support_provider_request_id' => 'known']);
        $this->assertTrue(app(LabPipelines::class)->process($pipeline)); $this->assertSame('needs_attention', $pipeline->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_definite_sam_failure_stops_immediately_but_transient_poll_can_resume_the_known_receipt(): void
    {
        [$case, $run] = $this->fixture(); $service = app(LabPipelines::class);
        foreach ([new \App\Exceptions\FalTerminalException(422), new \App\Exceptions\IguideLabSegmentationException('FAILED')] as $failure) {
            $pipeline = $this->reserve($case, $run);
            $this->mock(LabSegmentation::class, function ($mock) use ($failure) {
                $mock->shouldReceive('submit')->once()->andReturn('known'); $mock->shouldReceive('poll')->once()->andThrow($failure);
            });
            $this->assertTrue($service->process($pipeline)); $this->assertTrue($service->process($pipeline->fresh()));
            $this->assertSame('needs_attention', $pipeline->fresh()->status); $this->assertSame('failed', $pipeline->fresh()->support_status);
        }
        $pipeline = $this->reserve($case, $run);
        $this->mock(LabSegmentation::class, function ($mock) {
            $mock->shouldReceive('submit')->once()->andReturn('transient-known'); $mock->shouldReceive('poll')->twice()->andThrow(new \RuntimeException('temporary GET unavailable'));
        });
        $this->assertFalse($service->process($pipeline)); $this->assertFalse($service->process($pipeline->fresh()));
        $this->assertSame('processing', $pipeline->fresh()->support_status); $this->assertSame('transient-known', $pipeline->fresh()->support_provider_request_id);
        Http::assertNothingSent();
    }

    public function test_segmentation_uses_one_exact_model_request_and_downloads_bounded_mask_bytes_without_credentials(): void
    {
        $png = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('ffffff')->toPng();
        Http::fake([
            'https://queue.fal.run/fal-ai/sam-3-1/image' => Http::response(['request_id' => 'sam-123']),
            'https://queue.fal.run/fal-ai/sam-3-1/requests/sam-123/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/fal-ai/sam-3-1/requests/sam-123/response' => Http::response(['masks' => [['url' => 'https://v3.fal.media/mask.png']], 'scores' => [0.9]]),
            'https://v3.fal.media/mask.png' => Http::response($png),
        ]);
        $service = app(LabSegmentation::class); $id = $service->submit($png); $result = $service->poll($id);
        $this->assertSame([$png], $result['masks']); $this->assertSame([0.9], $result['metadata']['scores']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['prompt'] === 'grass lawn'
            && $request['image_url'] === 'data:image/png;base64,'.base64_encode($png) && $request['max_masks'] === 8 && $request['apply_mask'] === false);
        Http::assertSent(fn ($request) => $request->url() === 'https://v3.fal.media/mask.png' && ! $request->hasHeader('Authorization'));
        Http::assertSentCount(4);
    }

    public function test_segmentation_rejects_untrusted_mask_url_before_fetch(): void
    {
        $fal = $this->mock(\App\Services\FalService::class, function ($mock) {
            $mock->shouldReceive('modelStatus')->once()->andReturn('COMPLETED');
            $mock->shouldReceive('modelResult')->once()->andReturn(['masks' => [['url' => 'https://fal.media@127.0.0.1/secret']]]);
        });
        try { (new LabSegmentation($fal))->poll('known'); $this->fail('Untrusted mask URL must fail.'); }
        catch (\RuntimeException $e) { $this->assertSame('Unexpected segmentation asset host.', $e->getMessage()); }
        Http::assertNothingSent();
    }

    private function reserve(IguideLabCase $case, IguideLabRun $run): IguideLabPipeline
    {
        $id = $this->withToken($this->token)->postJson('/api/iguide-lab/pipelines', $this->input($case, $run))->assertAccepted()->json('data.id');
        return IguideLabPipeline::find($id);
    }

    private function input(IguideLabCase $case, IguideLabRun $run): array
    {
        return ['requestId' => (string) Str::uuid(), 'caseId' => $case->id, 'recipe' => LabPipelines::RECIPE, 'existingRunId' => $run->id];
    }

    private function fixture(): array
    {
        $png = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('88aa88')->toPng();
        $case = app(LabService::class)->addCase($this->project, UploadedFile::fake()->createWithContent('source.png', $png), ['label' => 'Lawn', 'projection' => 'equirectangular', 'metadata' => []]);
        $run = app(LabService::class)->start($this->project, ['caseId' => $case->id, 'requestId' => (string) Str::uuid(), 'modelId' => 'gpt-image-2.6-sunburst', 'preset' => 'grass']);
        $path = 'iguide-lab/'.$this->project->id.'/runs/'.$run->id.'.png'; Storage::disk(LabService::DISK)->put($path, $png);
        $options = $run->options; $options['preparedInputSha256'] = [hash('sha256', LabImages::prepared($png))];
        $run->update(['status' => 'completed', 'output_path' => $path, 'output_width' => 2048, 'output_height' => 1024, 'options' => $options]);
        return [$case, $run, $png];
    }

    private function fakeCompositor(string $png): void
    {
        $this->mock(LabCompositor::class, function ($mock) use ($png) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('run')->once()->andReturnUsing(function ($path) use ($png) {
                $request = json_decode(file_get_contents($path), true); $dir = $request['outputDirectory'];
                file_put_contents($dir.'/preview.png', $png); file_put_contents($dir.'/applied-alpha.png', $png);
                return ['preview' => ['path' => $dir.'/preview.png', 'sha256' => hash('sha256', $png)],
                    'alpha' => ['path' => $dir.'/applied-alpha.png', 'sha256' => hash('sha256', $png)], 'scriptSha256' => str_repeat('a', 64),
                    'diagnostics' => ['Review boundaries'], 'statistics' => ['outsideAlphaMaxChannelDifference' => 0]];
            });
        });
    }
}
