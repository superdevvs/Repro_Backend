<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Payment, PaymentServiceAllocation, Service, Shoot, ShootFile, ShootService, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{PublicTourImage, ShootActiveWorkLifecycle, ShootListingService, ShootPublicAssetsService};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Tests\TestCase;

class ActivePublicMediaPreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 12:00:00');
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake();
        foreach (['local', 'public'] as $name) {
            $disk = 'active_public_'.$name.'_'.bin2hex(random_bytes(6));
            Storage::fake($disk); Storage::set($name, Storage::disk($disk));
        }
        config(['media.read_from_r2' => false, 'media.r2_only' => false, 'media.download_offload' => false, 'media.tiered_storage_enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_original_published_url_survives_activation_while_new_unreleased_outputs_are_excluded(): void
    {
        [$shoot, $oldLine, $old, $url] = $this->delivered();
        $etag = $this->get($url)->assertOk()->assertStreamedContent('web-old')->headers->get('ETag');
        $pending = $this->activate($shoot);
        foreach (['todo', 'completed', 'verified'] as $stage) {
            $file = $this->file($shoot, $pending, 'pending-'.$stage, ['workflow_stage' => $stage]);
            $this->get(app(PublicTourImage::class)->url($file, 'web'))->assertNotFound();
        }
        // Even an old verified image wrongly assigned to the pending line is not released.
        $wrongLine = $this->file($shoot, $pending, 'wrong-line', ['created_at' => '2026-11-02 08:00:00', 'verified_at' => '2026-11-02 09:00:00']);
        $this->get(app(PublicTourImage::class)->url($wrongLine, 'web'))->assertNotFound();
        $this->get($url)->assertOk()->assertStreamedContent('web-old');
        $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
        $payload = app(ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot->fresh(), 'branded', false);
        $this->assertSame([$url], $payload['photos']);
        $this->assertSame('delivered', $oldLine->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
        $this->assertSame('shoots/'.$shoot->id.'/completed/old.jpg', $old->fresh()->path);
    }

    public function test_a_paid_historical_service_remains_published_when_new_work_adds_an_unpaid_balance(): void
    {
        [$shoot, $oldLine, , $url] = $this->delivered();
        $this->activate($shoot, true);
        $this->assertSame('partial', $shoot->fresh()->payment_status);
        $this->assertSame('paid', $oldLine->fresh()->payment_status);
        $this->get($url)->assertOk()->assertStreamedContent('web-old');
        $payload = app(ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot->fresh(), 'branded', false);
        $this->assertSame([$url], $payload['photos']);
        DB::table('payments')->where('shoot_id', $shoot->id)->update(['status' => 'failed']);
        $this->get($url)->assertNotFound();
        $oldLine->forceFill(['force_unlock_delivery' => true])->saveQuietly();
        $this->get($url)->assertOk()->assertStreamedContent('web-old');
    }

    public function test_newly_delivered_paid_service_is_published_while_another_service_keeps_the_order_active(): void
    {
        [$shoot, , , $oldUrl] = $this->delivered();
        $delivering = $this->activate($shoot, true);
        $admin = User::factory()->create(['role' => 'superadmin']);
        $remaining = Model::withoutEvents(function () use ($shoot, $delivering): ShootService {
            $delivering->forceFill(['scheduled_at' => now()->subHour()])->saveQuietly();
            $service = Service::factory()->unbracketedPhoto()->create();
            $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'price' => 50, 'quantity' => 1,
                'is_deliverable' => true, 'workflow_status' => 'pending', 'delivery_status' => 'not_started', 'scheduled_at' => '2026-11-05 15:00:00']);
            $shoot->fresh()->forceFill(['total_quote' => 200, 'payment_status' => 'partial'])->saveQuietly();
            $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 50, 'status' => Payment::STATUS_COMPLETED]);
            PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $delivering->id, 'amount' => 50]);
            return $item;
        });
        $this->travel(1)->minutes();
        $file = $this->file($shoot, $delivering, 'after-cycle', ['workflow_stage' => 'completed', 'verified_at' => null]);
        (new FinalizeShootJob($shoot->id, $admin->id, null, $delivering->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $delivering->fresh()->workflow_status);
        $this->assertSame('delivered', $delivering->fresh()->delivery_status);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertSame('not_started', $remaining->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
        $url = app(PublicTourImage::class)->url($file->fresh(), 'web');
        $this->get($oldUrl)->assertOk()->assertStreamedContent('web-old');
        $this->get($url)->assertOk()->assertStreamedContent('web-after-cycle');
        $payload = app(ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot->fresh(), 'branded', false);
        $this->assertCount(2, $payload['photos']);
        $this->assertContains($oldUrl, $payload['photos']);
        $this->assertContains($url, $payload['photos']);
    }

    public function test_retention_does_not_bypass_hidden_scan_raw_completion_or_hold_gates(): void
    {
        [$shoot, , $old, $url] = $this->delivered();
        $this->activate($shoot);
        $etag = $this->get($url)->assertOk()->headers->get('ETag');
        foreach ([['is_hidden' => true], ['scan_status' => 'quarantined'], ['media_type' => 'raw'], ['workflow_stage' => 'completed']] as $change) {
            DB::table('shoot_files')->where('id', $old->id)->update($change);
            $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
            DB::table('shoot_files')->where('id', $old->id)->update(['is_hidden' => false, 'scan_status' => 'clean', 'media_type' => 'edited', 'workflow_stage' => 'verified']);
        }
        DB::table('shoots')->where('id', $shoot->id)->update(['workflow_status' => 'on_hold']);
        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
        DB::table('shoots')->where('id', $shoot->id)->update(['workflow_status' => 'cancelled']);
        $this->get($url, ['If-None-Match' => $etag])->assertNotFound();
    }

    public function test_unscoped_legacy_history_retains_the_whole_order_payment_gate(): void
    {
        [$shoot, , $old, $url] = $this->delivered();
        DB::table('shoot_files')->where('id', $old->id)->update(['shoot_service_id' => null]);
        $this->activate($shoot);
        $this->get($url)->assertOk();
        DB::table('shoots')->where('id', $shoot->id)->update(['payment_status' => 'partial']);
        // No service identity means we cannot infer which paid allocation owns this legacy file.
        $this->get($url)->assertNotFound();
    }

    public function test_existing_private_preview_survives_active_work_and_new_pending_covers_cannot_crowd_it_out(): void
    {
        [$shoot, , $old] = $this->delivered();
        $pending = $this->activate($shoot, true);
        for ($index = 0; $index < 8; $index++) {
            $this->file($shoot, $pending, 'new-cover-'.$index, ['is_cover' => true, 'sort_order' => 1]);
        }
        $viewer = User::factory()->create(['role' => 'client']);
        $request = Request::create('/api/shoots', 'GET', ['private_listing' => '1', 'listing_scope' => 'all']);
        $request->setUserResolver(fn () => $viewer);
        $response = app(ShootListingService::class)->index($request, $viewer, function () {
            $this->fail('Private discovery must not invoke the operational presenter.');
        });
        $this->assertSame(200, $response->status());
        $data = $response->getData(true);
        $this->assertSame(1, $data['meta']['count']);
        $item = $data['data'][0];
        $this->assertSame($shoot->id, $item['id']);
        $this->assertStringContainsString('/tour-image/'.$shoot->id.'/'.$old->id.'/grid/', $item['preview_image_url']);
        $this->get($item['preview_image_url'])->assertOk()->assertStreamedContent('grid-old');
        foreach (['client', 'files', 'total_quote', 'payment_status', 'active_work_started_at', 'admin_verified_at'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
    }

    public function test_retaining_photo_renditions_does_not_drop_a_previously_delivered_video(): void
    {
        [$shoot, $oldLine, , $url] = $this->delivered();
        $video = $this->file($shoot, $oldLine, 'old-video', ['filename' => 'old-video.mp4', 'stored_filename' => 'old-video.mp4',
            'file_type' => 'video/mp4', 'mime_type' => 'video/mp4', 'media_type' => 'video',
            'created_at' => '2026-11-02 08:00:00', 'verified_at' => '2026-11-02 09:00:00']);
        $this->activate($shoot);
        $payload = app(ShootPublicAssetsService::class)->buildTypedPublicAssets($shoot->fresh(), 'branded', false);
        $this->assertSame([$url], $payload['photos']);
        $this->assertCount(1, $payload['videos']);
        $this->assertStringNotContainsString('/public/tour-image/', $payload['videos'][0]);
        $this->assertTrue(app(PublicTourImage::class)->canRetainPublishedFile($video->fresh(), $shoot->fresh()));
        $this->assertFalse(app(PublicTourImage::class)->canPublishFile($video->fresh(), $shoot->fresh()));
    }

    private function delivered(): array
    {
        return Model::withoutEvents(function (): array {
            $shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
                'scheduled_at' => '2026-11-01 15:00:00', 'scheduled_date' => '2026-11-01', 'timezone' => 'America/New_York',
                'admin_verified_at' => '2026-11-02 10:00:00', 'completed_at' => '2026-11-02 10:00:00',
                'payment_status' => 'paid', 'base_quote' => 100, 'total_quote' => 100, 'bypass_paywall' => false,
                'is_private_listing' => true, 'is_listing_hidden' => false]);
            $service = Service::factory()->unbracketedPhoto()->create();
            $line = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'price' => 100, 'quantity' => 1,
                'is_deliverable' => true, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => '2026-11-02 10:00:00']);
            $payment = Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 100, 'status' => Payment::STATUS_COMPLETED]);
            PaymentServiceAllocation::create(['payment_id' => $payment->id, 'shoot_service_id' => $line->id, 'amount' => 100]);
            $file = $this->file($shoot, $line, 'old', ['created_at' => '2026-11-02 08:00:00', 'verified_at' => '2026-11-02 09:00:00']);
            return [$shoot->fresh(), $line->fresh(), $file, app(PublicTourImage::class)->url($file, 'web')];
        });
    }

    private function activate(Shoot $shoot, bool $partial = false): ShootService
    {
        $pending = Model::withoutEvents(function () use ($shoot, $partial): ShootService {
            $service = Service::factory()->unbracketedPhoto()->create();
            $line = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'price' => 50, 'quantity' => 1,
                'is_deliverable' => true, 'workflow_status' => 'pending', 'delivery_status' => 'not_started', 'scheduled_at' => '2026-11-04 15:00:00']);
            $shoot->forceFill(['total_quote' => 150, 'payment_status' => $partial ? 'partial' : 'paid'])->saveQuietly();
            return $line;
        });
        Shoot::withoutEvents(fn () => app(ShootActiveWorkLifecycle::class)->begin($shoot->fresh()));
        return $pending->fresh();
    }

    private function file(Shoot $shoot, ShootService $line, string $name, array $attributes = []): ShootFile
    {
        $file = new ShootFile;
        $file->forceFill($attributes + [
            'shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => $name.'.jpg', 'stored_filename' => $name.'.jpg',
            'path' => "shoots/{$shoot->id}/completed/$name.jpg", 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 100, 'uploaded_by' => $shoot->client_id, 'media_type' => 'edited',
            'workflow_stage' => 'verified', 'scan_status' => 'clean', 'is_hidden' => false,
            'web_path' => "shoots/{$shoot->id}/web/$name.jpg", 'grid_path' => "shoots/{$shoot->id}/grid/$name.jpg",
            'thumbnail_path' => "shoots/{$shoot->id}/thumbs/$name.jpg", 'verified_at' => now(),
        ])->saveQuietly();
        foreach (['path' => 'original-'.$name, 'web_path' => 'web-'.$name, 'grid_path' => 'grid-'.$name, 'thumbnail_path' => 'thumb-'.$name] as $column => $bytes) {
            Storage::disk('local')->put($file->{$column}, $bytes);
        }
        return $file->fresh();
    }
}
