<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\ShootWorkflowService;
use App\Services\Shoots\{DeliveryPlanService, PublicTourImage, ShootAuthorizationSupport, ShootClientReleaseAccessService};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComponentReleaseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function fixture(): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'editing', 'workflow_status' => 'editing',
            'scheduled_at' => now()->subDay(), 'scheduled_date' => '2026-11-04', 'timezone' => 'UTC',
            'total_quote' => 250, 'payment_status' => 'paid', 'completed_at' => null, 'admin_verified_at' => null]);
        $item = $shoot->serviceItems()->create(['service_id' => Service::factory()->photoVideoIntake()->create()->id,
            'price' => 250, 'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'editing', 'delivery_status' => 'not_started',
            'scheduled_at' => now()->subDay()]);
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'approved.jpg',
            'stored_filename' => 'approved.jpg', 'path' => 'shoots/'.$shoot->id.'/approved.jpg', 'file_size' => 100,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'media_type' => 'edited', 'workflow_stage' => 'completed',
            'scan_status' => 'clean', 'uploaded_by' => $admin->id])->save();
        $delivery = app(DeliveryPlanService::class);
        DB::transaction(fn () => $delivery->commitSelection($shoot, $admin, ['mode' => 'media', 'component_ids' => [$item->id.':photo']]));
        $file->forceFill(['workflow_stage' => 'verified', 'verified_at' => now(), 'verified_by' => $admin->id])->save();
        $delivery->forgetReleasedFiles($shoot);
        return [$shoot->fresh(), $item->fresh(), $file->fresh(), $client, $admin];
    }

    public function test_partial_approved_photo_is_available_without_releasing_unapproved_video_or_new_versions(): void
    {
        [$shoot, $item, $photo, $client] = $this->fixture();
        $access = app(ShootClientReleaseAccessService::class);
        $policy = app(ShootAuthorizationSupport::class);
        $public = app(PublicTourImage::class);
        $this->assertTrue($access->isApprovedFile($shoot, $photo));
        $this->assertTrue($policy->canDownloadShootMediaFile($shoot, $photo, $client));
        $this->assertTrue($public->canPublish($shoot));
        $this->assertTrue($public->canRetainPublishedFile($photo, $shoot));
        $this->assertSame([$photo->id], $shoot->files()->verifiedForFinalizedDelivery()->pluck('shoot_files.id')->all());

        $video = $photo->replicate();
        $video->forceFill(['filename' => 'pending.mp4', 'file_type' => 'video/mp4', 'mime_type' => 'video/mp4', 'media_type' => 'video'])->save();
        $this->assertFalse($policy->canDownloadShootMediaFile($shoot, $video, $client));
        // A stale whole-line flag cannot release an unapproved component or edited version.
        $item->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()])->saveQuietly();
        $photo->forceFill(['content_version' => 2])->saveQuietly();
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->assertFalse($access->isApprovedFile($shoot, $photo->fresh()));
        $this->assertFalse($public->canRetainPublishedFile($photo->fresh(), $shoot));
        $this->assertSame([], $shoot->files()->verifiedForFinalizedDelivery()->pluck('shoot_files.id')->all());
    }

    public function test_component_release_keeps_payment_and_cancelled_owner_checks(): void
    {
        [$shoot, $item, $file, $client] = $this->fixture();
        $shoot->forceFill(['payment_status' => 'unpaid'])->saveQuietly();
        $this->assertTrue(app(ShootClientReleaseAccessService::class)->isFileReleaseLocked($shoot, $file, $client));
        $this->assertFalse(app(PublicTourImage::class)->canRetainPublishedFile($file, $shoot));
        $item->forceFill(['cancelled_at' => now(), 'workflow_status' => 'cancelled', 'delivery_status' => 'cancelled'])->saveQuietly();
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->assertFalse(app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $file->fresh()));
        $this->assertSame([], $shoot->files()->verifiedForFinalizedDelivery()->pluck('shoot_files.id')->all());
    }

    public function test_existing_legacy_delivery_is_preserved_in_a_mixed_component_order(): void
    {
        [$shoot, , , $client] = $this->fixture();
        $old = Model::withoutEvents(fn () => $shoot->serviceItems()->create(['service_id' => Service::factory()->unbracketedPhoto()->create()->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true,
            'delivery_requirements' => [['key' => 'legacy', 'kind' => 'legacy', 'label' => 'Historical delivery']],
            'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => now()->subDay()]));
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $old->id, 'filename' => 'old.jpg', 'stored_filename' => 'old.jpg', 'path' => 'old.jpg',
            'file_size' => 100, 'file_type' => 'image/jpeg', 'media_type' => 'edited', 'workflow_stage' => 'verified', 'scan_status' => 'clean', 'uploaded_by' => $client->id])->save();
        $this->assertTrue(app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $file));
        $this->assertTrue(app(ShootAuthorizationSupport::class)->canDownloadShootMediaFile($shoot, $file, $client));
        $this->assertTrue($shoot->files()->whereKey($file->id)->verifiedForFinalizedDelivery()->exists());
        $this->assertTrue(app(PublicTourImage::class)->canRetainPublishedFile($file, $shoot));
        DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->delete();
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->assertTrue(app(PublicTourImage::class)->canPublish($shoot));
        $this->assertTrue(app(PublicTourImage::class)->canRetainPublishedFile($file, $shoot));
        $old->forceFill(['delivery_requirements' => null])->saveQuietly();
        $this->assertTrue($shoot->files()->whereKey($file->id)->verifiedForFinalizedDelivery()->exists());
        $old->forceFill(['is_deliverable' => false, 'delivery_requirements' => []])->saveQuietly();
        $file->unsetRelation('serviceItem');
        $this->assertFalse(app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $file));
        $this->assertFalse($shoot->files()->whereKey($file->id)->verifiedForFinalizedDelivery()->exists());
    }

    public function test_legacy_mark_rejects_missing_work_without_stamping_false_delivery(): void
    {
        [$shoot, $item, , , $admin] = $this->fixture();
        try { app(ShootWorkflowService::class)->markCompleted($shoot, $admin); $this->fail('Missing video cannot finish the order.'); }
        catch (ValidationException $expected) { $this->assertNotEmpty($expected->errors()); }
        $this->assertNull($shoot->fresh()->admin_verified_at);
        $this->assertNull($shoot->fresh()->completed_at);
        $this->assertNotSame('delivered', $item->fresh()->delivery_status);
        Queue::assertNotPushed(FinalizeShootJob::class);
        $this->assertDatabaseMissing('workflow_logs', ['shoot_id' => $shoot->id, 'action' => 'shoot_completed']);
    }

    public function test_unscoped_historical_files_survive_both_open_and_closed_component_cycles(): void
    {
        [$shoot, , $original, $client] = $this->fixture();
        $old = $original->replicate();
        $old->forceFill(['shoot_service_id' => null, 'filename' => 'legacy-history.jpg',
            'created_at' => now()->subDays(2), 'verified_at' => now()->subDay()])->save();
        foreach (['active', 'delivered'] as $state) {
            $shoot->forceFill(['status' => $state, 'workflow_status' => $state, 'admin_verified_at' => now()->subDay(),
                'active_work_started_at' => now(), 'active_work_is_reopened' => true,
                'active_work_completed_at' => $state === 'delivered' ? now() : null])->saveQuietly();
            app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
            $this->assertTrue(app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $old));
            $this->assertTrue(app(ShootAuthorizationSupport::class)->canDownloadShootMediaFile($shoot, $old, $client));
            $this->assertTrue($shoot->files()->whereKey($old->id)->verifiedForFinalizedDelivery()->exists());
            $this->assertTrue(app(PublicTourImage::class)->canRetainPublishedFile($old, $shoot));
        }
    }

    public function test_card_projection_keeps_the_current_approved_version(): void
    {
        [$shoot, $item, $file, $client, $admin] = $this->fixture();
        $file->forceFill(['content_version' => 2, 'grid_path' => 'shoots/'.$shoot->id.'/grids/approved-v2.jpg'])->save();
        DB::transaction(fn () => app(DeliveryPlanService::class)->commitSelection($shoot->fresh(), $admin,
            ['mode' => 'media', 'component_ids' => [$item->id.':photo']]));
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->actingAs($client);
        $page=Shoot::whereKey($shoot->id)->get();
        app(\App\Services\Shoots\ShootListingService::class)->eagerLoadListCardPreviewFiles($page);
        $this->assertSame(2, (int) $page->first()->list_preview_files->first()->content_version);
        $this->assertCount(1, $page->first()->delivery_card_media['preview_images']);
        $this->assertStringContainsString('approved-v2', $page->first()->delivery_card_media['preview_images'][0]);
    }

    public function test_unassigned_verified_output_cannot_bypass_a_managed_component_archive(): void
    {
        [$shoot, , $file] = $this->fixture();
        $unassigned=$file->replicate(); $unassigned->forceFill(['shoot_service_id' => null])->save();
        $this->assertFalse(app(ShootClientReleaseAccessService::class)->isApprovedFile($shoot, $unassigned));
        $this->assertFalse($shoot->files()->whereKey($unassigned->id)->verifiedForFinalizedDelivery()->exists());
    }
}
