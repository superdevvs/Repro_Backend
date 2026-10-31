<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\WorkspaceImageOperations;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioWorkspaceRetainedSourceWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_worker_denies_retained_output_after_original_shoot_is_reassigned(): void
    {
        [$workspace, $originalShoot] = $this->queuedUpscale();
        $originalShoot->update(['editor_id' => null]);
        $this->mock(WorkspaceImageOperations::class)->shouldNotReceive('upscale');

        try {
            (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->handle(app(WorkspaceProcessor::class));
            $this->fail('The retained source must be authorized before provider work.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This edit is no longer within your media access.', $exception->getMessage());
        }

        $this->assertCount(1, $workspace->fresh()->outputs);
        $this->assertSame([], $workspace->fresh()->operation['completed']);
        Http::assertNothingSent();
    }

    public function test_worker_can_upscale_exact_retained_output_while_both_sources_remain_authorized(): void
    {
        [$workspace, , $originalBytes] = $this->queuedUpscale();
        $this->mock(WorkspaceImageOperations::class)->shouldReceive('upscale')->once()
            ->andReturnUsing(function ($record, $operationId, $mediaId, $bytes) use ($workspace, $originalBytes) {
                $this->assertSame($workspace->id, $record->id);
                $this->assertSame('p', $mediaId);
                $this->assertSame(hash('sha256', $originalBytes), hash('sha256', $bytes));

                return $bytes;
            });

        (new ProcessStudioWorkspace($workspace->id, $workspace->operation['id']))->handle(app(WorkspaceProcessor::class));

        $saved = $workspace->fresh();
        $this->assertSame('completed', $saved->status, $saved->error ?? '');
        $this->assertCount(2, $saved->outputs);
        $this->assertSame(['p'], $saved->operation['completed']);
        $this->assertCount(2, $saved->access_media);
        Http::assertNothingSent();
    }

    private function queuedUpscale(): array
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $originalShoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $replacementShoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $original = $this->source($originalShoot, $editor);
        $replacement = $this->source($replacementShoot, $editor);
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 20, 20));
        ob_start();
        imagejpeg($image, null, 95);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $workspace = StudioWorkspace::create([
            'team_id' => $editor->id, 'created_by' => $editor->id,
            'name' => 'Retained source version', 'preset_id' => 'listing-ready',
            'status' => 'completed', 'config' => [],
            'media' => [['id' => 'p', 'fileId' => $original->id, 'shootId' => $originalShoot->id]],
        ]);
        $path = 'studio/workspaces/'.$workspace->id.'/original-result.jpg';
        Storage::disk('public')->put($path, $bytes);
        $workspace->update(['outputs' => [[
            'id' => 'original-result', 'mediaId' => 'p', 'kind' => 'image',
            'status' => 'completed', 'version' => 1, 'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]]]);
        Sanctum::actingAs($editor);
        // Exercise the real replacement path, including server-owned provenance.
        $this->patchJson('/api/studio/workspaces/'.$workspace->id, [
            'media' => [['id' => 'p', 'fileId' => $replacement->id, 'shootId' => $replacementShoot->id]],
        ])->assertOk();
        $this->postJson('/api/studio/workspaces/'.$workspace->id.'/upscale', [
            'requestId' => 'retained-output-upscale', 'mediaId' => 'p', 'outputId' => 'original-result',
        ])->assertAccepted();
        Queue::assertPushed(ProcessStudioWorkspace::class);

        return [$workspace->fresh(), $originalShoot, $bytes];
    }

    private function source(Shoot $shoot, User $owner): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'source.jpg', 'stored_filename' => 'source.jpg', 'path' => 'source.jpg',
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $owner->id,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'media_type' => 'edited',
        ]);
    }
}
