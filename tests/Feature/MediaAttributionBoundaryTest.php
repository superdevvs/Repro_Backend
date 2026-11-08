<?php

namespace Tests\Feature;

use App\Jobs\{IngestIguideAssetsJob, ProcessAutoenhanceEditingJob, ProcessFalEditingJob, UploadShootAlbumMediaJob};
use App\Models\{AiEditingJob, Service, Shoot, ShootFile, ShootMediaAlbum, ShootService, User};
use App\Services\ShootActivityLogger;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\{LegacyAiOutputPublisher, ShootAuthorizationSupport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaAttributionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Storage::fake('local'); Storage::fake('public');
        config(['clamav.scan_on_upload' => false]);
    }

    private function shoot(): Shoot
    {
        return Shoot::factory()->create(['status' => 'editing', 'workflow_status' => 'editing', 'editor_id' => null,
            'scheduled_at' => now()->subDay(), 'total_quote' => 100, 'payment_status' => 'paid']);
    }

    private function item(Shoot $shoot, ?Service $catalog = null, array $attributes = []): ShootService
    {
        return $shoot->serviceItems()->create($attributes + ['service_id' => ($catalog ?? Service::factory()->unbracketedPhoto()->create(['requires_editing' => true]))->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
    }

    private function file(Shoot $shoot, ?ShootService $item, User $actor, array $attributes = []): ShootFile
    {
        return ShootFile::create($attributes + ['shoot_id' => $shoot->id, 'shoot_service_id' => $item?->id,
            'filename' => 'photo.jpg', 'stored_filename' => 'photo.jpg', 'path' => 'shoots/'.$shoot->id.'/photo.jpg',
            'file_type' => 'image/jpeg', 'file_size' => 100, 'media_type' => 'edited', 'workflow_stage' => 'completed', 'uploaded_by' => $actor->id]);
    }

    public function test_staff_upload_infers_the_only_compatible_outstanding_service(): void
    {
        $shoot = $this->shoot(); $photo = $this->item($shoot);
        $this->item($shoot, Service::factory()->videoIntake()->create());
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/api/shoots/'.$shoot->id.'/upload', ['upload_type' => 'edited', 'files' => [UploadedFile::fake()->image('accepted.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success_count', 1);
        $this->assertSame($photo->id, $shoot->files()->firstOrFail()->shoot_service_id);
    }

    public function test_staff_ambiguous_upload_requires_a_service_and_creates_no_orphan(): void
    {
        $shoot = $this->shoot(); $this->item($shoot); $this->item($shoot);
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $this->post('/api/shoots/'.$shoot->id.'/upload', ['upload_type' => 'edited', 'files' => [UploadedFile::fake()->image('ambiguous.jpg')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error_type', 'missing_service_item');
        $this->assertSame(0, $shoot->files()->count());
    }

    public function test_jpeg_cannot_be_uploaded_as_video_even_when_category_is_spoofed(): void
    {
        $shoot = $this->shoot(); $video = $this->item($shoot, Service::factory()->videoIntake()->create(['requires_editing' => true]));
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/api/shoots/'.$shoot->id.'/upload', ['upload_type' => 'edited', 'shoot_service_id' => $video->id,
            'media_type' => 'video', 'service_category' => 'video', 'files' => [UploadedFile::fake()->image('not-video.jpg')]], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(0, $shoot->files()->count());
        $this->assertSame('not_started', $video->fresh()->delivery_status);
    }

    public function test_bundle_editors_cannot_upload_into_each_others_content_lane(): void
    {
        $shoot = $this->shoot();
        $photo = User::factory()->create(['role' => 'editor']); $video = User::factory()->create(['role' => 'editor']);
        $item = $this->item($shoot, Service::factory()->photoVideoIntake()->create(['requires_editing' => true]), ['editor_id' => $photo->id, 'video_editor_id' => $video->id]);
        Sanctum::actingAs($video);
        $this->post('/api/shoots/'.$shoot->id.'/upload', ['upload_type' => 'edited', 'shoot_service_id' => $item->id,
            'media_type' => 'video', 'files' => [UploadedFile::fake()->image('photo.jpg')]], ['Accept' => 'application/json'])->assertForbidden();
        $path = tempnam(sys_get_temp_dir(), 'lane-video-');
        try {
            file_put_contents($path, pack('N', 24).'ftypXAVC'.pack('N', 0).'XAVCmp42'.str_repeat("\0", 4096));
            Sanctum::actingAs($photo);
            $this->post('/api/shoots/'.$shoot->id.'/upload', ['upload_type' => 'edited', 'shoot_service_id' => $item->id,
                'files' => [new UploadedFile($path, 'tour.mp4', null, null, true)]], ['Accept' => 'application/json'])->assertForbidden();
        } finally { @unlink($path); }
        $this->assertSame(0, $shoot->files()->count());
    }

    public function test_iguide_url_alone_is_not_provider_readiness(): void
    {
        $service = app(\App\Services\IguideService::class);
        $data = ['id' => 'provider-one', 'urls' => ['publicUrl' => 'https://youriguide.com/provider-one/']];
        $this->assertNull($service->parsePropertyData($data)['delivery_readiness']);
        $ready = $service->parsePropertyData($data + ['type' => 'ready'])['delivery_readiness'];
        $this->assertSame('ready', $ready['state']);
        $this->assertSame('provider_ready_event', $ready['source']);
        $this->assertSame('https://youriguide.com/provider-one/', $ready['tour_url']);
        $pending = $service->parsePropertyData($data + ['status' => 'not_ready'])['delivery_readiness'];
        $this->assertSame('not_ready', $pending['state']);
    }

    public function test_edited_upload_preserves_explicit_service_cancellation(): void
    {
        $shoot = $this->shoot(); $item = $this->item($shoot, null, ['workflow_status' => 'cancelled', 'delivery_status' => 'cancelled']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/api/shoots/'.$shoot->id.'/upload', ['upload_type' => 'edited', 'shoot_service_id' => $item->id,
            'files' => [UploadedFile::fake()->image('cancelled.jpg')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame('cancelled', $item->fresh()->workflow_status);
        $this->assertSame('cancelled', $item->fresh()->delivery_status);
        $this->assertSame(0, $shoot->files()->count());
    }

    public function test_editor_cannot_inherit_another_editors_album_scope(): void
    {
        $shoot = $this->shoot(); $editor = User::factory()->create(['role' => 'editor']);
        $this->item($shoot, null, ['editor_id' => $editor->id]);
        $other = $this->item($shoot, null, ['editor_id' => User::factory()->create(['role' => 'editor'])->id]);
        $album = ShootMediaAlbum::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $other->id, 'source' => 'local', 'folder_path' => 'albums/other']);
        Sanctum::actingAs($editor);
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $editor, 'edited', $other->id));
        $this->post('/api/shoots/'.$shoot->id.'/media', ['type' => 'edited', 'album_id' => $album->id,
            'files' => [UploadedFile::fake()->image('bypass.jpg')]], ['Accept' => 'application/json'])->assertForbidden();
        Queue::assertNotPushed(UploadShootAlbumMediaJob::class);
    }

    public function test_album_and_explicit_service_must_agree(): void
    {
        $shoot = $this->shoot(); $a = $this->item($shoot); $b = $this->item($shoot);
        $album = ShootMediaAlbum::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $b->id, 'source' => 'local', 'folder_path' => 'albums/b']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/api/shoots/'.$shoot->id.'/media', ['type' => 'edited', 'album_id' => $album->id, 'shoot_service_id' => $a->id,
            'files' => [UploadedFile::fake()->image('mismatch.jpg')]], ['Accept' => 'application/json'])->assertUnprocessable();
        Queue::assertNotPushed(UploadShootAlbumMediaJob::class);
    }

    public function test_album_worker_rechecks_assignment_before_creating_media(): void
    {
        $shoot = $this->shoot(); $editor = User::factory()->create(['role' => 'editor']);
        $item = $this->item($shoot, null, ['editor_id' => $editor->id]);
        $album = ShootMediaAlbum::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'source' => 'local', 'folder_path' => 'albums/a']);
        $upload = UploadedFile::fake()->image('queued.jpg');
        $path = $upload->store('temp/uploads', 'local');
        $job = new UploadShootAlbumMediaJob($shoot, $album, $path, 'queued.jpg', 'edited', $editor->id, null, $item->id);
        $item->update(['editor_id' => User::factory()->create(['role' => 'editor'])->id]);
        try { $job->handle(app(ShootMediaStorageService::class), app(ShootActivityLogger::class)); $this->fail('A revoked assignment was accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertSame(0, $shoot->files()->count());
        Storage::disk('local')->assertExists($path);
    }

    public function test_album_worker_uses_its_actor_without_http_auth_and_retries_after_cleanup(): void
    {
        $shoot = $this->shoot(); $admin = User::factory()->create(['role' => 'admin']); $item = $this->item($shoot);
        $album = ShootMediaAlbum::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'source' => 'local', 'folder_path' => 'albums/admin']);
        $path = UploadedFile::fake()->image('accepted-worker.jpg')->store('temp/uploads', 'local');
        $job = new UploadShootAlbumMediaJob($shoot, $album, $path, 'accepted-worker.jpg', 'edited', $admin->id, null, $item->id);
        $job->handle(app(ShootMediaStorageService::class), app(ShootActivityLogger::class));
        $job->handle(app(ShootMediaStorageService::class), app(ShootActivityLogger::class));
        $this->assertSame(1, $shoot->files()->count());
        $file = $shoot->files()->firstOrFail();
        $this->assertSame($admin->id, $file->uploaded_by);
        $this->assertSame($item->id, $file->shoot_service_id);
        $this->assertSame($album->id, $file->album_id);
    }

    public function test_all_three_legacy_ai_writers_preserve_source_execution_and_retries_do_not_duplicate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']); $shoot = $this->shoot(); $item = $this->item($shoot);
        $source = $this->file($shoot, $item, $admin);
        $data = 'data:image/jpeg;base64,'.base64_encode('generated image');
        $retry = 'data:image/jpeg;base64,'.base64_encode('different retry bytes');
        foreach (['autoenhance', 'fal', 'poll'] as $provider) {
            $job = AiEditingJob::create(['shoot_id' => $shoot->id, 'shoot_file_id' => $source->id, 'user_id' => $admin->id,
                'original_image_url' => 'https://source.test/photo.jpg', 'provider' => $provider === 'poll' ? 'autoenhance' : $provider, 'status' => 'processing', 'editing_type' => 'enhance']);
            if ($provider === 'poll') {
                $object = (new \ReflectionClass(\App\Http\Controllers\API\AutoenhanceController::class))->newInstanceWithoutConstructor();
                $method = new \ReflectionMethod($object, 'persistEnhancedImageForJob');
                $this->assertNotNull($method->invoke($object, $job, $data));
                $this->assertNotNull($method->invoke($object, $job, $retry));
            } else {
                $object = $provider === 'fal' ? new ProcessFalEditingJob($job) : new ProcessAutoenhanceEditingJob($job);
                $method = new \ReflectionMethod($object, $provider === 'fal' ? 'storeEditedImage' : 'storeEnhancedImage');
                $this->assertNotNull($method->invoke($object, $data));
                $this->assertNotNull($method->invoke($object, $retry));
            }
            $outputs = $shoot->files()->where('ai_editing_job_id', $job->id)->get();
            $this->assertCount(1, $outputs);
            $this->assertSame($item->id, $outputs->first()->shoot_service_id);
            $this->assertSame($source->id, $outputs->first()->source_file_id);
            $this->assertSame('generated image', Storage::disk('local')->get($outputs->first()->path));
            // Simulate a second consumer which staged distinct bytes before the
            // first consumer committed. Publication still keeps the first result.
            $otherPath = 'shoots/'.$shoot->id.'/parallel-attempt-'.$job->id.'.jpg';
            Storage::disk('local')->put($otherPath, 'parallel different bytes');
            $publisher = app(LegacyAiOutputPublisher::class);
            $raced = $publisher->publish($job, ['path' => $otherPath]);
            $this->assertSame($outputs->first()->id, $raced->id);
            $this->assertSame($outputs->first()->path, $publisher->finishPath($raced, $otherPath));
            $this->assertSame('generated image', Storage::disk('local')->get($raced->path));
            $this->assertSame(1, $shoot->files()->where('ai_editing_job_id', $job->id)->count());

        }
    }

    public function test_iguide_adopts_existing_unscoped_assets_and_resync_does_not_redownload_or_duplicate(): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']); $shoot = $this->shoot();
        $shoot->update(['iguide_property_id' => 'property-one', 'iguide_tour_url' => 'https://youriguide.com/property-one/']);
        $item = $this->item($shoot, Service::factory()->create(['name' => 'Premium iGuide with Floor Plans', 'upload_intake_type' => 'none', 'included_components' => null]));
        $file = $this->file($shoot, null, $admin, ['media_type' => 'floorplan', 'file_type' => 'application/pdf',
            'metadata' => ['source' => 'iguide', 'iguide_asset_key' => 'pdf_imperial']]);
        $before = $file->only(['id', 'path', 'created_at', 'workflow_stage']);
        $plans = [['asset_key' => 'pdf_imperial', 'url' => 'https://assets.test/floorplan.pdf', 'filename' => 'floorplan.pdf', 'type' => 'pdf']];
        (new IngestIguideAssetsJob($shoot->id, $plans))->handle(app(ShootActivityLogger::class));
        $item->service->update(['name' => 'Renamed catalog product']);
        app(\App\Services\IguideService::class)->applyShootData($shoot->fresh(), ['property_id' => 'property-one', 'tour_url' => 'https://youriguide.com/property-one/']);
        (new IngestIguideAssetsJob($shoot->id, $plans))->handle(app(ShootActivityLogger::class));
        $this->assertSame($item->id, $file->fresh()->shoot_service_id);
        $this->assertEquals($before, $file->fresh()->only(array_keys($before)));
        $this->assertSame(1, $shoot->files()->count());
        $this->assertSame($item->id, data_get($shoot->fresh()->iguide_data, 'service_line_id'));
        Http::assertNothingSent();
    }

    public function test_iguide_ambiguous_owner_never_downloads_or_guesses(): void
    {
        Http::preventStrayRequests(); $shoot = $this->shoot();
        foreach ([1, 2] as $n) $this->item($shoot, Service::factory()->create(['name' => 'iGuide '.$n, 'upload_intake_type' => 'none', 'included_components' => null]));
        (new IngestIguideAssetsJob($shoot->id, [['asset_key' => 'pdf', 'url' => 'https://assets.test/plan.pdf']]))->handle(app(ShootActivityLogger::class));
        $this->assertSame(0, $shoot->files()->count()); Http::assertNothingSent();
    }

    public function test_explicit_financial_catalog_snapshot_preserves_legacy_booking_and_payout(): void
    {
        $shoot = $this->shoot(); $catalog = Service::factory()->unbracketedPhoto()->create(['financial_only' => false]);
        $old = $this->item($shoot, $catalog, ['photographer_pay' => 25]);
        $original = $old->payout_snapshot;
        $catalog->update(['financial_only' => true]);
        $old->update(['workflow_status' => 'in_progress']);
        $this->assertTrue($old->fresh()->is_deliverable);
        $this->assertEquals($original, $old->fresh()->payout_snapshot);
        $other = $this->shoot(); $new = $this->item($other, $catalog, ['photographer_pay' => 25]);
        $this->assertFalse($new->is_deliverable);
        $this->assertSame('financial_only', data_get($new->payout_snapshot, 'delivery_requirement'));
        $this->assertSame(25.0, (float) data_get($new->payout_snapshot, 'unit_pay'));
        $this->assertSame([], $new->delivery_requirements);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/api/shoots/'.$other->id.'/upload', ['upload_type' => 'edited', 'shoot_service_id' => $new->id,
            'files' => [UploadedFile::fake()->image('fee.jpg')]], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
