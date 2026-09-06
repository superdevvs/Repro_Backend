<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\Studio\ReelCompositionService;
use App\Services\Studio\WorkspaceClipReuse;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceClipReuseFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['studio_uploads.disk' => 'public', 'services.fal.test_mode' => false, 'services.fal.walkthrough_model' => 'kling-pinned']);
    }

    public function test_styling_reuses_six_durable_clips_and_frame_four_revision_generates_only_two_joins(): void
    {
        $workspace = $this->workspace();
        $submitted = 0;
        $paths = [];
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('uploadImage')->times(15)->andReturn('https://fixture.test/conditioning.jpg');
        $fal->shouldReceive('submitWalkthroughClip')->times(8)->andReturnUsing(function () use (&$submitted) {
            return 'request-'.++$submitted;
        });
        $fal->shouldReceive('modelStatus')->times(8)->andReturn('COMPLETED');
        $fal->shouldReceive('modelVideoResult')->times(8)->andReturnUsing(function ($model, $request) use (&$paths) {
            $path = tempnam(sys_get_temp_dir(), 'original-scene-');
            file_put_contents($path, 'original-motion-'.$request);
            $paths[] = $path;

            return $path;
        });
        $assemblies = [];
        $this->mock(ReelCompositionService::class)->shouldReceive('compose')->times(3)->andReturnUsing(function ($clips, $output, $directory, $config) use (&$assemblies) {
            $assemblies[] = ['clips' => array_map('file_get_contents', $clips), 'config' => $config];
            file_put_contents($output, 'rendered-fixture');
        });
        try {
            $this->generate($workspace);
            $this->assertSame(6, $submitted);
            $firstJob = AiReelJob::findOrFail($workspace->outputs[0]['reelJobId']);
            $this->assertCount(6, data_get($firstJob->workflow_config, '_studioRuntime.localClips'));
            foreach (data_get($firstJob->workflow_config, '_studioRuntime.localClips') as $path) {
                Storage::disk('local')->assertExists($path);
            }

            $this->patchJson('/api/studio/workspaces/'.$workspace->id, ['config' => ['duration' => 45, 'transition' => 'fade', 'text' => ['style' => 'graphic', 'title' => 'New property title']]])->assertOk();
            $this->generate($workspace);
            $this->assertSame(6, $submitted, 'Finishing changes must not submit any motion generation.');
            $this->assertSame($assemblies[0]['clips'], $assemblies[1]['clips']);
            $this->assertSame(45, $assemblies[1]['config']['duration']);

            $prepared = $workspace->prepared_frames;
            $prepared[3]['path'] = "studio/workspaces/{$workspace->id}/scene4-v2.jpg";
            $prepared[3]['url'] = Storage::disk('public')->url($prepared[3]['path']);
            $prepared[3]['version'] = 2;
            Storage::disk('public')->put($prepared[3]['path'], $this->image());
            $workspace->update(['prepared_frames' => $prepared, 'operation' => null, 'status' => 'ready']);
            $this->generate($workspace);
            $this->assertSame(8, $submitted);
            $this->assertSame([2, 3], array_keys(array_diff_assoc($assemblies[1]['clips'], $assemblies[2]['clips'])));
            $this->assertCount(3, $workspace->outputs);
            $this->assertSame($workspace->outputs[2]['reelJobId'], $workspace->history[2]['reelJobId']);
            $this->getJson('/api/studio/workspaces/'.$workspace->id)->assertOk()->assertJsonMissingPath('data.outputs.0.reelJobId');
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    }

    public function test_reuse_rejects_other_workspaces_owners_and_missing_cached_files(): void
    {
        $workspace = $this->workspace();
        $refs = array_column($workspace->prepared_frames, 'path');
        $config = array_merge($workspace->config, ['presetId' => 'walkthrough', 'studioWorkspaceId' => $workspace->id]);
        $fingerprints = WorkspaceClipReuse::fingerprints($refs, $config);
        $path = "studio/workspaces/{$workspace->id}/clips/{$fingerprints[0]}.mp4";
        Storage::disk('local')->put($path, 'original-motion');
        $config['_studioRuntime'] = ['fingerprints' => $fingerprints, 'localClips' => [$path]];
        $job = AiReelJob::create(['user_id' => $workspace->created_by, 'provider' => 'fal', 'selected_file_ids' => [], 'source_media_refs' => [], 'status' => 'completed', 'workflow_config' => $config]);
        $workspace->update(['outputs' => [['reelJobId' => $job->id]]]);
        $reuse = app(WorkspaceClipReuse::class);
        $this->assertSame([$path], $reuse->seed($workspace, $refs, $config)['localClips']);
        $forged = AiReelJob::create(['user_id' => $workspace->created_by, 'provider' => 'fal', 'selected_file_ids' => [], 'source_media_refs' => [], 'status' => 'completed', 'workflow_config' => $config]);
        $this->assertNull($reuse->localPath($forged, 0), 'Copied runtime config is insufficient without a server-owned workspace/job association.');

        $otherConfig = $config;
        $otherConfig['studioWorkspaceId'] = (string) \Illuminate\Support\Str::uuid();
        $job->update(['workflow_config' => $otherConfig]);
        $this->assertSame([], $reuse->seed($workspace, $refs, $config)['localClips']);
        $job->update(['workflow_config' => $config, 'user_id' => User::factory()->create()->id]);
        $this->assertSame([], $reuse->seed($workspace, $refs, $config)['localClips']);
        $job->update(['user_id' => $workspace->created_by]);
        Storage::disk('local')->delete($path);
        $this->assertSame([], $reuse->seed($workspace, $refs, $config)['localClips']);
    }

    private function generate(StudioWorkspace $workspace): void
    {
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/generate')->assertAccepted();
        $workspace->refresh();
        (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->handle(app(WorkspaceProcessor::class));
        $workspace->refresh();
        $this->assertSame('completed', $workspace->status);
    }

    private function workspace(): StudioWorkspace
    {
        $user = User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 100]]);
        Sanctum::actingAs($user);
        $media = $frames = [];
        for ($i = 0; $i < 6; $i++) {
            $ref = "studio/uploads/100/{$user->id}/source{$i}.jpg";
            Storage::disk('public')->put($ref, $this->image());
            $media[] = ['id' => 'm'.$i, 'mediaRef' => $ref];
            $frames[] = ['mediaId' => 'm'.$i, 'method' => 'fit', 'duration' => 5];
        }
        $response = $this->postJson('/api/studio/workspaces', ['name' => 'Walkthrough', 'presetId' => 'walkthrough', 'media' => $media, 'config' => ['frames' => $frames]])->assertCreated();
        $workspace = StudioWorkspace::findOrFail($response->json('data.id'));
        $prepared = [];
        foreach ($frames as $i => $frame) {
            $path = "studio/workspaces/{$workspace->id}/prepared{$i}.jpg";
            Storage::disk('public')->put($path, $this->image());
            $prepared[] = array_merge($frame, ['path' => $path, 'url' => Storage::disk('public')->url($path), 'status' => 'completed', 'ratio' => '9:16', 'version' => 1]);
        }
        $workspace->update(['prepared_frames' => $prepared, 'status' => 'ready']);

        return $workspace;
    }

    private function image(): string
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
