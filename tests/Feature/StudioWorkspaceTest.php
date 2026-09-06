<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['studio_uploads.disk' => 'public']);
    }

    public function test_upload_sources_are_canonical_and_create_is_idempotent(): void
    {
        $user = $this->actor();
        $payload = $this->payload($user);
        $payload['requestId'] = 'one-create';
        $payload['media'][0]['url'] = 'http://127.0.0.1/private';
        $first = $this->postJson('/api/studio/workspaces', $payload)->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.config.transition', 'none');
        $this->assertStringNotContainsString('127.0.0.1', $first->json('data.media.0.url'));
        $this->postJson('/api/studio/workspaces', ['requestId' => 'one-create'])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, StudioWorkspace::count());
        Queue::assertNothingPushed();
    }

    public function test_cross_account_and_traversal_sources_are_rejected(): void
    {
        $user = $this->actor();
        $payload = $this->payload($user);
        $payload['media'][0]['mediaRef'] = 'studio/uploads/100/999/photo.jpg';
        $this->postJson('/api/studio/workspaces', $payload)->assertForbidden();
        $payload['media'][0]['mediaRef'] = "studio/uploads/100/{$user->id}/../secret.jpg";
        $this->postJson('/api/studio/workspaces', $payload)->assertForbidden();
        unset($payload['media'][0]['mediaRef']);
        $payload['media'][0]['url'] = 'https://example.com/source.jpg';
        $this->postJson('/api/studio/workspaces', $payload)->assertForbidden();
        $this->assertSame(0, StudioWorkspace::count());
    }

    public function test_blank_optional_strings_survive_http_create_patch_and_legacy_reads_as_strings(): void
    {
        $user = $this->actor();
        $payload = $this->payload($user);
        $payload['config'] = [
            'prompt' => '', 'text' => ['title' => '', 'subtitle' => ''],
            'frames' => [['mediaId' => 'm1', 'method' => 'fit', 'prompt' => '']],
        ];
        // Use the full HTTP middleware stack, including ConvertEmptyStringsToNull.
        $response = $this->postJson('/api/studio/workspaces', $payload)->assertCreated();
        foreach (['prompt', 'text.title', 'text.subtitle', 'frames.0.prompt'] as $field) {
            $response->assertJsonPath('data.config.'.$field, '');
        }
        $record = StudioWorkspace::findOrFail($response->json('data.id'));
        $this->assertSame('', data_get($record->config, 'text.title'));
        $this->assertSame('', data_get($record->config, 'frames.0.prompt'));

        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => [
            'prompt' => null, 'text' => ['title' => '123 Test Street', 'subtitle' => null],
            'frames' => [['mediaId' => 'm1', 'method' => 'fit', 'prompt' => null]],
        ]])->assertOk()->assertJsonPath('data.config.prompt', '')
            ->assertJsonPath('data.config.text.title', '123 Test Street')
            ->assertJsonPath('data.config.text.subtitle', '')
            ->assertJsonPath('data.config.frames.0.prompt', '');
        $this->assertSame('', data_get($record->fresh()->config, 'text.subtitle'));

        $legacy = $record->fresh()->config;
        $legacy['prompt'] = $legacy['text']['title'] = $legacy['text']['subtitle'] = $legacy['frames'][0]['prompt'] = null;
        $record->update(['config' => $legacy]);
        $read = $this->getJson('/api/studio/workspaces/'.$record->id)->assertOk();
        foreach (['prompt', 'text.title', 'text.subtitle', 'frames.0.prompt'] as $field) {
            $read->assertJsonPath('data.config.'.$field, '');
        }
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonPath('data.0.config.text.title', '');
        Queue::assertNothingPushed();
    }

    public function test_uploaded_raw_preview_is_authorized_cached_and_used_by_new_and_existing_workspaces(): void
    {
        config(['studio.client_access_enabled' => true]);
        $user = $this->actor('client');
        $upload = $this->post('/api/studio/workspaces/sources/uploads', [
            'workflow' => 'photo-enhancement',
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('room.nef', 10, 'application/octet-stream')],
        ])->assertCreated()->assertJsonPath('data.accepted.0.mediaType', 'raw');
        $media = $upload->json('data.accepted.0');
        $url = $media['previewUrl'];
        $this->assertStringContainsString('/api/studio/workspaces/sources/uploads/preview?mediaRef=', $url);
        $this->partialMock(\App\Services\RawThumbnailService::class)->shouldReceive('extractFullSizeJpeg')->once()->andReturnUsing(function () {
            $path = tempnam(sys_get_temp_dir(), 'raw-preview-test-');
            file_put_contents($path, $this->image(70, 120, 180));

            return $path;
        });
        $this->mock(FalService::class)->shouldNotReceive('submitModel');
        $first = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($first->getContent())[2]);
        $this->get($url)->assertOk()->assertContent($first->getContent());
        $this->assertCount(1, Storage::disk('local')->allFiles("studio/previews/100/{$user->id}"));

        $created = $this->postJson('/api/studio/workspaces', [
            'name' => 'RAW upload', 'presetId' => 'full-shoot',
            'media' => [['id' => 'raw1', 'mediaRef' => $media['mediaRef']]],
        ])->assertCreated()->assertJsonPath('data.media.0.url', $url)->assertJsonPath('data.media.0.thumbnailUrl', $url);
        $record = StudioWorkspace::findOrFail($created->json('data.id'));
        $this->assertSame($url, $record->media[0]['url']);
        $legacy = $record->media;
        $legacy[0]['url'] = $legacy[0]['thumbnailUrl'] = 'https://example.test/old-original.nef';
        $record->update(['media' => $legacy]);
        $this->getJson('/api/studio/workspaces/'.$record->id)->assertOk()->assertJsonPath('data.media.0.url', $url);
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonPath('data.0.media.0.thumbnailUrl', $url);

        $this->actor('client');
        $this->get($url)->assertForbidden();
        Sanctum::actingAs($user);
        $this->get('/api/studio/workspaces/sources/uploads/preview?'.http_build_query(['mediaRef' => "studio/uploads/100/{$user->id}/../other.nef"]))->assertForbidden();
        Storage::disk('public')->delete($media['mediaRef']);
        $this->get($url)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_failed_raw_conversion_is_not_cached_and_can_be_retried(): void
    {
        $user = $this->actor();
        $ref = "studio/uploads/100/{$user->id}/room.cr2";
        Storage::disk('public')->put($ref, 'mock raw bytes');
        $url = '/api/studio/workspaces/sources/uploads/preview?'.http_build_query(['mediaRef' => $ref]);
        $attempt = 0;
        $this->partialMock(\App\Services\RawThumbnailService::class)->shouldReceive('extractFullSizeJpeg')->twice()->andReturnUsing(function () use (&$attempt) {
            if (++$attempt === 1) {
                return null;
            }
            $path = tempnam(sys_get_temp_dir(), 'raw-preview-test-');
            file_put_contents($path, $this->image(100, 100, 100));

            return $path;
        });
        $this->getJson($url)->assertUnprocessable()->assertJsonPath('message', 'This RAW image has no supported browser preview.');
        $this->assertCount(0, Storage::disk('local')->allFiles('studio/previews'));
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        Queue::assertNothingPushed();
    }

    public function test_workspace_reads_are_isolated_by_team_and_owner(): void
    {
        $user = $this->actor('editor');
        $record = $this->create($user);
        $other = $this->actor('editor');
        $this->getJson('/api/studio/workspaces/'.$record->id)->assertForbidden();
        $this->getJson('/api/studio/workspaces')->assertOk()->assertJsonCount(0, 'data');
        $this->actor('admin', 200);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['name' => 'Overwrite'])->assertForbidden();
    }

    public function test_patch_replaces_frame_array_and_rejects_stale_versions(): void
    {
        $user = $this->actor();
        $record = $this->create($user, 2);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['version' => 1, 'config' => ['frames' => [['mediaId' => 'm1', 'method' => 'crop', 'duration' => 5]]]])->assertOk()->assertJsonCount(1, 'data.config.frames')->assertJsonPath('data.version', 2);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['version' => 1, 'name' => 'Stale'])->assertConflict();
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['frames' => []]])->assertOk()->assertJsonCount(0, 'data.config.frames');
    }

    public function test_generation_requires_all_video_frames_and_deduplicates_preparation(): void
    {
        $user = $this->actor();
        $record = $this->create($user, 2, 'walkthrough');
        $this->postJson('/api/studio/workspaces/'.$record->id.'/generate')->assertUnprocessable();
        $this->postJson('/api/studio/workspaces/'.$record->id.'/prepare')->assertAccepted()->assertJsonPath('data.status', 'preparing');
        $this->postJson('/api/studio/workspaces/'.$record->id.'/prepare')->assertAccepted();
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['name' => 'Busy'])->assertConflict();
        $this->postJson('/api/studio/workspaces/'.$record->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $record->refresh();
        (new ProcessStudioWorkspace($record->id, $record->operation['id']))->handle(app(WorkspaceProcessor::class));
        $this->assertSame([], $record->fresh()->prepared_frames ?? []);
    }

    public function test_real_local_crop_produces_target_ratio_without_provider_call(): void
    {
        $user = $this->actor();
        $record = $this->create($user);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['ratio' => '1:1', 'frames' => [['mediaId' => 'm1', 'method' => 'crop']]]])->assertOk();
        $this->postJson('/api/studio/workspaces/'.$record->id.'/prepare')->assertAccepted();
        $this->mock(FalService::class)->shouldNotReceive('submitModel');
        $record->refresh();
        (new ProcessStudioWorkspace($record->id, $record->operation['id']))->handle(app(WorkspaceProcessor::class));
        $record->refresh();
        $frame = $record->prepared_frames[0];
        $this->assertSame('ready', $record->status);
        $size = getimagesizefromstring(Storage::disk('public')->get($frame['path']));
        $this->assertSame($size[0], $size[1]);
        $this->assertSame('completed', $frame['status']);
        $this->assertSame('m1', $frame['mediaId']);
    }

    public function test_subset_generation_and_region_revision_keep_per_asset_versions(): void
    {
        $user = $this->actor();
        $record = $this->create($user, 2);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['frames' => [['mediaId' => 'm2', 'method' => 'fit']]]])->assertOk();
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitImageEditFromBuffer')->twice()->andReturn(['request_id' => 'provider-1']);
        $fal->shouldReceive('imageEditStatus')->twice()->andReturn(['status' => 'completed']);
        $fal->shouldReceive('imageEditResult')->twice()->andReturn(['edited_image_url' => 'data:image/jpeg;base64,'.base64_encode($this->image(0, 0, 255))]);
        $this->postJson('/api/studio/workspaces/'.$record->id.'/generate')->assertAccepted();
        $record->refresh();
        (new ProcessStudioWorkspace($record->id, $record->operation['id']))->handle(app(WorkspaceProcessor::class));
        $record->refresh();
        $this->assertCount(1, $record->outputs);
        $this->assertSame('m2', $record->outputs[0]['mediaId']);
        $this->postJson('/api/studio/workspaces/'.$record->id.'/revisions', ['mediaId' => 'm2', 'prompt' => 'Fix the wall', 'region' => ['x' => 0.25, 'y' => 0.25, 'width' => 0.5, 'height' => 0.5]])->assertAccepted();
        $record->refresh();
        (new ProcessStudioWorkspace($record->id, $record->operation['id']))->handle(app(WorkspaceProcessor::class));
        $record->refresh();
        $this->assertCount(2, $record->outputs);
        $this->assertSame(2, $record->outputs[1]['version']);
        $this->assertSame('m2', $record->history[1]['payload']['mediaId']);
    }

    public function test_invalid_region_and_unknown_frames_do_not_enqueue_work(): void
    {
        $user = $this->actor();
        $record = $this->create($user);
        $this->postJson('/api/studio/workspaces/'.$record->id.'/revisions', ['mediaId' => 'm1', 'prompt' => 'Fix', 'region' => ['x' => 0.9, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]])->assertUnprocessable();
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['frames' => [['mediaId' => 'other', 'method' => 'crop']]]])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_six_selected_frames_from_a_52_photo_library_prepare_and_generate(): void
    {
        $user = $this->actor();
        $record = $this->create($user, 52, 'walkthrough');
        $frames = array_map(fn ($i) => ['mediaId' => 'm'.$i, 'method' => 'crop', 'duration' => 5], range(1, 6));
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['frames' => $frames]])->assertOk();
        $this->postJson('/api/studio/workspaces/'.$record->id.'/prepare')->assertAccepted();
        $record->refresh();
        (new ProcessStudioWorkspace($record->id, $record->operation['id']))->handle(app(WorkspaceProcessor::class));
        $record->refresh();
        $this->assertCount(6, $record->prepared_frames);
        $this->assertCount(52, $record->media);
        $this->postJson('/api/studio/workspaces/'.$record->id.'/generate')->assertAccepted()->assertJsonPath('data.status', 'generating');
    }

    public function test_failed_job_is_visible_and_retry_reuses_provider_checkpoints(): void
    {
        $user = $this->actor();
        $record = $this->create($user);
        $this->postJson('/api/studio/workspaces/'.$record->id.'/generate', ['requestId' => 'same-generation'])->assertAccepted();
        $record->refresh();
        $id = $record->operation['id'];
        $operation = $record->operation;
        $operation['requests'] = ['m1' => 'existing-provider-request'];
        $record->update(['operation' => $operation]);
        (new ProcessStudioWorkspace($record->id, $id))->failed(new \RuntimeException('provider unavailable'));
        $this->getJson('/api/studio/workspaces/'.$record->id)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->postJson('/api/studio/workspaces/'.$record->id.'/generate', ['requestId' => 'same-generation'])->assertAccepted();
        $this->assertSame($id, $record->fresh()->operation['id']);
        $this->assertSame('existing-provider-request', $record->fresh()->operation['requests']['m1']);
    }

    public function test_terminal_provider_failure_is_replaced_without_repeating_completed_subset_outputs(): void
    {
        $user = $this->actor();
        $record = $this->create($user, 12);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['frames' => [['mediaId' => 'm2', 'method' => 'fit'], ['mediaId' => 'm3', 'method' => 'fit']]]])->assertOk();
        $fal = $this->mock(FalService::class);
        $fal->shouldReceive('submitImageEditFromBuffer')->times(3)->andReturn(['request_id' => 'done'], ['request_id' => 'failed'], ['request_id' => 'replacement']);
        $fal->shouldReceive('imageEditStatus')->with('done')->once()->andReturn(['status' => 'completed']);
        $fal->shouldReceive('imageEditStatus')->with('failed')->once()->andReturn(['status' => 'failed']);
        $fal->shouldReceive('imageEditStatus')->with('replacement')->once()->andReturn(['status' => 'completed']);
        $fal->shouldReceive('imageEditResult')->twice()->andReturn(['edited_image_url' => 'data:image/jpeg;base64,'.base64_encode($this->image(0, 255, 0))]);
        $this->postJson('/api/studio/workspaces/'.$record->id.'/generate')->assertAccepted();
        $record->refresh();
        $job = new ProcessStudioWorkspace($record->id, $record->operation['id']);
        try {
            $job->handle(app(WorkspaceProcessor::class));
            $this->fail('Expected provider failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('provider could not complete', $exception->getMessage());
        }
        $record->refresh();
        $this->assertArrayNotHasKey('m3', $record->operation['requests']);
        $this->assertSame(['m2'], $record->operation['completed']);
        $job->handle(app(WorkspaceProcessor::class));
        $record->refresh();
        $this->assertSame('completed', $record->status);
        $this->assertCount(2, $record->outputs);
        $this->assertSame(['m2', 'm3'], array_column($record->outputs, 'mediaId'));
    }

    public function test_clients_cannot_edit_raw_unreleased_or_unpaid_shoot_media(): void
    {
        config(['studio.client_access_enabled' => true]);
        $user = $this->actor('client');
        $shoot = Shoot::factory()->create(['client_id' => $user->id, 'payment_status' => 'unpaid', 'total_quote' => 100]);
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'filename' => 'photo.jpg', 'path' => 'photo.jpg', 'stored_filename' => 'photo.jpg', 'file_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $user->id, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_COMPLETED]);
        $payload = ['name' => 'Client edit', 'presetId' => 'listing-ready', 'media' => [['id' => 'm1', 'fileId' => $file->id, 'shootId' => $shoot->id]], 'config' => []];
        $this->postJson('/api/studio/workspaces', $payload)->assertForbidden();
        $shoot->update(['payment_status' => 'paid']);
        $created = $this->postJson('/api/studio/workspaces', $payload)->assertCreated();
        $shoot->update(['payment_status' => 'unpaid']);
        $this->postJson('/api/studio/workspaces/'.$created->json('data.id').'/segments', ['mediaId' => 'm1'])->assertForbidden();
        $shoot->update(['payment_status' => 'paid']);
        $file->update(['workflow_stage' => ShootFile::STAGE_TODO]);
        $this->postJson('/api/studio/workspaces', $payload)->assertForbidden();
        $this->getJson('/api/studio/templates')->assertForbidden();
    }

    public function test_client_source_aliases_resolve_only_own_released_images(): void
    {
        config(['studio.client_access_enabled' => true]);
        $user = $this->actor('client');
        $shoot = Shoot::factory()->create(['client_id' => $user->id, 'payment_status' => 'paid', 'address' => '123 Source Street']);
        $file = ShootFile::create(['shoot_id' => $shoot->id, 'filename' => 'photo.jpg', 'path' => 'photo.jpg', 'stored_filename' => 'photo.jpg', 'file_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $user->id, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'workflow_stage' => ShootFile::STAGE_COMPLETED]);
        $this->getJson('/api/studio/workspaces/sources/shoots?q=Source')->assertOk()->assertJsonPath('data.0.id', $shoot->id);
        $this->getJson('/api/studio/workspaces/sources/shoots/'.$shoot->id.'/media?workflow=photo-enhancement')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/studio/workspaces/sources/resolve', ['destination' => 'studio', 'recordType' => 'shoot', 'recordId' => (string) $shoot->id])->assertOk()->assertJsonPath('data.record.address', '123 Source Street');
        $other = Shoot::factory()->create();
        $this->getJson('/api/studio/workspaces/sources/shoots/'.$other->id.'/media?workflow=photo-enhancement')->assertNotFound();
        $shoot->update(['payment_status' => 'unpaid']);
        $this->getJson('/api/studio/workspaces/sources/shoots/'.$shoot->id.'/media?workflow=photo-enhancement')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_changing_ratio_invalidates_prepared_frames_but_render_styling_does_not(): void
    {
        $user = $this->actor();
        $record = $this->create($user, 1, 'walkthrough');
        $record->update(['prepared_frames' => [['mediaId' => 'm1', 'status' => 'completed', 'ratio' => '9:16', 'url' => '/prepared.jpg', 'method' => 'crop']], 'status' => 'ready']);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['transition' => 'fade', 'text' => ['style' => 'graphic']]])->assertOk()->assertJsonCount(1, 'data.preparedFrames');
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['ratio' => '16:9']])->assertOk()->assertJsonCount(0, 'data.preparedFrames');
    }

    public function test_full_shoot_draft_accepts_156_sources_without_claiming_bracket_merge(): void
    {
        $user = $this->actor();
        $record = $this->create($user, 156, 'full-shoot');
        $this->assertCount(156, $record->media);
        $this->assertSame([], $record->outputs ?? []);
        Queue::assertNothingPushed();
    }

    public function test_saving_review_choices_does_not_invalidate_completed_generation(): void
    {
        $user = $this->actor();
        $record = $this->create($user);
        $record->update(['status' => 'completed', 'progress' => 100, 'operation' => ['id' => 'existing', 'type' => 'generate']]);
        $this->patchJson('/api/studio/workspaces/'.$record->id, ['config' => ['reviewedOutputIds' => ['version-1']]])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.progress', 100);
        $this->assertSame('existing', $record->fresh()->operation['id']);
    }

    public function test_output_download_is_exact_and_scoped_and_config_cannot_seed_runtime(): void
    {
        config(['studio.client_access_enabled' => true]);
        $user = $this->actor('client');
        $payload = $this->payload($user);
        $payload['config'] = ['_studioRuntime' => ['clips' => ['/etc/passwd']], 'sourceDisk' => 'local', 'studioWorkspaceId' => 'someone-else'];
        $response = $this->postJson('/api/studio/workspaces', $payload)->assertCreated();
        $record = StudioWorkspace::findOrFail($response->json('data.id'));
        $this->assertArrayNotHasKey('_studioRuntime', $record->config);
        $this->assertArrayNotHasKey('sourceDisk', $record->config);
        $this->assertArrayNotHasKey('studioWorkspaceId', $record->config);
        $path = "studio/workspaces/{$record->id}/version-2.jpg";
        $bytes = $this->image(0, 150, 0);
        Storage::disk('public')->put($path, $bytes);
        $record->update(['outputs' => [['id' => 'v2-m1', 'mediaId' => 'm1', 'status' => 'completed', 'path' => $path, 'url' => Storage::disk('public')->url($path)]]]);
        $endpoint = '/api/studio/workspaces/'.$record->id.'/outputs/v2-m1/download';
        $download = $this->get($endpoint)->assertOk()->assertDownload('version-2.jpg');
        $this->assertSame($bytes, $download->streamedContent());
        $this->get('/api/studio/workspaces/'.$record->id.'/outputs/not-an-output/download')->assertNotFound();
        $this->actor('client');
        $this->get($endpoint)->assertForbidden();
        Sanctum::actingAs($user);
        Storage::disk('public')->delete($path);
        $this->get($endpoint)->assertNotFound();
    }

    private function actor(string $role = 'admin', int $team = 100): User
    {
        $user = User::factory()->create(['role' => $role, 'metadata' => ['team_id' => $team]]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function create(User $user, int $count = 1, string $preset = 'listing-ready'): StudioWorkspace
    {
        $payload = $this->payload($user, $count);
        $payload['presetId'] = $preset;
        $response = $this->postJson('/api/studio/workspaces', $payload)->assertCreated();

        return StudioWorkspace::findOrFail($response->json('data.id'));
    }

    private function payload(User $user, int $count = 1): array
    {
        $media = [];
        for ($i = 1; $i <= $count; $i++) {
            $ref = "studio/uploads/100/{$user->id}/photo-{$i}.jpg";
            Storage::disk('public')->put($ref, $this->image(255, 0, 0));
            $media[] = ['id' => 'm'.$i, 'mediaRef' => $ref];
        }

        return ['name' => 'Property edit', 'presetId' => 'listing-ready', 'media' => $media, 'config' => []];
    }

    private function image(int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor(160, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        ob_start();
        imagejpeg($image, null, 95);

        return ob_get_clean();
    }
}
