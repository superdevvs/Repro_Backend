<?php

namespace Tests\Feature;

use App\Exceptions\FalTerminalException;
use App\Jobs\GenerateReel;
use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\Shoots\ShootFileAccessService;
use App\Services\Studio\ReelCompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceVideoRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['services.fal.test_mode' => false]);
    }

    public function test_rejected_result_discards_only_that_request_and_retry_preserves_the_other_scene(): void
    {
        [$workspace, $job] = $this->workspace();
        $fal = \Mockery::mock(FalService::class);
        $fal->shouldReceive('status')->once()->with('request-0')->andReturn('COMPLETED');
        $fal->shouldReceive('result')->once()->with('request-0')->andThrow(new FalTerminalException(422));
        try {
            (new GenerateReel($job->id))->handle($fal, app(ShootFileAccessService::class));
            $this->fail('Rejected results must stop generation.');
        } catch (FalTerminalException) {
            $job->refresh();
            $this->assertSame('failed', $job->status);
            $this->assertSame([1 => 'request-1'], $job->workflow_config['_studioRuntime']['requests']);
        }

        $clip = tempnam(sys_get_temp_dir(), 'video-recovery-');
        file_put_contents($clip, 'existing-provider-motion');
        try {
            $retry = \Mockery::mock(FalService::class);
            $retry->shouldReceive('uploadImage')->once()->andReturn('https://fixture.test/first.jpg');
            $retry->shouldReceive('submit')->once()->andReturn('replacement-0');
            $retry->shouldReceive('status')->once()->with('replacement-0')->andReturn('COMPLETED');
            $retry->shouldReceive('status')->once()->with('request-1')->andReturn('COMPLETED');
            $retry->shouldReceive('result')->twice()->andReturn($clip);
            $this->mock(ReelCompositionService::class)->shouldReceive('compose')->once()->andReturnUsing(function ($clips, $output) {
                $this->assertCount(2, $clips);
                file_put_contents($output, 'finished-reel');
            });
            (new GenerateReel($job->id))->handle($retry, app(ShootFileAccessService::class));
            $this->assertSame('completed', $job->fresh()->status);
        } finally {
            @unlink($clip);
        }
    }

    public function test_transient_result_failure_keeps_request_ids_for_resume(): void
    {
        [, $job] = $this->workspace();
        $fal = \Mockery::mock(FalService::class);
        $fal->shouldReceive('status')->once()->andReturn('COMPLETED');
        $fal->shouldReceive('result')->once()->andThrow(new \RuntimeException('Temporary result service failure.'));
        try {
            (new GenerateReel($job->id))->handle($fal, app(ShootFileAccessService::class));
            $this->fail('Temporary failures should reach the queue retry handler.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Temporary result service failure.', $error->getMessage());
            $this->assertSame(['request-0', 'request-1'], $job->fresh()->workflow_config['_studioRuntime']['requests']);
        }
    }

    public function test_progress_reports_provider_work_and_rendering_without_exposing_requests(): void
    {
        [$workspace, $job] = $this->workspace();
        $presented = $workspace->present();
        $this->assertSame(['phase' => 'generating', 'total' => 2, 'submitted' => 2, 'completed' => 0], $presented['generation']);
        $this->assertSame(20, $presented['progress']);
        $config = $job->workflow_config;
        $config['_studioRuntime']['clips'][1] = 'https://fixture.test/second.mp4';
        $job->update(['workflow_config' => $config]);
        $this->assertSame(55, $workspace->present()['progress']);
        $job->markAsStitching();
        $this->assertSame('rendering', $workspace->present()['generation']['phase']);
        $this->assertSame(95, $workspace->present()['progress']);
        $workspace->update(['status' => 'cancelled']);
        $this->assertNull($workspace->present()['generation']);
    }

    public function test_progress_never_reads_a_different_workspaces_job(): void
    {
        [$workspace, $job] = $this->workspace();
        $config = $job->workflow_config;
        $config['studioWorkspaceId'] = 'unrelated';
        $job->update(['workflow_config' => $config]);
        $this->assertSame(0, $workspace->present()['generation']['submitted']);
    }

    private function workspace(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $workspace = StudioWorkspace::create(['created_by' => $user->id, 'team_id' => $user->id, 'name' => 'Recovery test', 'preset_id' => 'property-reel', 'media' => [['id' => 'm0'], ['id' => 'm1']], 'config' => ['frames' => [['mediaId' => 'm0'], ['mediaId' => 'm1']], 'ratio' => '9:16'], 'status' => 'generating', 'progress' => 0, 'version' => 1]);
        $image = imagecreatetruecolor(256, 256);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put('prepared/0.jpg', $bytes);
        Storage::disk('public')->put('prepared/1.jpg', $bytes);
        $job = AiReelJob::create(['user_id' => $user->id, 'provider' => 'fal', 'status' => 'processing', 'source_media_refs' => ['prepared/0.jpg', 'prepared/1.jpg'], 'selected_file_ids' => [], 'workflow_config' => ['studioWorkspace' => true, 'studioWorkspaceId' => $workspace->id, 'sourceDisk' => 'public', 'presetId' => 'property-reel', '_studioRuntime' => ['requests' => ['request-0', 'request-1']]]]);
        $workspace->update(['operation' => ['id' => 'operation', 'type' => 'generate', 'reelJobId' => $job->id]]);

        return [$workspace, $job];
    }
}
