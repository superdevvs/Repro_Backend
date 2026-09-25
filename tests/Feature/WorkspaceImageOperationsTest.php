<?php

namespace Tests\Feature;

use App\Exceptions\FalTerminalException;
use App\Exceptions\OpenAiImageException;
use App\Exceptions\StudioProviderException;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\Studio\Providers\OpenAiImageProvider;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\WorkspaceImageOperations;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkspaceImageOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.autoenhance.api_key' => 'auto-fixture', 'studio_uploads.disk' => 'public', 'services.fal.key' => 'fal-fixture', 'services.openai.api_key' => 'openai-fixture',
            'services.fal.image_model' => 'fal-ai/flux-kontext/dev', 'services.fal.outpaint_model' => 'fal-ai/flux-2-pro/outpaint',
            'services.fal.video_poll_timeout' => 0]);
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldReceive('run')->andReturnUsing(fn ($w, $op, $item, $bytes, $service) => $bytes)->byDefault();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 100]]));
    }

    public function test_enqueued_fal_route_is_frozen_despite_later_provider_settings_changes(): void
    {
        $settings = app(StudioProviderSettings::class);
        $settings->save(['services' => [['id' => 'twilight', 'provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit']]]);
        $workspace = $this->draft();
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertAccepted();
        $workspace->refresh();
        $settings->save(['services' => [['id' => 'twilight', 'provider' => 'openai', 'model' => 'gpt-image-2']]]);
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitModel')->with('fal-ai/nano-banana-pro/edit', \Mockery::type('array'))->once()->andReturn('saved-fal');
        $fal->shouldReceive('modelStatus')->with('fal-ai/nano-banana-pro/edit', 'saved-fal')->once()->andReturn('COMPLETED');
        $fal->shouldReceive('modelImageResult')->with('fal-ai/nano-banana-pro/edit', 'saved-fal')->once()->andReturn($this->dataImage());
        $this->mock(OpenAiImageProvider::class)->shouldNotReceive('edit');
        $result = app(WorkspaceImageOperations::class)->edit($workspace, $workspace->operation['id'], ['id' => 'm1'], $this->image(), 'Correct colors');
        $this->assertNotFalse(getimagesizefromstring($result));
        $this->assertSame('fal-ai/nano-banana-pro/edit', $workspace->fresh()->operation['routing']['twilight']['model']);
        $this->assertSame('saved-fal', $workspace->fresh()->operation['requests']['m1']);
        $body = $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('saved-fal', $body);
        $this->assertStringNotContainsString('nano-banana', $body);
    }

    public function test_openai_route_snapshot_forwards_references_and_reuses_the_saved_bytes_once(): void
    {
        $settings = app(StudioProviderSettings::class);
        $settings->save(['services' => [['id' => 'twilight', 'provider' => 'openai', 'model' => 'gpt-image-2']]]);
        $workspace = $this->draft();
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertAccepted();
        $workspace->refresh();
        $settings->save(['services' => [['id' => 'twilight', 'provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit']]]);
        $source = $this->image();
        $references = [$this->image(320, 240, [0, 0, 200])];
        $expected = $this->image(480, 320, [0, 200, 0]);
        $this->mock(OpenAiImageProvider::class)->shouldReceive('edit')->once()->with($source, 'Match the reference', ['model' => 'gpt-image-2', 'reference_images' => $references])->andReturn($expected);
        $this->mock(FalService::class)->shouldNotReceive('submitModel');
        $operations = app(WorkspaceImageOperations::class);
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame($expected, $operations->edit($workspace, $workspace->operation['id'], ['id' => 'm1'], $source, 'Match the reference', $references));
        }
        $state = $workspace->fresh()->operation['providerState']['openai-'.hash('sha256', 'm1')];
        $this->assertSame($expected, Storage::disk('local')->get($state['path']));
        $this->assertStringNotContainsString('providerState', json_encode($workspace->fresh()->present()));
    }

    #[DataProvider('safeFallbackStatuses')]
    public function test_only_definite_account_submission_rejections_with_no_accepted_request_fall_back(int $status): void
    {
        $workspace = $this->active(['outpaint' => $this->outpaintRoute()], 'prepare');
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitModel')->once()->andThrow(new FalTerminalException($status));
        $fal->shouldNotReceive('modelStatus');
        $openai = $this->mock(OpenAiImageProvider::class);
        $openai->shouldReceive('configured')->once()->andReturnTrue();
        $openai->shouldReceive('outpaint')->once()->andReturn($this->image(900, 1600));
        $operations = app(WorkspaceImageOperations::class);
        for ($i = 0; $i < 2; $i++) {
            $bytes = $operations->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
            $this->assertSame([900, 1600], array_slice(getimagesizefromstring($bytes), 0, 2));
        }
        $state = $workspace->fresh()->operation;
        $this->assertTrue($state['providerState']['fallback-m1']);
        $this->assertArrayNotHasKey('m1', $state['requests']);
        $this->assertArrayNotHasKey('fal-submitting-m1', $state['providerState']);
    }

    public static function safeFallbackStatuses(): array
    {
        return [[401], [402]];
    }

    public function test_absent_fal_configuration_falls_back_without_submitting(): void
    {
        config(['services.fal.key' => null]);
        $workspace = $this->active(['outpaint' => $this->outpaintRoute()], 'prepare');
        $this->mock(FalService::class)->shouldNotReceive('submitModel');
        $openai = $this->mock(OpenAiImageProvider::class);
        $openai->shouldReceive('configured')->once()->andReturnTrue();
        $openai->shouldReceive('outpaint')->once()->andReturn($this->image());
        app(WorkspaceImageOperations::class)->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
        $this->assertTrue($workspace->fresh()->operation['providerState']['fallback-m1']);
    }

    #[DataProvider('unsafeFallbackStatuses')]
    public function test_accepted_requests_do_not_fall_back_after_account_or_payload_result_errors(int $status, bool $keepsRequest): void
    {
        $workspace = $this->active(['outpaint' => $this->outpaintRoute()], 'prepare', ['m1' => 'accepted-paid-request']);
        $fal = $this->mock(FalService::class);
        $fal->shouldNotReceive('submitModel');
        $fal->shouldReceive('modelStatus')->once()->andReturn('COMPLETED');
        $fal->shouldReceive('modelImageResult')->once()->andThrow(new FalTerminalException($status));
        $this->mock(OpenAiImageProvider::class)->shouldNotReceive('configured', 'outpaint');
        try {
            app(WorkspaceImageOperations::class)->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
            $this->fail('Expected original provider error.');
        } catch (FalTerminalException $exception) {
            $this->assertSame($status, $exception->httpStatus);
        }
        $state = $workspace->fresh()->operation;
        $this->assertSame($keepsRequest, isset($state['requests']['m1']));
        $this->assertFalse($state['providerState']['fallback-m1'] ?? false);
    }

    public static function unsafeFallbackStatuses(): array
    {
        return [[401, true], [402, true], [422, false]];
    }

    public function test_submission_422_does_not_use_fallback_or_leave_an_ambiguous_marker(): void
    {
        $workspace = $this->active(['outpaint' => $this->outpaintRoute()], 'prepare');
        $this->mock(FalService::class)->shouldReceive('submitModel')->once()->andThrow(new FalTerminalException(422));
        $this->mock(OpenAiImageProvider::class)->shouldNotReceive('configured', 'outpaint');
        try {
            app(WorkspaceImageOperations::class)->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
            $this->fail('Expected payload rejection.');
        } catch (FalTerminalException $exception) {
            $this->assertSame(422, $exception->httpStatus);
        }
        $this->assertEmpty($workspace->fresh()->operation['providerState'] ?? []);
    }

    public function test_ambiguous_submission_does_not_fall_back_or_submit_again_on_resume(): void
    {
        $workspace = $this->active(['outpaint' => $this->outpaintRoute()], 'prepare');
        $this->mock(FalService::class)->shouldReceive('submitModel')->once()->andThrow(new RuntimeException('Provider connection could not be confirmed.'));
        $this->mock(OpenAiImageProvider::class)->shouldNotReceive('configured', 'outpaint');
        $operations = app(WorkspaceImageOperations::class);
        try {
            $operations->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
            $this->fail('Expected transport failure.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(FalTerminalException::class, $exception);
        }
        try {
            $operations->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
            $this->fail('Expected recovery guard.');
        } catch (StudioProviderException $exception) {
            $this->assertTrue($exception->ambiguous);
        }
        $this->assertTrue($workspace->fresh()->operation['providerState']['fal-submitting-m1']);
    }

    public function test_still_processing_request_keeps_its_id_and_has_no_fallback(): void
    {
        $workspace = $this->active(['outpaint' => $this->outpaintRoute()], 'prepare', ['m1' => 'in-flight']);
        $fal = $this->mock(FalService::class);
        $fal->shouldNotReceive('submitModel', 'modelImageResult');
        $fal->shouldReceive('modelStatus')->once()->andReturn('IN_PROGRESS');
        $this->mock(OpenAiImageProvider::class)->shouldNotReceive('configured', 'outpaint');
        try {
            app(WorkspaceImageOperations::class)->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
            $this->fail('Expected polling timeout.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('still processing', $exception->getMessage());
        }
        $this->assertSame('in-flight', $workspace->fresh()->operation['requests']['m1']);
    }

    public function test_removing_a_key_does_not_enable_fallback_after_an_ambiguous_submission(): void
    {
        config(['services.fal.key' => null]);
        $workspace = $this->active(['outpaint' => $this->outpaintRoute()], 'prepare');
        $operation = $workspace->operation;
        $operation['providerState']['fal-submitting-m1'] = true;
        $workspace->update(['operation' => $operation]);
        $this->mock(FalService::class)->shouldNotReceive('submitModel');
        $openai = $this->mock(OpenAiImageProvider::class);
        $openai->shouldReceive('configured')->zeroOrMoreTimes()->andReturnTrue();
        $openai->shouldNotReceive('outpaint');
        try {
            app(WorkspaceImageOperations::class)->outpaint($workspace, 'operation-one', 'm1', $this->image(), '9:16', []);
            $this->fail('Expected the ambiguous submission guard.');
        } catch (StudioProviderException $exception) {
            $this->assertTrue($exception->ambiguous);
        }
        $this->assertFalse($workspace->fresh()->operation['providerState']['fallback-m1'] ?? false);
    }

    public function test_ambiguous_openai_attempt_is_not_submitted_twice_but_terminal_failure_clears_marker(): void
    {
        $route = ['provider' => 'openai', 'model' => 'gpt-image-2', 'fallback' => null];
        $workspace = $this->active(['twilight' => $route]);
        $openai = $this->mock(OpenAiImageProvider::class);
        $openai->shouldReceive('edit')->once()->andThrow(new OpenAiImageException(ambiguous: true, reason: 'transport'));
        $operations = app(WorkspaceImageOperations::class);
        try {
            $operations->edit($workspace, 'operation-one', ['id' => 'm1'], $this->image(), 'Edit');
            $this->fail('Expected ambiguous request.');
        } catch (OpenAiImageException $exception) {
            $this->assertTrue($exception->ambiguous);
        }
        try {
            $operations->edit($workspace, 'operation-one', ['id' => 'm1'], $this->image(), 'Edit');
            $this->fail('Expected recovery guard.');
        } catch (StudioProviderException $exception) {
            $this->assertTrue($exception->ambiguous);
        }
        $other = $this->active(['twilight' => $route]);
        $openai->shouldReceive('edit')->once()->andThrow(new OpenAiImageException(400, reason: 'moderation_blocked'));
        try {
            $operations->edit($other, 'operation-one', ['id' => 'm1'], $this->image(), 'Edit');
            $this->fail('Expected rejection.');
        } catch (OpenAiImageException $exception) {
            $this->assertSame('moderation_blocked', $exception->reason);
        }
        $this->assertEmpty($other->fresh()->operation['providerState'] ?? []);
    }

    public function test_nano_reference_payload_contains_source_then_references_and_high_quality_options(): void
    {
        $workspace = $this->active(['revision' => ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit', 'fallback' => null]], 'revision');
        $source = $this->image();
        $references = [$this->image(200, 200, [0, 0, 200]), $this->image(200, 200, [0, 200, 0])];
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitModel')->once()->with('fal-ai/nano-banana-pro/edit', \Mockery::on(function (array $payload) use ($source, $references): bool {
            $this->assertSame([$source, ...$references], array_map(fn ($uri) => base64_decode(explode(',', $uri, 2)[1]), $payload['image_urls']));
            $this->assertSame('2K', $payload['resolution']);
            $this->assertSame('auto', $payload['aspect_ratio']);
            $this->assertSame('png', $payload['output_format']);
            $this->assertSame(1, $payload['num_images']);
            $this->assertTrue($payload['limit_generations']);

            return true;
        }))->andReturn('nano-reference');
        $fal->shouldReceive('modelStatus')->once()->andReturn('COMPLETED');
        $fal->shouldReceive('modelImageResult')->once()->andReturn($this->dataImage());
        app(WorkspaceImageOperations::class)->edit($workspace, 'operation-one', ['id' => 'm1'], $source, 'Match the staging reference', $references);
    }

    public function test_default_flux_references_are_rejected_before_any_provider_submission(): void
    {
        $workspace = $this->active([]);
        $this->mock(FalService::class)->shouldNotReceive('submitModel', 'submitImageEditFromBuffer');
        $this->mock(OpenAiImageProvider::class)->shouldNotReceive('edit');
        $this->expectException(StudioProviderException::class);
        $this->expectExceptionMessage('Reference photos are not supported');
        app(WorkspaceImageOperations::class)->edit($workspace, 'operation-one', ['id' => 'm1'], $this->image(), 'Match it', [$this->image()]);
    }

    public function test_upscale_reads_the_explicit_older_output_and_uses_autoenhance(): void
    {
        $workspace = $this->draft();
        $old = $this->image(480, 320, [200, 0, 0]);
        $latest = $this->image(480, 320, [0, 0, 200]);
        $outputs = [];
        foreach ([1 => $old, 2 => $latest] as $version => $bytes) {
            $path = 'studio/workspaces/'.$workspace->id.'/version-'.$version.'.jpg';
            Storage::disk('public')->put($path, $bytes);
            $outputs[] = ['id' => 'v'.$version, 'mediaId' => 'm1', 'path' => $path, 'url' => Storage::disk('public')->url($path), 'kind' => 'image', 'status' => 'completed', 'version' => $version];
        }
        $workspace->update(['outputs' => $outputs, 'status' => 'completed']);
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/upscale', ['mediaId' => 'm1', 'outputId' => 'v1'])->assertAccepted();
        $workspace->refresh();
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldReceive('run')->once()->with(\Mockery::type(StudioWorkspace::class), $workspace->operation['id'], ['id' => 'm1'], $old, 'upscale')->andReturn($this->image(960, 640));
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
        $workspace->refresh();
        $this->assertSame('completed', $workspace->status);
        $this->assertCount(3, $workspace->outputs);
        $this->assertSame($outputs[0], $workspace->outputs[0]);
        $this->assertSame($outputs[1], $workspace->outputs[1]);
        $this->assertSame(3, $workspace->outputs[2]['version']);
        $this->assertSame([960, 640], array_slice(getimagesizefromstring(Storage::disk('public')->get($workspace->outputs[2]['path'])), 0, 2));
    }

    public function test_upscale_output_must_belong_to_the_selected_media_and_live_workspace(): void
    {
        $workspace = $this->draft();
        $workspace->update(['outputs' => [['id' => 'other', 'mediaId' => 'm2', 'kind' => 'image', 'status' => 'completed', 'version' => 1]], 'status' => 'completed']);
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/upscale', ['mediaId' => 'm1', 'outputId' => 'other'])->assertUnprocessable();
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/upscale', ['mediaId' => 'm1', 'outputId' => 'missing'])->assertUnprocessable();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function draft(): StudioWorkspace
    {
        $ref = 'studio/uploads/100/'.auth()->id().'/'.\Illuminate\Support\Str::uuid().'.jpg';
        Storage::disk('public')->put($ref, $this->image());
        $response = $this->postJson('/api/studio/workspaces', ['name' => 'Provider fixture', 'presetId' => 'twilight', 'media' => [['id' => 'm1', 'mediaRef' => $ref]]])->assertCreated();

        return StudioWorkspace::findOrFail($response->json('data.id'));
    }

    private function active(array $routing, string $type = 'generate', array $requests = []): StudioWorkspace
    {
        $workspace = $this->draft();
        $workspace->update(['status' => $type === 'prepare' ? 'preparing' : 'generating', 'operation' => ['id' => 'operation-one', 'type' => $type, 'routing' => $routing, 'requests' => $requests, 'completed' => [], 'payload' => []]]);

        return $workspace;
    }

    private function outpaintRoute(): array
    {
        return ['provider' => 'fal', 'model' => 'fal-ai/flux-2-pro/outpaint', 'fallback' => ['provider' => 'openai', 'model' => 'gpt-image-2']];
    }

    private function dataImage(int $width = 480, int $height = 320): string
    {
        return 'data:image/jpeg;base64,'.base64_encode($this->image($width, $height));
    }

    private function image(int $width = 480, int $height = 320, array $rgb = [200, 0, 0]): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagejpeg($image, null, 96);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
