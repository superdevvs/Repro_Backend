<?php

namespace Tests\Feature;

use App\Jobs\{FinalizeShootJob, ProcessMediaVersion};
use App\Models\{Service, Shoot, ShootFile, ShootFileVersion, User};
use App\Services\Media\MediaStorage;
use App\Services\Scanning\{ClamAvClient, ClamAvScanResult};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{DeliveryPlanService, MediaVersionPublisher, ShootActiveWorkLifecycle};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class ComponentMediaVersionReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $editor;
    private Shoot $shoot;
    private ShootFile $file;
    private ShootFile $otherFile;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake();
        Storage::fake('local'); Storage::fake('public');
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.dual_write' => false,
            'media.r2_only' => false, 'media.read_from_r2' => false]);
        $this->manager = User::factory()->create(['role' => 'editing_manager']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        $this->shoot = Shoot::factory()->create(['status' => 'ready', 'workflow_status' => 'ready',
            'admin_verified_at' => null, 'completed_at' => null, 'editor_id' => $this->editor->id,
            'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(), 'timezone' => 'UTC']);
        $service = Service::factory()->create(['upload_intake_type' => 'photo', 'requires_editing' => true,
            'included_components' => [['name' => 'Photos', 'upload_intake_type' => 'photo', 'uses_hdr_brackets' => false]]]);
        $item = $this->shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 100, 'quantity' => 1,
            'is_deliverable' => true, 'editor_id' => $this->editor->id, 'workflow_status' => 'ready', 'delivery_status' => 'ready',
            'scheduled_at' => now()->subDay()]);
        $old = UploadedFile::fake()->image('old.jpg', 32, 24);
        $path = "shoots/{$this->shoot->id}/completed/original.jpg";
        app(MediaStorage::class)->put($path, file_get_contents($old->getRealPath()));
        $this->file = ShootFile::create(['shoot_id' => $this->shoot->id, 'shoot_service_id' => $item->id,
            'filename' => 'Kitchen.jpg', 'stored_filename' => 'original.jpg', 'path' => $path, 'storage_path' => $path,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => $old->getSize(), 'media_type' => 'edited',
            'workflow_stage' => 'completed', 'uploaded_by' => $this->editor->id, 'scan_status' => 'clean', 'content_version' => 1]);
        $otherPath = "shoots/{$this->shoot->id}/completed/other.jpg";
        app(MediaStorage::class)->put($otherPath, file_get_contents($old->getRealPath()));
        $this->otherFile = $this->file->replicate();
        $this->otherFile->forceFill(['filename' => 'Other.jpg', 'stored_filename' => 'other.jpg', 'path' => $otherPath, 'storage_path' => $otherPath])->save();
        $intent = app(DeliveryPlanService::class)->validateSelection($this->shoot, $this->manager, []);
        (new FinalizeShootJob($this->shoot->id, $this->manager->id, null, null, false, $intent['idempotency_key'], $intent))
            ->handle(app(ShootActivityLogger::class));
        $this->file->refresh(); $this->shoot->refresh();
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file));
        $this->assertSame('delivered', $this->shoot->status);
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
    }

    private function returned(string $origin, ?User $actor = null): ShootFileVersion
    {
        $upload = UploadedFile::fake()->image('returned.png', 48, 32);
        $version = app(MediaVersionPublisher::class)->stage($this->file->fresh(), $upload->getRealPath(), 'returned.png',
            $this->file->fresh()->content_version, $actor ?? $this->editor, 'review:'.Str::uuid(), ['origin' => $origin]);
        app()->call([new ProcessMediaVersion($version->id), 'handle']);
        return $version->fresh();
    }

    public function test_automatic_intake_human_and_ai_returns_cannot_replace_a_delivered_component(): void
    {
        $path = $this->file->path;
        $bytes = app(MediaStorage::class)->get($path);
        $originalReceipt = DB::table('shoot_delivery_receipts')->first();
        foreach (['intake', 'human', 'ai'] as $origin) {
            // Even an admin who starts AI generation has not reviewed its result.
            $version = $this->returned($origin, $origin === 'ai' ? $this->manager : $this->editor);
            $this->assertSame('conflict', $version->status);
            $this->assertSame('delivery_review_required', $version->error_code);
            $this->assertSame(1, $this->file->fresh()->content_version);
            $this->assertSame($path, $this->file->fresh()->path);
            $this->assertSame($bytes, app(MediaStorage::class)->get($path));
            $this->assertTrue(app(MediaStorage::class)->exists($version->snapshot['path']));
        }
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
        $this->assertEquals($originalReceipt, DB::table('shoot_delivery_receipts')->first());
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file->fresh()));
        $this->assertSame('delivered', $this->shoot->fresh()->status);
    }

    public function test_actual_staff_resolve_records_exact_new_version_without_reopening_or_rewriting_history(): void
    {
        $deliveredAt = $this->shoot->admin_verified_at;
        $first = DB::table('shoot_delivery_receipts')->first();
        $oldPath = $this->file->path;
        $version = $this->returned('human');
        $url = "/api/shoots/{$this->shoot->id}/media-versions/{$version->id}/resolve";
        $this->actingAs($this->editor)->postJson($url, ['choice' => 'replace_latest', 'expected_latest_version' => 1])->assertForbidden();
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->actingAs($this->manager)->postJson($url, ['choice' => 'replace_latest', 'expected_latest_version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
        $receipt = DB::table('shoot_delivery_receipts')->orderByDesc('id')->first();
        $this->assertSame($this->manager->id, (int) $receipt->actor_id);
        $this->assertContains(['id' => $this->file->id, 'version' => 2], json_decode($receipt->evidence, true)['files']);
        $this->assertEquals($first, DB::table('shoot_delivery_receipts')->where('id', $first->id)->first());
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file->fresh()));
        $this->assertSame('delivered', $this->shoot->fresh()->status);
        $this->assertEquals($deliveredAt, $this->shoot->fresh()->admin_verified_at);
        $this->assertTrue(app(MediaStorage::class)->exists($oldPath));
        $this->assertSame([], app(DeliveryPlanService::class)->plan($this->shoot->fresh(), $this->manager)['ready_component_ids']);
        app(MediaVersionPublisher::class)->publish($version->fresh());
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_saved_manual_staff_review_carries_only_its_approved_component_version(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/versions", [
            'file' => UploadedFile::fake()->image('returned.png', 48, 32), 'request_id' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertAccepted();
        $version = ShootFileVersion::findOrFail($response->json('data.id'));
        app()->call([new ProcessMediaVersion($version->id), 'handle']);
        $version->refresh();
        $this->assertSame('published', $version->status);
        $this->assertSame(2, $this->file->fresh()->content_version);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file->fresh()));
        $this->assertSame('delivered', $this->shoot->fresh()->status);
    }

    public function test_staff_upload_permission_is_rechecked_before_worker_publication(): void
    {
        $response = $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/files/{$this->file->id}/versions", [
            'file' => UploadedFile::fake()->image('returned.png', 48, 32), 'request_id' => (string) Str::uuid(), 'expected_version' => 1,
        ])->assertAccepted();
        $this->manager->forceFill(['permission_overrides' => ['deny' => ['shoots-manage']]])->save();
        $version = ShootFileVersion::findOrFail($response->json('data.id'));
        app()->call([new ProcessMediaVersion($version->id), 'handle']);
        $this->assertSame('delivery_review_required', $version->fresh()->error_code);
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
    }

    public function test_staff_save_copy_preserves_existing_approval_and_approves_only_the_new_copy(): void
    {
        $oldPath = $this->file->path;
        $version = $this->returned('intake');
        $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/media-versions/{$version->id}/resolve",
            ['choice' => 'save_copy', 'expected_latest_version' => 1])->assertOk()->assertJsonPath('data.status', 'published');
        $copy = ShootFile::findOrFail($version->fresh()->published_file_id);
        $this->assertNotSame($this->file->id, $copy->id);
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file->fresh()));
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($copy));
        $this->assertSame('delivered', $this->shoot->fresh()->status);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_review_rechecks_effective_permission_and_the_exact_latest_version(): void
    {
        $version = $this->returned('human');
        $url = "/api/shoots/{$this->shoot->id}/media-versions/{$version->id}/resolve";
        $this->manager->forceFill(['permission_overrides' => ['deny' => ['shoots-manage']]])->save();
        $this->actingAs($this->manager)->postJson($url, ['choice' => 'replace_latest', 'expected_latest_version' => 1])->assertForbidden();
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->manager->forceFill(['permission_overrides' => []])->save();
        $this->returned('manual', $this->manager);
        $this->actingAs($this->manager)->postJson($url, ['choice' => 'replace_latest', 'expected_latest_version' => 1])->assertConflict();
        $this->assertSame(2, $this->file->fresh()->content_version);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_two_sequential_staff_replacements_preserve_the_other_approved_file_proof(): void
    {
        $this->assertSame('published', $this->returned('manual', $this->manager)->status);
        $firstFile = $this->file->fresh();
        $this->file = $this->otherFile->fresh();
        $this->assertSame('published', $this->returned('manual', $this->manager)->status);
        $this->assertSame(2, $firstFile->fresh()->content_version);
        $this->assertSame(2, $this->file->fresh()->content_version);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($firstFile->fresh()));
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file->fresh()));
        $this->assertSame('delivered', $this->shoot->fresh()->status);
        $this->assertDatabaseCount('shoot_delivery_receipts', 3);
        $this->assertSame([], app(DeliveryPlanService::class)->plan($this->shoot->fresh(), $this->manager)['ready_component_ids']);
    }

    public function test_reviewing_a_delivered_photo_cannot_finish_another_pending_video_service(): void
    {
        $service = Service::factory()->videoIntake()->create();
        $video = $this->shoot->serviceItems()->create(['service_id' => $service->id, 'quantity' => 1, 'price' => 100,
            'is_deliverable' => true, 'workflow_status' => 'pending', 'delivery_status' => 'not_started', 'scheduled_at' => now()->subHour()]);
        app(ShootActiveWorkLifecycle::class)->begin($this->shoot->fresh());
        $this->assertSame('active', $this->shoot->fresh()->status);
        $this->assertSame('published', $this->returned('manual', $this->manager)->status);
        $this->assertSame('active', $this->shoot->fresh()->status);
        $this->assertNotSame('delivered', $video->fresh()->delivery_status);
        $this->assertSame(0, DB::table('shoot_delivery_receipts')->where('shoot_service_id', $video->id)->count());
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file->fresh()));
    }

    public function test_ineligible_staff_carry_rolls_back_new_current_bytes_and_archiving(): void
    {
        $oldPath = $this->file->path;
        $item = $this->file->serviceItem;
        $item->forceFill(['scheduled_at' => now()->addDay()])->save();
        $version = $this->returned('manual', $this->manager);
        $this->assertSame('delivery_review_required', $version->error_code);
        $this->assertSame(1, $this->file->fresh()->content_version);
        $this->assertSame($oldPath, $this->file->fresh()->path);
        $this->assertDatabaseMissing('shoot_file_versions', ['request_key' => 'baseline:'.$this->file->id.':1']);
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($this->file->fresh()));
    }
}
