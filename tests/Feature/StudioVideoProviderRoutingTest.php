<?php

namespace Tests\Feature;

use App\Exceptions\FalTerminalException;
use App\Jobs\GenerateReel;
use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\Shoots\ShootFileAccessService;
use App\Services\Studio\WorkspaceClipReuse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudioVideoProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        config(['services.fal.key' => 'video-fixture', 'services.fal.model' => 'fal-ai/wan/v2.6/image-to-video',
            'services.fal.walkthrough_model' => 'fal-ai/kling-video/v3/pro/image-to-video', 'services.fal.test_mode' => false]);
    }

    public function test_explicit_video_model_is_used_for_submission_status_and_result_without_global_mutation(): void
    {
        Http::fake(function (Request $request) {
            $this->assertStringStartsWith('https://queue.fal.run/fal-ai/wan-pro', $request->url());
            if ($request->method() === 'POST') {
                return Http::response(['request_id' => 'pinned-request']);
            }

            return str_ends_with($request->url(), '/status')
                ? Http::response(['status' => 'COMPLETED'])
                : Http::response(['video' => ['url' => 'https://fixture.test/clip.mp4']]);
        });
        $fal = new FalService(['video' => 'fal-ai/wan-pro/image-to-video']);
        $id = $fal->submit('https://fixture.test/source.jpg', 'Slow camera movement');
        $this->assertSame('COMPLETED', $fal->status($id));
        $this->assertSame('https://fixture.test/clip.mp4', $fal->result($id));
        $this->assertSame('fal-ai/wan/v2.6/image-to-video', config('services.fal.model'));
        Http::assertSentCount(3);
    }

    public function test_explicit_walkthrough_model_keeps_start_and_end_frames_and_five_seconds(): void
    {
        Http::fake(function (Request $request) {
            $this->assertSame('https://queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/image-to-video', $request->url());
            $this->assertSame('https://fixture.test/start.jpg', $request['image_url']);
            $this->assertSame('https://fixture.test/end.jpg', $request['tail_image_url']);
            $this->assertSame('5', $request['duration']);

            return Http::response(['request_id' => 'walkthrough-pinned']);
        });
        $fal = new FalService(['walkthrough' => 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video']);
        $this->assertSame('walkthrough-pinned', $fal->submitWalkthroughClip('https://fixture.test/start.jpg', 'https://fixture.test/end.jpg', 'Continuous movement'));
        $this->assertSame('fal-ai/kling-video/v3/pro/image-to-video', config('services.fal.walkthrough_model'));
        Http::assertSentCount(1);
    }

    public function test_pinned_clip_fingerprints_ignore_global_route_changes_but_change_for_a_new_pinned_model(): void
    {
        $refs = ['scene-1.jpg', 'scene-2.jpg', 'scene-3.jpg'];
        foreach (['walkthrough', 'property-reel'] as $preset) {
            $config = ['presetId' => $preset, 'prompt' => 'Slow movement', '_studioProviderRoute' => ['provider' => 'fal', 'model' => 'pinned-model-one']];
            $before = WorkspaceClipReuse::fingerprints($refs, $config);
            config(['services.fal.model' => 'new-global-video', 'services.fal.walkthrough_model' => 'new-global-walkthrough']);
            $this->assertSame($before, WorkspaceClipReuse::fingerprints($refs, $config));
            $styled = array_merge($config, ['transition' => 'fade', 'text' => ['title' => 'Listing address', 'style' => 'graphic']]);
            $this->assertSame($before, WorkspaceClipReuse::fingerprints($refs, $styled));
            $config['_studioProviderRoute']['model'] = 'pinned-model-two';
            $this->assertCount(3, array_diff_assoc($before, WorkspaceClipReuse::fingerprints($refs, $config)));
        }
    }

    public function test_linked_workspace_job_resumes_accepted_request_on_its_pinned_model(): void
    {
        $job = $this->videoJob(true);
        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringStartsWith('https://queue.fal.run/fal-ai/wan-pro/requests/request-0/', $request->url());

            return str_ends_with($request->url(), '/status')
                ? Http::response(['status' => 'COMPLETED'])
                : Http::response(['detail' => 'Rejected fixture result'], 422);
        });
        $unused = \Mockery::mock(FalService::class);
        $unused->shouldNotReceive('status', 'result', 'submit');
        try {
            (new GenerateReel($job->id))->handle($unused, app(ShootFileAccessService::class));
            $this->fail('Expected the pinned provider result rejection.');
        } catch (FalTerminalException $exception) {
            $this->assertSame(422, $exception->httpStatus);
        }
        Http::assertSentCount(2);
        $this->assertSame('failed', $job->fresh()->status);
    }

    public function test_unlinked_legacy_job_cannot_inject_a_model_using_copied_workspace_metadata(): void
    {
        $job = $this->videoJob(false);
        $fal = \Mockery::mock(FalService::class);
        $fal->shouldReceive('status')->once()->with('request-0')->andThrow(new \RuntimeException('Expected configured service'));
        try {
            (new GenerateReel($job->id))->handle($fal, app(ShootFileAccessService::class));
            $this->fail('Expected the normal service path.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Expected configured service', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    private function videoJob(bool $linked): AiReelJob
    {
        $user = User::factory()->create(['role' => 'admin']);
        $workspace = StudioWorkspace::create(['created_by' => $user->id, 'team_id' => $user->id, 'name' => 'Pinned video fixture', 'preset_id' => 'property-reel',
            'media' => [['id' => 'm0']], 'config' => ['frames' => [['mediaId' => 'm0']], 'ratio' => '9:16'], 'status' => 'generating', 'progress' => 0, 'version' => 1]);
        $job = AiReelJob::create(['user_id' => $user->id, 'provider' => 'fal', 'status' => 'processing', 'source_media_refs' => ['prepared/0.jpg'], 'selected_file_ids' => [],
            'workflow_config' => ['studioWorkspace' => true, 'studioWorkspaceId' => $workspace->id, 'sourceDisk' => 'public', 'presetId' => 'property-reel',
                '_studioProviderRoute' => ['provider' => 'fal', 'model' => 'fal-ai/wan-pro/image-to-video'], '_studioRuntime' => ['requests' => ['request-0']]]]);
        if ($linked) {
            $workspace->update(['operation' => ['id' => 'operation', 'type' => 'generate', 'reelJobId' => $job->id]]);
        }

        return $job;
    }
}
