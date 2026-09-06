<?php

namespace Tests\Feature;

use App\Exceptions\FalTerminalException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class WorkspaceImagePreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['studio_uploads.disk' => 'public']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 100]]));
    }

    public function test_extend_bounds_the_whole_canvas_and_preserves_landscape_and_portrait_sources(): void
    {
        $submitted = [];
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitModel')->twice()->andReturnUsing(function ($model, $payload) use (&$submitted) {
            $input = base64_decode(explode(',', $payload['image_url'], 2)[1]);
            [$width, $height] = getimagesizefromstring($input);
            $canvasWidth = $width + $payload['expand_left'] + $payload['expand_right'];
            $canvasHeight = $height + $payload['expand_top'] + $payload['expand_bottom'];
            $this->assertLessThanOrEqual(2560, max($canvasWidth, $canvasHeight));
            $this->assertFalse($payload['auto_crop']);
            $submitted[] = [$width, $height, $canvasWidth, $canvasHeight];

            return 'request-'.count($submitted);
        });
        $fal->shouldReceive('modelStatus')->twice()->andReturn('COMPLETED');
        $fal->shouldReceive('modelImageResult')->twice()->andReturnUsing(function () use (&$submitted) {
            $canvas = end($submitted);

            return $this->dataImage($canvas[2], $canvas[3], [0, 150, 0]);
        });

        foreach ([[1600, 1067, '9:16', 900, 1600], [1067, 1600, '16:9', 1600, 900]] as [$width, $height, $ratio, $finalWidth, $finalHeight]) {
            $workspace = $this->workspace($width, $height, $ratio);
            $this->postJson('/api/studio/workspaces/'.$workspace->id.'/prepare')->assertAccepted();
            $this->process($workspace);
            $workspace->refresh();
            $this->assertSame('ready', $workspace->status);
            $result = getimagesizefromstring(Storage::disk('public')->get($workspace->prepared_frames[0]['path']));
            $this->assertSame([$finalWidth, $finalHeight], array_slice($result, 0, 2));
            $source = end($submitted);
            $this->assertEqualsWithDelta($width / $height, $source[0] / $source[1], 0.002);
        }
        $this->assertSame([1440, 960, 1440, 2560], $submitted[0]);
        $this->assertSame([960, 1440, 2560, 1440], $submitted[1]);
    }

    public function test_small_revision_crop_meets_provider_minimum_and_changes_only_its_original_region(): void
    {
        $workspace = $this->workspace(1000, 1000, '1:1');
        $dimensions = [];
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitImageEditFromBuffer')->once()->andReturnUsing(function ($bytes) use (&$dimensions) {
            $dimensions = array_slice(getimagesizefromstring($bytes), 0, 2);
            $this->assertSame(256, $dimensions[0]);
            $this->assertGreaterThanOrEqual(256, $dimensions[1]);
            $this->assertLessThanOrEqual(2048, max($dimensions));

            return ['request_id' => 'revision'];
        });
        $fal->shouldReceive('imageEditStatus')->once()->andReturn(['status' => 'completed']);
        $fal->shouldReceive('imageEditResult')->once()->andReturnUsing(function () use (&$dimensions) {
            return ['edited_image_url' => $this->dataImage($dimensions[0], $dimensions[1], [0, 200, 0])];
        });

        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/revisions', [
            'mediaId' => 'm1', 'prompt' => 'Paint the selected wall green',
            'region' => ['x' => 0.2, 'y' => 0.2, 'width' => 0.09, 'height' => 0.48],
        ])->assertAccepted();
        $this->process($workspace);
        $result = imagecreatefromstring(Storage::disk('public')->get($workspace->fresh()->outputs[0]['path']));
        $this->assertSame([1000, 1000], [imagesx($result), imagesy($result)]);
        $this->assertPixel($result, 100, 100, [0, 0, 200]);
        $this->assertPixel($result, 240, 440, [0, 200, 0]);
        $this->assertPixel($result, 310, 440, [0, 0, 200]);
        imagedestroy($result);
    }

    public function test_very_thin_photo_uses_temporary_padding_then_returns_only_the_photo(): void
    {
        $workspace = $this->workspace(5, 800, '1:1');
        $submitted = '';
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitImageEditFromBuffer')->once()->andReturnUsing(function ($bytes) use (&$submitted) {
            $submitted = $bytes;
            $this->assertSame([256, 2048], array_slice(getimagesizefromstring($bytes), 0, 2));

            return ['request_id' => 'thin'];
        });
        $fal->shouldReceive('imageEditStatus')->once()->andReturn(['status' => 'completed']);
        $fal->shouldReceive('imageEditResult')->once()->andReturnUsing(function () use (&$submitted) {
            return ['edited_image_url' => 'data:image/jpeg;base64,'.base64_encode($submitted)];
        });
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertAccepted();
        $this->process($workspace);
        $result = imagecreatefromstring(Storage::disk('public')->get($workspace->fresh()->outputs[0]['path']));
        $this->assertSame([13, 2048], [imagesx($result), imagesy($result)]);
        $this->assertPixel($result, 6, 1024, [0, 0, 200]);
        imagedestroy($result);
    }

    public function test_completed_queue_result_422_discards_only_failed_frame_request_and_retry_keeps_completed_work(): void
    {
        $workspace = $this->workspace(1600, 1067, '9:16', 2);
        $fal = $this->mock(FalService::class);
        $model = config('services.fal.outpaint_model');
        $fal->shouldReceive('submitModel')->times(3)->andReturn('first', 'failed', 'replacement');
        $fal->shouldReceive('modelStatus')->times(3)->andReturn('COMPLETED');
        $fal->shouldReceive('modelImageResult')->with($model, 'first')->once()->andReturn($this->dataImage(90, 160, [0, 150, 0]));
        $fal->shouldReceive('modelImageResult')->with($model, 'failed')->once()->andThrow(new FalTerminalException(422));
        $fal->shouldReceive('modelImageResult')->with($model, 'replacement')->once()->andReturn($this->dataImage(90, 160, [0, 150, 0]));
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/prepare')->assertAccepted();
        try {
            $this->process($workspace);
            $this->fail('Expected the completed provider request to report its failed result.');
        } catch (FalTerminalException $exception) {
            $this->assertSame(422, $exception->httpStatus);
            $workspace->refresh();
            $this->assertSame(['m1'], $workspace->operation['completed']);
            $this->assertArrayNotHasKey('m2', $workspace->operation['requests']);
            (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->failed($exception);
        }
        $firstPath = $workspace->fresh()->prepared_frames[0]['path'];
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/prepare')->assertAccepted();
        $this->process($workspace);
        $workspace->refresh();
        $this->assertSame('ready', $workspace->status);
        $this->assertCount(2, $workspace->prepared_frames);
        $this->assertSame($firstPath, $workspace->prepared_frames[0]['path']);
        $this->assertSame('replacement', $workspace->operation['requests']['m2']);
    }

    public function test_account_errors_and_transient_result_failures_keep_existing_provider_requests(): void
    {
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitModel')->twice()->andReturn('account-blocked', 'temporary');
        $fal->shouldReceive('modelStatus')->twice()->andReturn('COMPLETED');
        $model = config('services.fal.outpaint_model');
        $fal->shouldReceive('modelImageResult')->with($model, 'account-blocked')->once()->andThrow(new FalTerminalException(402));
        $fal->shouldReceive('modelImageResult')->with($model, 'temporary')->once()->andThrow(new RuntimeException('Connection timed out'));
        foreach (['account-blocked', 'temporary'] as $requestId) {
            $workspace = $this->workspace(1600, 1067, '9:16');
            $this->postJson('/api/studio/workspaces/'.$workspace->id.'/prepare')->assertAccepted();
            try {
                $this->process($workspace);
                $this->fail('Expected the provider result failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame($requestId, $workspace->fresh()->operation['requests']['m1']);
            }
        }
    }

    private function workspace(int $width, int $height, string $ratio, int $count = 1): StudioWorkspace
    {
        $media = [];
        $frames = [];
        for ($i = 1; $i <= $count; $i++) {
            $path = 'studio/uploads/100/'.auth()->id().'/'.\Illuminate\Support\Str::uuid().'.jpg';
            Storage::disk('public')->put($path, $this->image($width, $height, [0, 0, 200]));
            $media[] = ['id' => 'm'.$i, 'mediaRef' => $path];
            $frames[] = ['mediaId' => 'm'.$i, 'method' => 'extend'];
        }
        $response = $this->postJson('/api/studio/workspaces', ['name' => 'Image preparation test', 'presetId' => 'listing-ready', 'media' => $media, 'config' => ['ratio' => $ratio, 'frames' => $frames]])->assertCreated();

        return StudioWorkspace::findOrFail($response->json('data.id'));
    }

    private function process(StudioWorkspace $workspace): void
    {
        $workspace->refresh();
        app(WorkspaceProcessor::class)->process($workspace, $workspace->operation['id']);
    }

    private function dataImage(int $width, int $height, array $rgb): string
    {
        return 'data:image/jpeg;base64,'.base64_encode($this->image($width, $height, $rgb));
    }

    private function image(int $width, int $height, array $rgb): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagejpeg($image, null, 96);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function assertPixel(\GdImage $image, int $x, int $y, array $expected): void
    {
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        foreach (['red', 'green', 'blue'] as $index => $channel) {
            $this->assertEqualsWithDelta($expected[$index], $color[$channel], 15);
        }
    }
}
