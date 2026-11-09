<?php

namespace Tests\Feature;

use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
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

class WorkspacePhotoExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        config(['studio_uploads.disk' => 'public', 'services.fal.key' => 'test-extension-key']);
    }

    public function test_original_extension_creates_a_new_photo_version_and_replay_never_requeues(): void
    {
        [$w, $payload, $ref, $original] = $this->workspace();
        $this->mockOutpaint(1);
        $receipt = $this->postJson($this->endpoint($w), $payload)->assertAccepted()
            ->assertJsonPath('data.operationReceipt.type', 'extend')
            ->assertJsonPath('data.generationScope.mediaIds', ['photo'])
            ->json('data.operationReceipt');
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.operationReceipt', $receipt);
        $this->process($w);
        $saved = $w->fresh();
        $this->assertSame('completed', $saved->status);
        $this->assertCount(1, $saved->outputs);
        $this->assertEmpty($saved->prepared_frames);
        $this->assertSame('extend', $saved->outputs[0]['method']);
        $this->assertSame('1:1', $saved->outputs[0]['ratio']);
        $this->assertSame(1, $saved->outputs[0]['version']);
        $this->assertSame($original, Storage::disk('public')->get($ref));
        $result = Storage::disk('public')->get($saved->outputs[0]['path']);
        $this->assertSame([400, 400], array_slice(getimagesizefromstring($result), 0, 2));
        $this->assertPixel($result, 200, 200, [180, 30, 30]);
        $this->assertPixel($result, 200, 4, [20, 140, 50]);
        $this->assertPixel($result, 200, 100, [180, 30, 30]);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.operationReceipt', $receipt)
            ->assertJsonPath('data.status', 'completed');
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        Http::assertNothingSent();
    }

    public function test_exact_historical_output_is_extended_without_replacing_later_outputs_or_recipe(): void
    {
        [$w, $payload] = $this->workspace();
        $old = $this->savedOutput($w, 'old', [20, 60, 190], 1);
        $latest = $this->savedOutput($w, 'latest', [210, 140, 10], 2);
        $w->update(['outputs' => [$old, $latest], 'config' => array_replace($w->config, ['ratio' => '9:16'])]);
        $payload = $this->outputPayload($payload, 'old');
        $this->mockOutpaint(1);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $this->process($w);
        $saved = $w->fresh();
        $this->assertCount(3, $saved->outputs);
        $this->assertSame([$old, $latest], array_slice($saved->outputs, 0, 2));
        $this->assertSame('9:16', $saved->config['ratio']);
        $this->assertSame('1:1', $saved->outputs[2]['ratio']);
        $this->assertSame(3, $saved->outputs[2]['version']);
        $this->assertPixel(Storage::disk('public')->get($saved->outputs[2]['path']), 200, 200, [20, 60, 190]);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.status', 'completed');
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
    }

    #[DataProvider('invalidRequests')]
    public function test_extension_requires_an_explicit_supported_photo_target(array $replace, array $remove): void
    {
        [$w, $payload] = $this->workspace();
        $payload = array_replace($payload, $replace);
        foreach ($remove as $key) {
            unset($payload[$key]);
        }
        $this->postJson($this->endpoint($w), $payload)->assertUnprocessable();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function invalidRequests(): array
    {
        return [
            'missing version' => [[], ['version']],
            'missing receipt' => [[], ['sourceVersion']],
            'missing ratio' => [[], ['ratio']],
            'unknown ratio' => [['ratio' => '7:3'], []],
            'conflicting source' => [['outputId' => 'old'], []],
            'no explicit source' => [[], ['original', 'sourceVersion']],
            'unknown source' => [['mediaId' => 'outside'], []],
            'prompt not accepted' => [['prompt' => 'Replace this room'], []],
            'region not accepted' => [['region' => ['x' => 0, 'y' => 0, 'width' => .5, 'height' => .5]], []],
            'edits not accepted' => [['edits' => ['exposure' => 1]], []],
        ];
    }

    public function test_same_key_cannot_change_ratio_version_or_output_and_cancelled_operation_stays_cancelled(): void
    {
        [$w, $payload] = $this->workspace();
        $w->update(['outputs' => [$this->savedOutput($w, 'old', [20, 60, 190], 1), $this->savedOutput($w, 'latest', [210, 140, 10], 2)]]);
        $payload = $this->outputPayload($payload, 'old');
        $receipt = $this->postJson($this->endpoint($w), $payload)->assertAccepted()->json('data.operationReceipt');
        foreach ([['ratio' => '16:9'], ['version' => 2], ['outputId' => 'latest']] as $change) {
            $this->postJson($this->endpoint($w), array_replace($payload, $change))->assertConflict();
        }
        $this->postJson('/api/studio/workspaces/'.$w->id.'/cancel')->assertOk();
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.operationReceipt', $receipt);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        Http::assertNothingSent();
    }

    public function test_stale_original_and_stale_output_workspace_version_are_rejected_before_queueing(): void
    {
        [$w, $payload, $ref] = $this->workspace();
        Storage::disk('public')->put($ref, $this->image(400, 200, [20, 60, 190]));
        $this->postJson($this->endpoint($w), $payload)->assertConflict();
        $w->update(['outputs' => [$this->savedOutput($w, 'old', [20, 60, 190], 1)], 'version' => 2]);
        $this->postJson($this->endpoint($w), $this->outputPayload($payload, 'old'))->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_original_changed_after_acceptance_cannot_spend_or_save_an_output(): void
    {
        [$w, $payload, $ref] = $this->workspace();
        $this->mock(WorkspaceImageOperations::class)->shouldNotReceive('outpaint');
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        Storage::disk('public')->put($ref, $this->image(400, 200, [20, 60, 190]));
        try {
            $this->process($w);
            $this->fail('A changed original must fail before extension.');
        } catch (StudioProviderException $exception) {
            $this->assertStringContainsString('original photo changed', $exception->getMessage());
        }
        $this->assertEmpty($w->fresh()->outputs);
        Http::assertNothingSent();
    }

    public function test_same_aspect_ratio_has_no_provider_request(): void
    {
        [$w, $payload] = $this->workspace(300, 300);
        $this->mock(WorkspaceImageOperations::class)->shouldNotReceive('outpaint');
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $this->process($w);
        $output = $w->fresh()->outputs[0];
        $this->assertSame([300, 300], array_slice(getimagesizefromstring(Storage::disk('public')->get($output['path'])), 0, 2));
        Http::assertNothingSent();
    }

    public function test_catalog_exposes_support_but_missing_provider_and_video_are_not_accepted(): void
    {
        [$w, $payload] = $this->workspace();
        config(['services.fal.key' => '', 'services.openai.api_key' => '']);
        $this->getJson('/api/studio/workspaces/catalog')->assertOk()->assertJsonPath('data.controls.photoExtend', true)
            ->assertJsonPath('data.capabilities.outpaint.ready', false);
        $this->getJson('/api/studio/workspaces/'.$w->id)->assertOk()->assertJsonPath('data.capabilities.photoExtend', true);
        $this->postJson($this->endpoint($w), $payload)->assertUnprocessable();
        config(['services.fal.key' => 'test-extension-key']);
        $w->update(['preset_id' => 'property-reel']);
        $this->postJson($this->endpoint($w), $payload)->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_other_team_cannot_extend_the_photo(): void
    {
        [$w, $payload] = $this->workspace();
        Sanctum::actingAs(User::factory()->create(['role' => 'editor', 'metadata' => ['team_id' => 200]]));
        $this->postJson($this->endpoint($w), $payload)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_retry_reuses_accepted_provider_request_without_resubmitting(): void
    {
        [$w, $payload] = $this->workspace();
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitModel')->once()->andReturn('accepted-outpaint');
        $fal->shouldReceive('modelStatus')->twice()->andReturn('COMPLETED');
        $attempt = 0;
        $fal->shouldReceive('modelImageResult')->twice()->andReturnUsing(function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new RuntimeException('Result retrieval temporarily interrupted');
            }

            return 'data:image/jpeg;base64,'.base64_encode($this->image(400, 400, [20, 140, 50]));
        });
        $receipt = $this->postJson($this->endpoint($w), $payload)->assertAccepted()->json('data.operationReceipt');
        try {
            $this->process($w);
            $this->fail('Expected the first result retrieval to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Result retrieval temporarily interrupted', $exception->getMessage());
            $w->refresh();
            (new ProcessStudioWorkspace($w->id, $w->operation['id']))->failed($exception);
        }
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.operationReceipt', $receipt);
        $this->process($w);
        $this->assertSame('completed', $w->fresh()->status);
        $this->assertCount(1, $w->fresh()->outputs);
        Queue::assertPushed(ProcessStudioWorkspace::class, 2);
        Http::assertNothingSent();
    }

    public function test_large_landscape_source_is_bounded_by_the_expanded_canvas(): void
    {
        [$w, $payload] = $this->workspace(3000, 2000);
        $payload['ratio'] = '9:16';
        $this->mockOutpaint(1);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $this->process($w);
        $bytes = Storage::disk('public')->get($w->fresh()->outputs[0]['path']);
        $this->assertSame([1440, 2560], array_slice(getimagesizefromstring($bytes), 0, 2));
        $this->assertPixel($bytes, 720, 1280, [180, 30, 30]);
    }

    private function workspace(int $width = 400, int $height = 200): array
    {
        $user = User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 100]]);
        Sanctum::actingAs($user);
        $ref = 'studio/uploads/100/'.$user->id.'/original.jpg';
        $original = $this->image($width, $height, [180, 30, 30]);
        Storage::disk('public')->put($ref, $original);
        $id = $this->postJson('/api/studio/workspaces', ['name' => 'Photo extension', 'presetId' => 'listing-ready',
            'config' => [], 'media' => [['id' => 'photo', 'mediaRef' => $ref]]])->assertCreated()->json('data.id');
        $w = StudioWorkspace::findOrFail($id);
        $preview = $this->postJson('/api/studio/workspaces/'.$id.'/edit-preview', ['version' => $w->version,
            'mediaId' => 'photo', 'original' => true, 'edits' => ['exposure' => 0]])->assertOk();
        $payload = ['requestId' => 'extend-photo', 'version' => $w->version, 'mediaId' => 'photo', 'original' => true,
            'sourceVersion' => $preview->headers->get('X-Studio-Source-Version'), 'ratio' => '1:1'];

        return [$w, $payload, $ref, $original];
    }

    private function savedOutput(StudioWorkspace $w, string $id, array $color, int $version): array
    {
        $path = 'studio/workspaces/'.$w->id.'/'.$id.'.jpg';
        Storage::disk('public')->put($path, $this->image(400, 200, $color));

        return ['id' => $id, 'mediaId' => 'photo', 'path' => $path, 'url' => '/storage/'.$path, 'thumbnailUrl' => '/storage/'.$path,
            'kind' => 'image', 'status' => 'completed', 'version' => $version];
    }

    private function outputPayload(array $payload, string $outputId): array
    {
        unset($payload['original'], $payload['sourceVersion']);

        return array_replace($payload, ['outputId' => $outputId]);
    }

    private function endpoint(StudioWorkspace $w): string
    {
        return '/api/studio/workspaces/'.$w->id.'/extensions';
    }

    private function process(StudioWorkspace $w): void
    {
        $w->refresh();
        app(WorkspaceProcessor::class)->process($w, $w->operation['id']);
    }

    private function mockOutpaint(int $count): void
    {
        $this->mock(WorkspaceImageOperations::class)->shouldReceive('outpaint')->times($count)
            ->andReturnUsing(function ($workspace, $operationId, $mediaId, $source, $ratio, $expansion) {
                [$width, $height] = getimagesizefromstring($source);
                $width += $expansion['expand_left'] + $expansion['expand_right'];
                $height += $expansion['expand_top'] + $expansion['expand_bottom'];
                $this->assertLessThanOrEqual(2560, max($width, $height));
                $this->assertEqualsWithDelta($expansion['expand_left'], $expansion['expand_right'], 1);
                $this->assertEqualsWithDelta($expansion['expand_top'], $expansion['expand_bottom'], 1);

                return $this->image($width, $height, [20, 140, 50]);
            });
    }

    private function image(int $width, int $height, array $color): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));
        ob_start();
        imagejpeg($image, null, 98);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function assertPixel(string $bytes, int $x, int $y, array $expected): void
    {
        $image = imagecreatefromstring($bytes);
        $pixel = imagecolorat($image, $x, $y);
        foreach ([16, 8, 0] as $index => $shift) {
            $this->assertEqualsWithDelta($expected[$index], ($pixel >> $shift) & 255, 5);
        }
        imagedestroy($image);
    }
}
