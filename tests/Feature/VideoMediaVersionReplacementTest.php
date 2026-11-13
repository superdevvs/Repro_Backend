<?php

namespace Tests\Feature;

use App\Jobs\{FinalizeShootJob, ProcessMediaVersion};
use App\Models\{Service, Shoot, ShootFile, ShootFileVersion, User};
use App\Services\ImageProcessingService;
use App\Services\Media\MediaStorage;
use App\Services\Scanning\{ClamAvClient, ClamAvScanResult};
use App\Services\ShootActivityLogger;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\{DeliveryPlanService, MediaVersionPublisher, MediaVersionVideo, ShootMediaArchiveService, ShootPresenter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class VideoMediaVersionReplacementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $editor;
    private User $client;
    private Shoot $shoot;
    private ShootFile $file;
    private ShootFile $sibling;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake();
        Storage::fake('local'); Storage::fake('public');
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.dual_write' => false,
            'media.r2_only' => false, 'media.read_from_r2' => false, 'media.local_delivery_enabled' => false]);
        $this->manager = User::factory()->create(['role' => 'editing_manager']);
        $this->editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'status' => 'ready', 'workflow_status' => 'ready',
            'payment_status' => 'paid', 'total_quote' => 100, 'bypass_paywall' => false,
            'admin_verified_at' => null, 'completed_at' => null, 'editor_id' => $this->editor->id,
            'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(), 'timezone' => 'UTC']);
        $service = Service::factory()->videoIntake()->create(['requires_editing' => true,
            'included_components' => [['name' => 'Listing video', 'upload_intake_type' => 'video', 'uses_hdr_brackets' => false]]]);
        $item = $this->shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 100, 'quantity' => 1,
            'is_deliverable' => true, 'editor_id' => $this->editor->id, 'workflow_status' => 'ready', 'delivery_status' => 'ready',
            'scheduled_at' => now()->subDay()]);
        $path = "shoots/{$this->shoot->id}/completed/original.mp4";
        app(MediaStorage::class)->put($path, $this->videoBytes());
        $this->file = ShootFile::create(['shoot_id' => $this->shoot->id, 'shoot_service_id' => $item->id,
            'filename' => 'Listing.mp4', 'stored_filename' => 'original.mp4', 'path' => $path, 'storage_path' => $path,
            'file_type' => 'video/mp4', 'mime_type' => 'video/mp4', 'file_size' => strlen($this->videoBytes()), 'media_type' => 'video',
            'workflow_stage' => 'completed', 'uploaded_by' => $this->editor->id, 'scan_status' => 'clean', 'content_version' => 1, 'sort_order' => 7]);
        $this->sibling = $this->file->replicate();
        $siblingPath = "shoots/{$this->shoot->id}/completed/sibling.mp4";
        app(MediaStorage::class)->put($siblingPath, $this->videoBytes('sibling'));
        $this->sibling->forceFill(['filename' => 'Social.mp4', 'stored_filename' => 'sibling.mp4', 'path' => $siblingPath, 'storage_path' => $siblingPath])->save();
        $intent = app(DeliveryPlanService::class)->validateSelection($this->shoot, $this->manager, []);
        (new FinalizeShootJob($this->shoot->id, $this->manager->id, null, null, false, $intent['idempotency_key'], $intent))->handle(app(ShootActivityLogger::class));
        $this->file->refresh(); $this->shoot->refresh();
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file));
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
        $this->mock(ImageProcessingService::class, fn ($mock) => $mock->shouldNotReceive('processImageFromPath'));
    }

    private function videoBytes(string $label = ''): string
    {
        // A tiny real H.264/MP4 plus a legal free box makes revisions byte-distinct.
        return file_get_contents(__DIR__.'/../Fixtures/media/replacement-video.mp4').pack('N', 8 + strlen($label)).'free'.$label;
    }

    private function upload(string $label = 'replacement'): ShootFileVersion
    {
        $saved = app(ShootMediaStorageService::class)->uploadToCompleted($this->shoot->fresh(),
            UploadedFile::fake()->createWithContent('Listing.mp4', $this->videoBytes($label)), $this->manager->id,
            null, 'video', $this->file->shoot_service_id);
        $this->assertSame($this->file->id, $saved->id);
        $this->assertNotNull($saved->pendingMediaVersionId);
        return ShootFileVersion::findOrFail($saved->pendingMediaVersionId);
    }

    private function process(ShootFileVersion $version): ShootFileVersion
    {
        app()->call([new ProcessMediaVersion($version->id), 'handle']);
        return $version->fresh();
    }

    private function resolve(ShootFileVersion $version, int $expected = 1)
    {
        return $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/media-versions/{$version->id}/resolve",
            ['choice' => 'replace_latest', 'expected_latest_version' => $expected]);
    }

    private function downloadUrl(): string { return "/api/shoots/{$this->shoot->id}/media/{$this->file->id}/download"; }

    private function assertClientBytes(string $expected): void
    {
        $response = $this->actingAs($this->client)->get($this->downloadUrl(), ['Accept' => 'application/zip'])->assertOk();
        $this->assertSame($expected, file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_same_filename_video_preserves_approved_bytes_until_review_then_publishes_exact_download_and_archive(): void
    {
        $this->file->forceFill(['dropbox_path' => '/previous.mp4', 'dropbox_file_id' => 'previous'])->save();
        $oldPath = $this->file->path;
        $firstReceipt = DB::table('shoot_delivery_receipts')->first();
        $deliveredAt = $this->shoot->admin_verified_at;
        $version = $this->upload();
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $this->assertClientBytes($this->videoBytes());
        $version = $this->process($version);
        $this->assertSame('delivery_review_required', $version->error_code);
        $this->assertSame('verified', $this->file->fresh()->workflow_stage);
        $this->assertClientBytes($this->videoBytes());
        $this->resolve($version)->assertOk()->assertJsonPath('data.version', 2);
        $current = $this->file->fresh();
        $this->assertSame($current->path, $current->storage_path);
        $this->assertSame('video/mp4', $current->mime_type);
        $this->assertNull($current->dropbox_path);
        $this->assertNull($current->dropbox_file_id);
        $this->assertSame(7, $current->sort_order);
        $this->assertNotSame($oldPath, $current->storage_path);
        $this->assertTrue(app(MediaStorage::class)->exists($oldPath));
        $this->assertClientBytes($this->videoBytes('replacement'));
        $this->assertEquals($firstReceipt, DB::table('shoot_delivery_receipts')->where('id', $firstReceipt->id)->first());
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->sibling->fresh()));
        $this->assertEquals($deliveredAt, $this->shoot->fresh()->admin_verified_at);
        $this->assertSame('delivered', $this->shoot->fresh()->status);
        $archives = app(ShootMediaArchiveService::class);
        $type = $archives->buildArchiveTypeToken('edited', false, [], 'videos', true);
        $archives->generateArchive($this->shoot->fresh(), $type, 'original');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($archives->getArchivePath($this->shoot, $type, 'original'))));
        $contents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $contents[] = $zip->getFromIndex($i);
        $zip->close();
        $this->assertEqualsCanonicalizing([$this->videoBytes('replacement'), $this->videoBytes('sibling')], $contents);
        $listing = $this->actingAs($this->client)->getJson('/api/shoots?include_files=true&tab=delivered')->assertOk();
        $listedShoot = collect($listing->json('data'))->firstWhere('id', $this->shoot->id);
        $listedFile = collect($listedShoot['files'])->firstWhere('id', $this->file->id);
        $this->assertSame(2, $listedFile['content_version']);
        $this->assertTrue($listedFile['can_download']);
        $this->process($version->fresh());
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_manual_video_upload_also_requires_review_and_preview_is_signed_streaming_and_revocable(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/versions", [
            'file' => UploadedFile::fake()->createWithContent('review.mp4', $this->videoBytes('manual')),
            'request_id' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertAccepted();
        $version = $this->process(ShootFileVersion::findOrFail($response->json('data.id')));
        $this->assertSame('delivery_review_required', $version->error_code);
        $url = $this->actingAs($this->manager)->getJson("/api/shoots/{$this->shoot->id}/media-versions/{$version->id}")->assertOk()->json('data.stream_url');
        $this->assertNotEmpty($url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->get($url, ['Range' => 'bytes=0-31'])->assertStatus(206)->assertHeader('Content-Length', '32');
        $this->get(Str::before($url, '?'))->assertForbidden();
        $this->get(str_replace('/'.$this->manager->id.'?', '/'.$this->client->id.'?', $url))->assertForbidden();
        $this->manager->forceFill(['permission_overrides' => ['deny' => ['shoots-manage']]])->save();
        $this->get($url)->assertForbidden();
        $this->resolve($version)->assertForbidden();
        $this->assertSame(1, $this->file->fresh()->content_version);
    }

    public function test_chunked_edited_upload_uses_the_same_pending_replacement_boundary(): void
    {
        $bytes = $this->videoBytes('chunked');
        $this->actingAs($this->manager);
        $session = $this->postJson("/api/shoots/{$this->shoot->id}/upload-sessions", [
            'filename' => 'Listing.mp4', 'size_bytes' => strlen($bytes), 'upload_type' => 'edited',
            'fields' => ['upload_type' => 'edited', 'shoot_service_id' => (string) $this->file->shoot_service_id,
                'replace_file_id' => (string) $this->file->id, 'idempotency_key' => 'chunked-video-replacement'],
        ])->assertCreated()->json('session_id');
        $this->call('PUT', "/api/shoots/{$this->shoot->id}/upload-sessions/{$session}/chunks/0", [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($bytes)], $bytes)->assertOk();
        $response = $this->postJson("/api/shoots/{$this->shoot->id}/upload-sessions/{$session}/complete")
            ->assertOk()->assertJsonPath('success_count', 1);
        $version = ShootFileVersion::where('target_file_id', $this->file->id)->where('status', 'queued')->firstOrFail();
        $this->assertStringContainsString($version->id, $response->getContent());
        $this->assertClientBytes($this->videoBytes());
        $this->resolve($this->process($version))->assertOk();
        $this->assertClientBytes($bytes);
    }

    public function test_video_preview_rechecks_scan_status_and_never_exposes_failed_or_quarantined_versions(): void
    {
        $version = $this->process($this->upload());
        $this->actingAs($this->manager);
        $url = $version->present()['stream_url'];
        foreach (['infected', 'quarantined', 'failed'] as $scan) {
            foreach (['conflict', 'archived', 'published'] as $status) {
                $version->update(['status' => $status, 'snapshot' => array_merge($version->snapshot, ['scan_status' => $scan])]);
                $this->assertNull($version->present()['stream_url']);
                $this->get($url)->assertForbidden();
            }
        }
        $version->update(['status' => 'failed', 'snapshot' => array_merge($version->snapshot, ['scan_status' => 'clean'])]);
        $this->assertNull($version->present()['stream_url']);
        $this->get($url)->assertNotFound();
    }

    public function test_failed_scan_and_concurrent_upload_preserve_current_video_and_require_latest_review(): void
    {
        $failed = $this->upload('infected');
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::infected('fixture')));
        $this->assertSame('scan_failed', $this->process($failed)->error_code);
        $this->assertClientBytes($this->videoBytes());
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
        $first = $this->process($this->upload('first'));
        $second = $this->upload('second');
        $this->resolve($first)->assertOk();
        $this->assertSame('newer_version', $this->process($second)->error_code);
        $this->resolve($second, 1)->assertConflict();
        $this->assertClientBytes($this->videoBytes('first'));
        $this->resolve($second->fresh(), 2)->assertOk();
        $this->assertClientBytes($this->videoBytes('second'));
        $this->assertSame(3, $this->file->fresh()->content_version);
    }

    public function test_video_restore_revalidates_archived_bytes_and_keeps_payment_and_ownership_gates(): void
    {
        $this->resolve($this->process($this->upload()))->assertOk();
        $baseline = ShootFileVersion::where('published_file_id', $this->file->id)->where('version', 1)->firstOrFail();
        $restoredId = $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/media-versions/{$baseline->id}/restore",
            ['request_id' => (string) Str::uuid(), 'expected_version' => 2])->assertAccepted()->json('data.id');
        $this->assertSame('published', $this->process(ShootFileVersion::findOrFail($restoredId))->status);
        $this->assertClientBytes($this->videoBytes());
        $this->shoot->update(['payment_status' => 'unpaid']);
        $this->actingAs($this->client)->getJson($this->downloadUrl())->assertForbidden()->assertJsonPath('code', 'payment_required');
        $this->actingAs(User::factory()->create(['role' => 'client']))->getJson($this->downloadUrl())->assertForbidden();
    }

    public function test_reviewed_publication_write_failure_preserves_original_receipt_and_client_download_for_later_worker_retry(): void
    {
        $version = $this->process($this->upload());
        $receipt = DB::table('shoot_delivery_receipts')->first();
        $oldPath = $this->file->path;
        $targetId = $this->file->id;
        $failWrite = true;
        ShootFile::saving(function (ShootFile $candidate) use ($targetId, &$failWrite) {
            if ($failWrite && $candidate->id === $targetId && $candidate->isDirty('path')) {
                throw new \RuntimeException('Simulated reviewed publication failure');
            }
        });
        try {
            app(MediaVersionPublisher::class)->resolve($version, 'replace_latest', 1, $this->manager);
            $this->fail('The reviewed publication write must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated reviewed publication failure', $error->getMessage());
        }
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->assertSame('verified', $this->file->fresh()->workflow_stage);
        $this->assertClientBytes($this->videoBytes());
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
        $this->assertEquals($receipt, DB::table('shoot_delivery_receipts')->first());
        $this->assertDatabaseMissing('shoot_file_versions', ['published_file_id' => $targetId]);
        $this->assertSame('ready', $version->fresh()->status);
        $this->assertSame($this->videoBytes('replacement'), app(MediaStorage::class)->get($version->snapshot['path']));
        $failWrite = false;
        // Exercise a later worker retry explicitly; resolve() itself does not
        // enqueue this retry when its synchronous publication throws.
        $this->assertSame('published', $this->process($version->fresh())->status);
        $this->assertClientBytes($this->videoBytes('replacement'));
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
        $this->assertEquals($receipt, DB::table('shoot_delivery_receipts')->where('id', $receipt->id)->first());
    }

    public function test_hidden_same_name_is_not_overwritten_and_explicit_hidden_target_is_rejected(): void
    {
        $this->file->update(['is_hidden' => true]);
        $oldPath = $this->file->path;
        $new = app(ShootMediaStorageService::class)->uploadToCompleted($this->shoot->fresh(),
            UploadedFile::fake()->createWithContent('Listing.mp4', $this->videoBytes('new')), $this->manager->id, null, 'video', $this->file->shoot_service_id);
        $this->assertNotSame($this->file->id, $new->id);
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ShootMediaStorageService::class)->uploadToCompleted($this->shoot->fresh(),
            UploadedFile::fake()->createWithContent('Listing.mp4', $this->videoBytes('newer')), $this->manager->id, null, 'video', $this->file->shoot_service_id, null, $this->file->id);
    }

    public function test_worker_without_optional_probe_can_publish_scanned_video_and_retries_never_withdraw_old_bytes(): void
    {
        config(['studio.ffprobe_path' => 'repro-missing-optional-ffprobe']);
        $version = $this->upload();
        $this->mock(MediaVersionVideo::class, fn ($mock) => $mock->shouldReceive('inspect')->once()->andThrow(new \RuntimeException('temporary read failure')));
        $job = new ProcessMediaVersion($version->id);
        try { app()->call([$job, 'handle']); $this->fail('Expected processing failure'); }
        catch (\RuntimeException $error) { $job->failed($error); }
        $this->assertClientBytes($this->videoBytes());
        app()->forgetInstance(MediaVersionVideo::class);
        $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/media-versions/{$version->id}/retry")->assertAccepted();
        $version = $this->process($version->fresh());
        $this->assertSame('mime_and_scan', $version->snapshot['video_validation']['validation']);
        $this->resolve($version)->assertOk();
        $this->assertClientBytes($this->videoBytes('replacement'));
    }

    public function test_detail_and_media_list_agree_on_unreviewed_video_capability_for_client_and_staff(): void
    {
        // Reproduces a legacy replacement already stranded at completed.
        $this->file->update(['workflow_stage' => 'completed', 'metadata' => ['internal_marker' => 'private-fixture-secret',
            'original_url' => 'https://provider.test/private-original.mp4', 'width' => 32, 'height' => 24, 'captured_at' => '2026-11-12T12:00:00Z']]);
        foreach ([[$this->client, false, 'content_not_finalized'], [$this->manager, true, null]] as [$actor, $allowed, $reason]) {
            $this->actingAs($actor);
            $detail = app(ShootPresenter::class)->transformOperationalShoot($this->shoot->fresh(), $actor->role === 'client');
            if ($actor->role === 'client') {
                $this->assertStringNotContainsString('private-fixture-secret', json_encode($detail));
                $this->assertStringNotContainsString('private-original.mp4', json_encode($detail));
            } else $this->assertStringContainsString('private-fixture-secret', json_encode($detail));
            $detailFile = collect($detail['files'])->firstWhere('id', $this->file->id);
            if ($actor->role === 'client') {
                $this->assertSame(32, $detailFile['width']);
                $this->assertSame(24, $detailFile['height']);
                $this->assertSame('2026-11-12T12:00:00Z', $detailFile['captured_at']);
            }
            $listFile = collect($this->getJson("/api/shoots/{$this->shoot->id}/files?type=edited")->assertOk()->json('data'))->firstWhere('id', $this->file->id);
            $this->assertNotNull($detailFile); $this->assertNotNull($listFile);
            $this->assertSame($allowed, $detailFile['can_download']);
            $this->assertSame($allowed, $listFile['can_download']);
            $this->assertSame($reason, $detailFile['download_unavailable_reason']);
            $this->assertSame($reason, $listFile['download_unavailable_reason']);
        }
    }
}
