<?php

namespace Tests\Feature;

use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\WorkspaceVideoAudio;
use App\Services\Studio\WorkspaceVideoOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkspaceVideoControlsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/studio-audio-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['filesystems.disks.studio_audio.root' => $this->directory.'/private', 'filesystems.disks.studio_audio.shared_group' => null]);
        Storage::forgetDisk('studio_audio');
        Storage::fake('public');
        config(['studio_uploads.disk' => 'public']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 100]]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_defaults_validation_and_finishing_changes_preserve_prepared_frames_and_old_outputs(): void
    {
        $w = $this->workspace();
        $this->assertSame(WorkspaceVideoOptions::defaults()['audio'], $w->config['audio']);
        $this->assertSame('1080p', $w->config['resolution']);
        $w->update(['status' => 'completed', 'prepared_frames' => [['mediaId' => 'm1', 'method' => 'fit', 'status' => 'completed']], 'outputs' => [['id' => 'old', 'kind' => 'video', 'resolution' => '1080p', 'hasAudio' => false]], 'operation' => ['id' => 'old']]);
        $this->patchJson($this->url($w), ['version' => 1, 'config' => ['resolution' => '720p', 'audio' => ['original' => true, 'musicVolume' => 0.2, 'fade' => false]]])
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.config.resolution', '720p')->assertJsonPath('data.config.audio.original', true)
            ->assertJsonPath('data.preparedFrames.0.mediaId', 'm1')->assertJsonPath('data.outputs.0.resolution', '1080p');
        $this->assertNull($w->fresh()->operation);
        foreach ([['resolution' => '4K'], ['audio' => ['musicVolume' => 2]], ['audio' => ['path' => '/etc/passwd']], ['audio' => ['musicId' => '../../secret']]] as $config) {
            $this->patchJson($this->url($w), ['config' => $config])->assertUnprocessable();
        }
        $this->assertSame(2, $w->fresh()->version);
        Queue::assertNothingPushed();
    }

    public function test_upload_preview_select_remove_and_delete_are_private_versioned_and_workspace_scoped(): void
    {
        $w = $this->workspace();
        $upload = $this->post($this->url($w).'/audio', ['version' => 1, 'audio' => $this->tone()], ['Accept' => 'application/json'])->assertCreated();
        $asset = $upload->json('data');
        $upload->assertJsonPath('data.workspaceVersion', 2)->assertJsonPath('data.mimeType', 'audio/mp4');
        $this->assertGreaterThan(0.9, $asset['durationSeconds']);
        $this->assertStringNotContainsString('storage/', $asset['previewUrl']);
        $this->getJson($this->url($w).'/audio')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $asset['id'])->assertJsonPath('workspaceVersion', 2);
        $this->get($asset['previewUrl'])->assertOk()->assertHeader('Content-Type', 'audio/mp4')->assertHeader('X-Content-Type-Options', 'nosniff');
        $path = app(WorkspaceVideoAudio::class)->path($w, $asset['id']);
        $this->assertFileExists($path);
        $this->assertSame(0660, fileperms($path) & 0777);
        $this->patchJson($this->url($w), ['version' => 2, 'config' => ['audio' => ['musicId' => $asset['id']]]])->assertOk()->assertJsonPath('data.version', 3);
        $this->deleteJson($asset['previewUrl'], ['version' => 3])->assertConflict();
        $this->assertSame(3, $w->fresh()->version);

        $other = $this->workspace();
        $this->getJson($this->url($other).'/audio')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($this->url($other), ['config' => ['audio' => ['musicId' => $asset['id']]]])->assertUnprocessable();
        $this->getJson($this->url($other).'/audio/'.$asset['id'])->assertUnprocessable();
        $actor = auth()->user();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 200]]));
        $this->getJson($asset['previewUrl'])->assertForbidden();
        $this->getJson($this->url($w).'/audio')->assertForbidden();
        $this->deleteJson($asset['previewUrl'], ['version' => 3])->assertForbidden();
        Sanctum::actingAs($actor);

        $this->patchJson($this->url($w), ['version' => 3, 'config' => ['audio' => ['musicId' => null]]])->assertOk()->assertJsonPath('data.version', 4);
        $this->deleteJson($asset['previewUrl'], ['version' => 3])->assertConflict();
        $this->deleteJson($asset['previewUrl'], ['version' => 4])->assertOk()->assertJsonPath('data.version', 5);
        $this->assertFileDoesNotExist($path);
        $this->getJson($asset['previewUrl'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('studio_audio')->allFiles());
    }

    public function test_invalid_audio_busy_stale_and_photo_workspaces_are_rejected_without_assets(): void
    {
        $w = $this->workspace();
        $endpoint = $this->url($w).'/audio';
        $this->post($endpoint, ['version' => 1, 'audio' => UploadedFile::fake()->createWithContent('fake.mp3', 'not audio')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($endpoint, ['version' => 1, 'audio' => UploadedFile::fake()->create('large.mp3', 20481, 'audio/mpeg')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($endpoint, ['version' => 1, 'audio' => $this->tone(0.2)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($endpoint, ['version' => 1, 'audio' => $this->tone(601, 8000)], ['Accept' => 'application/json'])->assertUnprocessable();
        $videoPath = $this->directory.'/hidden-video.m4a';
        (new Process(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=32x32:r=10', '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '1', '-c:v', 'libx264', '-c:a', 'aac', '-f', 'mp4', $videoPath]))->mustRun();
        $this->post($endpoint, ['version' => 1, 'audio' => new UploadedFile($videoPath, 'hidden-video.m4a', 'audio/mp4', null, true)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($endpoint, ['version' => 99, 'audio' => $this->tone()], ['Accept' => 'application/json'])->assertConflict();
        $w->update(['status' => 'generating']);
        $this->post($endpoint, ['version' => 1, 'audio' => $this->tone()], ['Accept' => 'application/json'])->assertConflict();
        $w->update(['status' => 'draft', 'preset_id' => 'twilight']);
        $this->post($endpoint, ['version' => 1, 'audio' => $this->tone()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('studio_audio')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_upload_race_cleans_the_new_asset_when_generation_started_during_transcoding(): void
    {
        $w = $this->workspace();
        $this->partialMock(WorkspaceVideoAudio::class)->shouldReceive('store')->once()->andReturnUsing(function ($record, $file) {
            $asset = (new WorkspaceVideoAudio)->store($record, $file);
            $record->fresh()->update(['status' => 'generating', 'version' => 2]);

            return $asset;
        });
        $this->post($this->url($w).'/audio', ['version' => 1, 'audio' => $this->tone()], ['Accept' => 'application/json'])->assertConflict();
        $this->assertSame([], Storage::disk('studio_audio')->allFiles());
        $this->assertSame('generating', $w->fresh()->status);
    }

    public function test_worker_audio_requires_server_owned_job_link_and_same_creator(): void
    {
        $w = $this->workspace();
        $assets = app(WorkspaceVideoAudio::class);
        $asset = $assets->store($w, $this->tone());
        $job = AiReelJob::create(['user_id' => $w->created_by, 'provider' => 'fal', 'selected_file_ids' => [], 'source_media_refs' => [],
            'workflow_config' => ['studioWorkspaceId' => $w->id, 'audio' => ['musicId' => $asset['id']]], 'status' => 'queued']);
        try {
            $assets->forJob($job);
            $this->fail('A guessed workspace ID must not authorize job audio.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $w->update(['operation' => ['reelJobId' => $job->id]]);
        $this->assertSame($assets->path($w, $asset['id']), $assets->forJob($job));
        $job->user_id = User::factory()->create()->id;
        try {
            $assets->forJob($job);
            $this->fail('A job created by another user must not read private audio.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_unlinked_private_audio_is_rejected_before_provider_work(): void
    {
        $w = $this->workspace();
        $asset = app(WorkspaceVideoAudio::class)->store($w, $this->tone());
        $job = AiReelJob::create(['user_id' => $w->created_by, 'provider' => 'fal', 'selected_file_ids' => [], 'source_media_refs' => [],
            'workflow_config' => ['studioWorkspace' => true, 'studioWorkspaceId' => $w->id, 'audio' => ['musicId' => $asset['id']]], 'status' => 'queued']);
        $fal = \Mockery::mock(\App\Services\FalService::class);
        $fal->shouldNotReceive('uploadImage', 'submit', 'submitWalkthroughClip', 'status', 'result');
        try {
            (new \App\Jobs\GenerateReel($job->id))->handle($fal, app(\App\Services\Shoots\ShootFileAccessService::class));
            $this->fail('Private audio must be authorized before any provider work.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
            $this->assertSame('failed', $job->fresh()->status);
        }
    }

    private function workspace(): StudioWorkspace
    {
        $ref = 'studio/uploads/100/'.auth()->id().'/'.bin2hex(random_bytes(4)).'.jpg';
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put($ref, $bytes);
        $response = $this->postJson('/api/studio/workspaces', ['name' => 'Video audio test', 'presetId' => 'property-reel',
            'media' => [['id' => 'm1', 'mediaRef' => $ref]], 'config' => ['frames' => [['mediaId' => 'm1', 'method' => 'fit']]]])->assertCreated();

        return StudioWorkspace::findOrFail($response->json('data.id'));
    }

    private function url(StudioWorkspace $workspace): string
    {
        return '/api/studio/workspaces/'.$workspace->id;
    }

    private function tone(float $seconds = 1, int $sampleRate = 48000): UploadedFile
    {
        $path = $this->directory.'/tone-'.bin2hex(random_bytes(4)).'.wav';
        (new Process(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate='.$sampleRate, '-t', (string) $seconds, $path]))->mustRun();

        return new UploadedFile($path, 'my-original-audio.wav', 'audio/wav', null, true);
    }
}
