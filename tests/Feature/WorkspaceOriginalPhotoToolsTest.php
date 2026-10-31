<?php

namespace Tests\Feature;

use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\WorkspaceImageOperations;
use App\Services\Studio\WorkspaceMediaService;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceOriginalPhotoToolsTest extends TestCase
{
    use RefreshDatabase;

    private array $sourceVersions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        config(['studio_uploads.disk' => 'public', 'services.fal.key' => 'fake-revision-key']);
    }

    public function test_original_preview_is_private_and_does_not_generate_or_replace_anything(): void
    {
        [$w, $ref, $bytes] = $this->workspace();
        $response = $this->postJson($this->endpoint($w, 'edit-preview'), [
            'version' => $w->version, 'mediaId' => 'photo', 'original' => true,
            'edits' => ['crop' => ['x' => 0, 'y' => 0, 'width' => .5, 'height' => 1], 'rotation' => 90],
        ])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->headers->get('X-Studio-Source-Version'));
        $this->assertSame([60, 40], array_slice(getimagesizefromstring($response->getContent()), 0, 2));
        $this->assertSame($bytes, Storage::disk('public')->get($ref));
        $this->assertEmpty($w->fresh()->outputs);
        $this->assertSame(1, $w->fresh()->version);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_original_adjustment_creates_first_output_and_same_request_replays_after_version_advances(): void
    {
        [$w, $ref, $bytes] = $this->workspace();
        $this->mock(WorkspaceImageOperations::class)->shouldNotReceive('edit', 'upscale');
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldNotReceive('run');
        $payload = $this->edit($w);
        $first = $this->postJson($this->endpoint($w), $payload)->assertAccepted()->json('data.operationReceipt');
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.operationReceipt', $first);
        $this->processWorkspace($w);
        $saved = $w->fresh();
        $this->assertSame('completed', $saved->status);
        $this->assertCount(1, $saved->outputs);
        $this->assertSame(1, $saved->outputs[0]['version']);
        $pixels = imagecreatefromstring(Storage::disk('public')->get($saved->outputs[0]['path']));
        $this->assertEqualsWithDelta(128, (imagecolorat($pixels, 25, 25) >> 16) & 255, 4);
        imagedestroy($pixels);
        $this->assertSame($bytes, Storage::disk('public')->get($ref));
        $response = $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.status', 'completed');
        $this->assertStringNotContainsString('originalSources', $response->getContent());
        $this->assertStringNotContainsString(hash('sha256', $bytes), $response->getContent());
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        Http::assertNothingSent();
    }

    public function test_portrait_orientation_crop_preview_and_saved_output_use_the_same_source_coordinates(): void
    {
        [$w, $ref, $jpeg] = $this->workspace();
        // Camera orientation 6: stored landscape pixels display as portrait.
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        $oriented = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        Storage::disk('public')->put($ref, $oriented);
        $this->originalToken($w);
        $edits = ['crop' => ['x' => 0, 'y' => 0, 'width' => .5, 'height' => 1], 'rotation' => 90];
        $preview = $this->postJson($this->endpoint($w, 'edit-preview'), ['version' => 1, 'mediaId' => 'photo',
            'original' => true, 'sourceVersion' => $this->sourceVersions[$w->id], 'edits' => $edits])->assertOk();
        $this->assertSame([80, 30], array_slice(getimagesizefromstring($preview->getContent()), 0, 2));
        $this->postJson($this->endpoint($w), array_replace($this->edit($w), ['edits' => $edits]))->assertAccepted();
        $this->processWorkspace($w);
        $output = Storage::disk('public')->get($w->fresh()->outputs[0]['path']);
        $this->assertSame([80, 30], array_slice(getimagesizefromstring($output), 0, 2));
        $this->assertSame($oriented, Storage::disk('public')->get($ref));
    }

    public function test_original_targets_require_version_and_explicit_mutually_exclusive_selection(): void
    {
        [$w] = $this->workspace();
        $payload = $this->edit($w);
        unset($payload['version']);
        $this->postJson($this->endpoint($w), $payload)->assertUnprocessable();
        $payload = $this->edit($w);
        unset($payload['targets'][0]['sourceVersion']);
        $this->postJson($this->endpoint($w), $payload)->assertUnprocessable();
        $payload = $this->edit($w);
        $payload['version'] = 99;
        $this->postJson($this->endpoint($w), $payload)->assertConflict();
        $payload = $this->edit($w);
        $payload['targets'][0]['outputId'] = 'irrelevant';
        $this->postJson($this->endpoint($w), $payload)->assertUnprocessable();
        $payload['targets'] = [['mediaId' => 'photo']];
        $this->postJson($this->endpoint($w), $payload)->assertUnprocessable();
        $this->postJson($this->endpoint($w, 'edit-preview'), ['mediaId' => 'photo', 'original' => true, 'edits' => []])->assertUnprocessable();
        $this->postJson($this->endpoint($w, 'revisions'), ['version' => 1, 'mediaId' => 'photo', 'original' => true, 'prompt' => 'Remove chair'])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_same_key_with_changed_edit_or_target_is_rejected_before_any_second_job(): void
    {
        [$w] = $this->workspace();
        $payload = $this->edit($w);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $changed = array_replace($payload, ['edits' => ['exposure' => -1]]);
        $this->postJson($this->endpoint($w), $changed)->assertConflict();
        $changed = array_replace($payload, ['version' => 2]);
        $this->postJson($this->endpoint($w), $changed)->assertConflict();
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
    }

    public function test_legacy_output_request_version_does_not_change_fallback_idempotency(): void
    {
        [$w] = $this->workspace();
        $path = 'studio/workspaces/'.$w->id.'/historical.jpg';
        Storage::disk('public')->put($path, $this->image(64));
        $w->update(['outputs' => [['id' => 'old', 'mediaId' => 'photo', 'path' => $path, 'kind' => 'image', 'status' => 'completed', 'version' => 1]]]);
        $payload = ['version' => 1, 'targets' => [['mediaId' => 'photo', 'outputId' => 'old']], 'edits' => ['exposure' => 1]];
        $operation = $this->postJson($this->endpoint($w), $payload)->assertAccepted()->json('data.operationReceipt.id');
        $this->processWorkspace($w);
        $payload['version'] = $w->fresh()->version;
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.operationReceipt.id', $operation);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
    }

    public function test_failed_original_request_resumes_with_original_receipt_and_old_version(): void
    {
        [$w] = $this->workspace();
        $payload = $this->edit($w);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $w->refresh();
        $operation = $w->operation;
        $w->update(['status' => 'failed', 'error' => 'Worker interrupted']);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $this->assertSame($operation, $w->fresh()->operation);
        $this->processWorkspace($w);
        $this->assertCount(1, $w->fresh()->outputs);
        Queue::assertPushed(ProcessStudioWorkspace::class, 2);
    }

    public function test_cancelled_original_request_replay_reports_cancellation_without_restarting(): void
    {
        [$w] = $this->workspace();
        $payload = $this->edit($w);
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $operation = $w->fresh()->operation['id'];
        $this->postJson($this->endpoint($w, 'cancel'))->assertOk();
        $this->postJson($this->endpoint($w), $payload)->assertAccepted()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.operationReceipt.id', $operation);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
    }

    public function test_worker_rejects_retargeted_media_id_before_reading_its_new_bytes(): void
    {
        [$w] = $this->workspace();
        $this->postJson($this->endpoint($w), $this->edit($w))->assertAccepted();
        $media = $w->fresh()->media;
        $replacement = dirname($media[0]['mediaRef']).'/replacement.jpg';
        Storage::disk('public')->put($replacement, $this->image(200));
        $media[0]['mediaRef'] = $replacement;
        $w->update(['media' => $media]);
        $this->expectConflict(fn () => $this->processWorkspace($w));
        $this->assertEmpty($w->fresh()->outputs);
        Http::assertNothingSent();
    }

    public function test_worker_rejects_changed_content_at_the_same_source_reference(): void
    {
        [$w, $ref] = $this->workspace();
        $this->postJson($this->endpoint($w), $this->edit($w))->assertAccepted();
        Storage::disk('public')->put($ref, $this->image(200));
        $this->expectConflict(fn () => $this->processWorkspace($w));
        $this->assertEmpty($w->fresh()->outputs);
        Http::assertNothingSent();
    }

    public function test_changed_original_is_a_terminal_friendly_worker_failure_without_automatic_retry(): void
    {
        [$w, $ref] = $this->workspace();
        $this->postJson($this->endpoint($w), $this->edit($w))->assertAccepted();
        Storage::disk('public')->put($ref, $this->image(180));
        (new ProcessStudioWorkspace($w->id, $w->fresh()->operation['id']))->handle(app(WorkspaceProcessor::class));
        $this->assertSame('failed', $w->fresh()->status);
        $this->assertSame('The selected original photo changed. Reopen photo tools before starting another edit.', $w->fresh()->error);
        $this->assertEmpty($w->fresh()->outputs);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        Http::assertNothingSent();
    }

    public function test_source_replacement_after_preview_is_rejected_even_without_workspace_version_change(): void
    {
        [$w, $ref] = $this->workspace();
        $payload = $this->edit($w);
        Storage::disk('public')->put($ref, $this->image(180));
        $this->postJson($this->endpoint($w), $payload)->assertConflict();
        $this->postJson($this->endpoint($w, 'edit-preview'), ['version' => $w->version, 'mediaId' => 'photo',
            'original' => true, 'sourceVersion' => $payload['targets'][0]['sourceVersion'], 'edits' => ['exposure' => 0]])->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_workspace_change_during_source_capture_is_rejected_at_reservation(): void
    {
        [$w, , $bytes] = $this->workspace();
        $service = $this->partialMock(WorkspaceMediaService::class);
        $service->shouldReceive('bytes')->once()->andReturnUsing(function () use ($w, $bytes) {
            $w->increment('version');

            return $bytes;
        });
        $this->postJson($this->endpoint($w), $this->edit($w))->assertConflict();
        Queue::assertNothingPushed();
        $this->assertEmpty($w->fresh()->operation);
    }

    public function test_sqlite_write_contention_retries_only_reservation_without_rehashing_or_duplicate_dispatch(): void
    {
        [$w, , $bytes] = $this->workspace();
        $this->partialMock(WorkspaceMediaService::class)->shouldReceive('bytes')->once()->andReturn($bytes);
        $attempts = 0;
        $events = StudioWorkspace::getEventDispatcher();
        StudioWorkspace::setEventDispatcher(clone $events);
        StudioWorkspace::saving(function (StudioWorkspace $record) use ($w, &$attempts): void {
            if ($record->id === $w->id && $record->isDirty('operation') && ++$attempts === 1) {
                throw new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            }
        });
        try {
            $this->postJson($this->endpoint($w), $this->edit($w))->assertAccepted();
        } finally {
            StudioWorkspace::setEventDispatcher($events);
        }
        $this->assertSame(2, $attempts);
        $this->assertSame(2, $w->fresh()->version);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
    }

    #[DataProvider('photoPresets')]
    public function test_custom_region_revision_uses_exact_selected_source_or_output(string $preset, bool $original = true): void
    {
        [$w, $ref, $bytes] = $this->workspace();
        $w->update(['preset_id' => $preset]);
        $path = 'studio/workspaces/'.$w->id.'/later.jpg';
        Storage::disk('public')->put($path, $this->image(220));
        $previous = ['id' => 'later', 'mediaId' => 'photo', 'path' => $path, 'kind' => 'image', 'status' => 'completed', 'version' => 1];
        $w->update(['outputs' => [$previous]]);
        $this->mock(WorkspaceImageOperations::class)->shouldReceive('edit')->once()->andReturnUsing(function ($record, $operation, $item, $source, $prompt) use ($original) {
            $pixels = imagecreatefromstring($source);
            $this->assertEqualsWithDelta($original ? 64 : 220, (imagecolorat($pixels, 10, 10) >> 16) & 255, 4);
            imagedestroy($pixels);
            $this->assertStringContainsString('Remove the chair', $prompt);

            return $this->image(180);
        });
        $this->mock(\App\Services\Studio\WorkspaceAutoenhance::class)->shouldNotReceive('run');
        $payload = ['version' => $w->version, 'requestId' => 'original-revision', 'mediaId' => 'photo', 'original' => true,
            'sourceVersion' => $this->sourceVersions[$w->id],
            'customEdit' => true, 'prompt' => 'Remove the chair', 'region' => ['x' => .25, 'y' => .25, 'width' => .5, 'height' => .5]];
        if (! $original) {
            unset($payload['original'], $payload['sourceVersion'], $payload['version']);
            $payload['outputId'] = 'later';
        }
        $this->postJson($this->endpoint($w, 'revisions'), $payload)->assertAccepted();
        $this->processWorkspace($w);
        $this->assertSame($previous, $w->fresh()->outputs[0]);
        $this->assertCount(2, $w->fresh()->outputs);
        $this->assertSame($bytes, Storage::disk('public')->get($ref));
        $this->postJson($this->endpoint($w, 'revisions'), $payload)->assertAccepted()->assertJsonPath('data.status', 'completed');
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        Http::assertNothingSent();
    }

    public static function photoPresets(): array
    {
        return ['listing original' => ['listing-ready'], 'upscale original' => ['upscale'],
            'staging original' => ['virtual-staging'], 'upscale historical output' => ['upscale', false]];
    }

    public function test_worker_rechecks_shoot_assignment_before_original_read(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $editor->id]);
        $path = 'shoots/'.$shoot->id.'/original.jpg';
        Storage::disk('public')->put($path, $this->image(64));
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'filename' => 'original.jpg', 'stored_filename' => 'original.jpg', 'path' => $path,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $editor->id,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_COMPLETED, 'media_type' => 'edited']);
        Sanctum::actingAs($editor);
        $id = $this->postJson('/api/studio/workspaces', ['name' => 'Original', 'presetId' => 'listing-ready', 'config' => [],
            'media' => [['id' => 'photo', 'fileId' => $file->id, 'shootId' => $shoot->id]]])->assertCreated()->json('data.id');
        $w = StudioWorkspace::findOrFail($id);
        $this->postJson($this->endpoint($w), $this->edit($w))->assertAccepted();
        $shoot->update(['editor_id' => null]);
        $this->partialMock(WorkspaceMediaService::class)->shouldNotReceive('bytes');
        try {
            $this->processWorkspace($w);
            $this->fail('Expected revoked assignment to deny the queued edit.');
        } catch (AuthorizationException) {
            $this->assertEmpty($w->fresh()->outputs);
        }
    }

    public function test_shoot_content_version_changes_invalidate_displayed_original_without_workspace_change(): void
    {
        [$w] = $this->workspace();
        $user = User::findOrFail($w->created_by);
        $shoot = Shoot::factory()->create();
        $file = $this->shootFile($shoot, $user, 'one.jpg');
        $w->update(['media' => [['id' => 'photo', 'fileId' => $file->id, 'shootId' => $shoot->id]]]);
        $this->originalToken($w);
        $token = $this->sourceVersions[$w->id];
        $file->touch();
        $this->assertSame($token, $this->originalToken($w), 'Preview/scan timestamps do not replace source bytes.');
        $payload = $this->edit($w);
        $file->increment('content_version');
        $this->postJson($this->endpoint($w), $payload)->assertConflict();
        $this->assertSame(1, $w->fresh()->version);
        Queue::assertNothingPushed();
    }

    public function test_ready_hdr_original_is_edited_without_touching_merge_or_exposures(): void
    {
        Storage::fake('studio_hdr');
        [$w] = $this->workspace();
        $user = User::findOrFail($w->created_by);
        $shoot = Shoot::factory()->create();
        $files = [$this->shootFile($shoot, $user, 'one.jpg'), $this->shootFile($shoot, $user, 'two.jpg')];
        $hdr = app(\App\Services\Studio\WorkspaceHdrService::class);
        $media = $hdr->describe(array_map(fn ($file) => $file->id, $files), $user, 100);
        $path = $hdr->path($media);
        $bytes = $this->image(64);
        Storage::disk('studio_hdr')->put($path, $bytes);
        $w->update(['media' => [$media]]);
        $response = $this->postJson($this->endpoint($w, 'edit-preview'), ['version' => 1, 'mediaId' => $media['id'], 'original' => true, 'edits' => ['exposure' => 0]])->assertOk();
        $payload = ['version' => 1, 'requestId' => 'original-hdr-adjust', 'targets' => [['mediaId' => $media['id'], 'original' => true,
            'sourceVersion' => $response->headers->get('X-Studio-Source-Version')]], 'edits' => ['exposure' => 1]];
        $this->postJson($this->endpoint($w), $payload)->assertAccepted();
        $this->processWorkspace($w);
        $this->assertCount(1, $w->fresh()->outputs);
        $this->assertSame($bytes, Storage::disk('studio_hdr')->get($path));
        foreach ($files as $file) {
            $this->assertSame($bytes, Storage::disk('public')->get($file->path));
        }
        Http::assertNothingSent();
    }

    private function shootFile(Shoot $shoot, User $user, string $name): ShootFile
    {
        $path = 'shoots/'.$shoot->id.'/'.$name;
        Storage::disk('public')->put($path, $this->image(64));

        return ShootFile::create(['shoot_id' => $shoot->id, 'filename' => $name, 'stored_filename' => $name, 'path' => $path,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => strlen($this->image(64)), 'uploaded_by' => $user->id,
            'content_version' => 1, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_TODO, 'media_type' => 'raw']);
    }

    public function test_capability_is_exposed_without_requiring_a_provider_and_video_originals_are_rejected(): void
    {
        [$w] = $this->workspace();
        $this->getJson('/api/studio/workspaces/catalog')->assertOk()->assertJsonPath('data.controls.originalPhotoTools', true);
        $this->getJson('/api/studio/workspaces/'.$w->id)->assertOk()->assertJsonPath('data.capabilities.originalPhotoTools', true);
        $w->update(['preset_id' => 'property-reel']);
        $this->postJson($this->endpoint($w), $this->edit($w))->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    private function workspace(): array
    {
        $user = User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 100]]);
        Sanctum::actingAs($user);
        $ref = 'studio/uploads/100/'.$user->id.'/original.jpg';
        $bytes = $this->image(64);
        Storage::disk('public')->put($ref, $bytes);
        $id = $this->postJson('/api/studio/workspaces', ['name' => 'Original tools', 'presetId' => 'listing-ready', 'config' => [],
            'media' => [['id' => 'photo', 'mediaRef' => $ref]]])->assertCreated()->json('data.id');

        $workspace = StudioWorkspace::findOrFail($id);
        $this->originalToken($workspace);

        return [$workspace, $ref, $bytes];
    }

    private function edit(StudioWorkspace $w): array
    {
        return ['version' => $w->version, 'requestId' => 'original-adjust',
            'targets' => [['mediaId' => 'photo', 'original' => true, 'sourceVersion' => $this->sourceVersions[$w->id] ?? $this->originalToken($w)]], 'edits' => ['exposure' => 1]];
    }

    private function originalToken(StudioWorkspace $w): string
    {
        $response = $this->postJson($this->endpoint($w, 'edit-preview'), ['version' => $w->version,
            'mediaId' => 'photo', 'original' => true, 'edits' => ['exposure' => 0]])->assertOk();

        return $this->sourceVersions[$w->id] = $response->headers->get('X-Studio-Source-Version');
    }

    private function endpoint(StudioWorkspace $w, string $action = 'edits'): string
    {
        return '/api/studio/workspaces/'.$w->id.'/'.$action;
    }

    private function processWorkspace(StudioWorkspace $w): void
    {
        $w->refresh();
        app(WorkspaceProcessor::class)->process($w, $w->operation['id']);
    }

    private function expectConflict(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a stale source conflict.');
        } catch (StudioProviderException $exception) {
            $this->assertFalse($exception->ambiguous);
            $this->assertStringContainsString('original photo changed', $exception->getMessage());
        }
    }

    private function image(int $red): string
    {
        $image = imagecreatetruecolor(80, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 20, 20));
        ob_start();
        imagejpeg($image, null, 98);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
