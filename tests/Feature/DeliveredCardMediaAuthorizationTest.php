<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootEditingDispatch, ShootFile, ShootService, User};
use App\Services\Shoots\ShootListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, DB, Http, Mail, Notification};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveredCardMediaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
    }

    public static function contractors(): array { return [['photographer'], ['editor']]; }

    #[DataProvider('contractors')]
    public function test_service_previews_are_scoped_before_six_and_revoked_after_reassignment(string $role): void
    {
        $viewer = User::factory()->create(['role' => $role, 'metadata' => ['editing_capabilities' => ['photo']]]);
        $peer = User::factory()->create(['role' => $role]);
        $shoot = $this->shoot();
        $field = $role === 'photographer' ? 'photographer_id' : 'editor_id';
        $own = $this->item($shoot, [$field => $viewer->id]);
        $retained = $this->item($shoot, [$field => $viewer->id]);
        $foreign = $this->item($shoot, [$field => $peer->id]);
        // Peer covers sort ahead of every allowed photo; limiting first loses all safe previews.
        foreach (range(1, 7) as $index) { $this->photo($shoot, 'peer-secret-'.$index, ['shoot_service_id' => $foreign->id, 'is_cover' => true]); }
        $this->photo($shoot, 'own', ['shoot_service_id' => $own->id]);
        $this->photo($shoot, 'retained', ['shoot_service_id' => $retained->id]);
        $this->photo($shoot, 'hidden-secret', ['shoot_service_id' => $own->id, 'is_hidden' => true]);
        Sanctum::actingAs($viewer);
        $before = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(2, $before['preview_images']);
        $this->assertStringContainsString('/own-grid.jpg', json_encode($before));
        $this->assertStringContainsString('/retained-grid.jpg', json_encode($before));
        $this->assertStringNotContainsString('peer-secret', json_encode($before));
        $this->assertStringNotContainsString('hidden-secret', json_encode($before));
        $this->assertNull($before['count']);
        $own->update([$field => $peer->id]);
        $after = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(1, $after['preview_images']);
        $this->assertStringContainsString('/retained-grid.jpg', json_encode($after));
        $this->assertStringNotContainsString('/own-grid.jpg', json_encode($after));
        // Existing authentication/assignment presence broadcasts are not media
        // generation. Reading previews must never start derivative/archive jobs.
        Http::assertNothingSent();
        Bus::assertNotDispatched(\App\Jobs\GenerateWatermarkedImageJob::class);
        Bus::assertNotDispatched(\App\Jobs\GenerateShootMediaArchiveJob::class);
    }

    public function test_non_required_extra_is_not_mistaken_for_an_editor_deliverable(): void
    {
        $viewer = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $shoot = $this->shoot();
        $item = $this->item($shoot, ['editor_id' => $viewer->id]);
        $this->photo($shoot, 'not-required-secret', ['shoot_service_id' => $item->id, 'is_extra' => true, 'required_for_editing' => false]);
        $this->photo($shoot, 'required', ['shoot_service_id' => $item->id, 'is_extra' => true, 'required_for_editing' => true]);
        Sanctum::actingAs($viewer);
        $value = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(1, $value['preview_images']);
        $this->assertStringNotContainsString('not-required-secret', json_encode($value));
        $this->assertStringContainsString('/required-grid.jpg', json_encode($value));
    }

    public function test_preview_policy_batches_assignment_reads_without_attaching_raw_relations_to_page_models(): void
    {
        $viewer = User::factory()->photographer()->create();
        $shoot = $this->shoot();
        $item = $this->item($shoot, ['photographer_id' => $viewer->id]);
        foreach (range(1, 12) as $index) { $this->photo($shoot, 'batch-'.$index, ['shoot_service_id' => $item->id]); }
        $page = Shoot::with('services')->whereKey($shoot->id)->get();
        Sanctum::actingAs($viewer);
        DB::enableQueryLog();
        try {
            app(ShootListingService::class)->eagerLoadListCardPreviewFiles($page);
            $queries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'from "shoot_service"'));
            $this->assertCount(1, $queries, 'Assignment reads must be batched, not repeated for each candidate.');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertFalse($page->first()->relationLoaded('serviceItems'));
        $this->assertCount(6, $page->first()->getAttribute('delivery_card_media')['preview_images']);
    }

    public function test_authorized_video_editor_retains_read_only_final_photo_references(): void
    {
        $viewer = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $photoEditor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $shoot = $this->shoot();
        $service = Service::factory()->photoVideoIntake()->create(['requires_editing' => true]);
        $item = $this->item($shoot, ['service_id' => $service->id, 'editor_id' => $photoEditor->id, 'video_editor_id' => $viewer->id]);
        $this->photo($shoot, 'reference', ['shoot_service_id' => $item->id]);
        Sanctum::actingAs($viewer);
        $value = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(1, $value['preview_images']);
        $this->assertStringContainsString('/reference-grid.jpg', json_encode($value));
    }

    public function test_dispatch_only_editor_gets_only_source_previews_and_loses_them_when_task_closes(): void
    {
        $viewer = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $shoot = $this->shoot();
        $own = $this->photo($shoot, 'dispatch-source');
        $this->photo($shoot, 'dispatch-peer-secret', ['is_cover' => true]);
        $dispatch = ShootEditingDispatch::create([
            'request_id' => (string) Str::uuid(), 'shoot_id' => $shoot->id, 'created_by' => $shoot->client_id,
            'scope' => 'selected', 'destination' => 'human', 'workflow' => 'human-photo',
            'input_hash' => str_repeat('a', 64), 'plan' => [], 'status' => 'ready',
        ]);
        $task = $dispatch->items()->create([
            'input_key' => 'photo:source', 'workflow' => 'human-photo', 'sources' => [['id' => $own->id]],
            'lane' => 'photo', 'destination' => 'human', 'editor_id' => $viewer->id, 'status' => 'ready',
        ]);
        Sanctum::actingAs($viewer);
        $value = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(1, $value['preview_images']);
        $this->assertStringContainsString('/dispatch-source-grid.jpg', json_encode($value));
        $this->assertStringNotContainsString('dispatch-peer-secret', json_encode($value));
        $task->update(['status' => 'completed']);
        $this->listing()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_keeps_eligible_global_previews_and_locked_client_keeps_only_safe_watermarks(): void
    {
        $shoot = $this->shoot(['payment_status' => 'unpaid']);
        $this->photo($shoot, 'interior', ['watermarked_web_path' => 'watermarked/interior.jpg']);
        $this->photo($shoot, 'no-safe-watermark');
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->listing()->assertOk()->assertJsonCount(2, 'data.0.delivery_card_media.preview_images');
        Sanctum::actingAs($shoot->client);
        $value = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertTrue($value['uses_watermark']);
        $this->assertCount(1, $value['preview_images']);
        $this->assertStringContainsString('/watermarked/interior.jpg', $value['hero_image']);
        $this->assertStringNotContainsString('/photos/', json_encode($value));
        Http::assertNothingSent(); Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_active_client_previews_retain_only_released_lines_before_the_six_image_limit(): void
    {
        $shoot = $this->shoot([
            'status' => 'active', 'workflow_status' => 'active',
            'active_work_started_at' => '2026-11-03 13:00:00', 'active_work_completed_at' => null,
            'admin_verified_at' => '2026-11-02 12:00:00',
        ]);
        $old = $this->item($shoot, ['is_deliverable' => true, 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'delivered_at' => '2026-11-02 12:00:00']);
        $pending = $this->item($shoot, ['is_deliverable' => true, 'workflow_status' => 'pending', 'delivery_status' => 'not_started']);
        $this->photo($shoot, 'released-old', ['shoot_service_id' => $old->id]);
        $this->photo($shoot, 'legacy-old', ['created_at' => '2026-11-02 10:00:00', 'verified_at' => '2026-11-02 11:00:00']);
        $this->photo($shoot, 'legacy-new-secret', ['created_at' => '2026-11-03 14:00:00']);
        foreach (range(1, 7) as $index) {
            $this->photo($shoot, 'pending-secret-'.$index, ['shoot_service_id' => $pending->id, 'is_cover' => true]);
        }
        $this->activateFixture($shoot);
        Sanctum::actingAs($shoot->client);
        $this->assertSame('client', auth()->user()->role);
        $this->assertTrue(app(\App\Services\Shoots\ShootAuthorizationSupport::class)->isClientUser(auth()->user()));
        $before = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(2, $before['preview_images']);
        $this->assertStringContainsString('/released-old-grid.jpg', json_encode($before));
        $this->assertStringContainsString('/legacy-old-grid.jpg', json_encode($before));
        $this->assertStringNotContainsString('secret', json_encode($before));

        // A newly finalized service is safe while another service keeps the order Active.
        $this->item($shoot, ['is_deliverable' => true, 'workflow_status' => 'pending', 'delivery_status' => 'not_started']);
        $admin = User::factory()->create(['role' => 'superadmin']);
        DB::transaction(fn () => app(\App\Services\Shoots\DeliveryPlanService::class)->commitSelection($shoot->fresh(), $admin,
            ['mode' => 'media', 'component_ids' => [$pending->id.':photo']]));
        $pending->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'delivered_at' => '2026-11-03 15:00:00'])->saveQuietly();
        $after = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(6, $after['preview_images']);
        $this->assertStringContainsString('/pending-secret-', json_encode($after));
        $this->assertStringNotContainsString('legacy-new-secret', json_encode($after));
    }

    public function test_active_client_retention_fails_closed_for_locked_or_suspended_orders(): void
    {
        $shoot = $this->shoot(['status' => 'active', 'workflow_status' => 'active',
            'active_work_started_at' => '2026-11-03 13:00:00', 'active_work_completed_at' => null]);
        $item = $this->item($shoot, ['is_deliverable' => true, 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'delivered_at' => '2026-11-02 12:00:00']);
        $this->photo($shoot, 'retained', ['shoot_service_id' => $item->id, 'watermarked_web_path' => 'watermarked/retained.jpg']);
        $this->activateFixture($shoot);
        Sanctum::actingAs($shoot->client);
        $page = Shoot::whereKey($shoot->id)->get();
        app(ShootListingService::class)->eagerLoadListCardPreviewFiles($page);
        $this->assertCount(1, $page->first()->getAttribute('delivery_card_media')['preview_images']);
        foreach ([['payment_status' => 'unpaid'], ['workflow_status' => 'on_hold', 'payment_status' => 'paid']] as $change) {
            $shoot->forceFill($change)->saveQuietly();
            $page = Shoot::whereKey($shoot->id)->get();
            app(ShootListingService::class)->eagerLoadListCardPreviewFiles($page);
            $this->assertSame([], $page->first()->getAttribute('delivery_card_media')['preview_images']);
        }
    }

    public function test_active_client_retention_batches_service_identity_without_serializing_policy_relations(): void
    {
        $shoot = $this->shoot(['status' => 'active', 'workflow_status' => 'active',
            'active_work_started_at' => '2026-11-03 13:00:00', 'active_work_completed_at' => null]);
        $item = $this->item($shoot, ['is_deliverable' => true, 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'delivered_at' => '2026-11-02 12:00:00']);
        foreach (range(1, 12) as $index) { $this->photo($shoot, 'active-batch-'.$index, ['shoot_service_id' => $item->id]); }
        $this->activateFixture($shoot);
        Sanctum::actingAs($shoot->client);
        $page = Shoot::whereKey($shoot->id)->get();
        DB::enableQueryLog();
        try {
            app(ShootListingService::class)->eagerLoadListCardPreviewFiles($page);
            $queries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'from "shoot_service"'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog();
        }
        $this->assertFalse($page->first()->relationLoaded('serviceItems'));
        $this->assertFalse($page->first()->list_preview_files->first()->relationLoaded('serviceItem'));
        $this->assertCount(6, $page->first()->getAttribute('delivery_card_media')['preview_images']);
    }

    private function activateFixture(Shoot $shoot): void
    {
        // Build service rows first: creating a delivered line with no remaining
        // work legitimately closes an existing cycle through its observer.
        $shoot->refresh()->forceFill(['status' => 'active', 'workflow_status' => 'active',
            'active_work_started_at' => '2026-11-03 13:00:00', 'active_work_completed_at' => null])->saveQuietly();
        $this->assertTrue(app(\App\Services\Shoots\ShootActiveWorkLifecycle::class)->hasActiveWork($shoot->fresh()));
    }

    public static function retainedClientAccess(): array { return [['paid'], ['force_unlocked']]; }

    #[DataProvider('retainedClientAccess')]
    public function test_new_unpaid_active_work_cannot_relock_released_paid_or_unlocked_service_previews(string $access): void
    {
        $shoot = $this->shoot(['payment_status' => 'partial', 'total_quote' => 200]);
        $released = $this->item($shoot, ['is_deliverable' => true, 'workflow_status' => 'delivered',
            'delivery_status' => 'delivered', 'delivered_at' => '2026-11-02 12:00:00',
            'force_unlock_delivery' => $access === 'force_unlocked']);
        $pending = $this->item($shoot, ['is_deliverable' => true, 'workflow_status' => 'pending', 'delivery_status' => 'not_started']);
        if ($access === 'paid') {
            \Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($shoot, $released) {
                $payment = \App\Models\Payment::factory()->create(['shoot_id' => $shoot->id, 'invoice_id' => null,
                    'amount' => 100, 'status' => \App\Models\Payment::STATUS_COMPLETED]);
                \App\Models\PaymentServiceAllocation::create(['payment_id' => $payment->id,
                    'shoot_service_id' => $released->id, 'amount' => 100]);
            });
        }
        // No watermarked derivatives exist: this must use the released line's
        // normal optimized rendition, not silently return an empty mosaic.
        $this->photo($shoot, 'old-paid', ['shoot_service_id' => $released->id]);
        foreach (range(1, 7) as $index) {
            $this->photo($shoot, 'new-unpaid-secret-'.$index, ['shoot_service_id' => $pending->id, 'is_cover' => true]);
        }
        $this->activateFixture($shoot);
        Sanctum::actingAs($shoot->client);
        $value = $this->listing()->assertOk()->json('data.0.delivery_card_media');
        $this->assertCount(1, $value['preview_images']);
        $this->assertStringContainsString('/photos/old-paid-grid.jpg', $value['hero_image']);
        $this->assertFalse($value['uses_watermark']);
        $this->assertStringNotContainsString('new-unpaid-secret', json_encode($value));
    }

    private function listing()
    {
        return $this->getJson('/api/shoots?tab=delivered&view=card&include_files=false&no_cache=true&include_filters=false');
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::withoutEvents(fn () => Shoot::factory()->create(array_replace([
            'status' => 'delivered', 'workflow_status' => 'delivered', 'editor_id' => null,
            'hero_image' => null, 'payment_status' => 'paid', 'total_quote' => 200, 'admin_verified_at' => '2026-11-03 12:00:00',
        ], $attributes)));
    }

    private function item(Shoot $shoot, array $attributes): ShootService
    {
        return ShootService::create(array_replace([
            'shoot_id' => $shoot->id, 'service_id' => Service::factory()->unbracketedPhoto()->create(['requires_editing' => true])->id,
            'quantity' => 1, 'price' => 100,
        ], $attributes));
    }

    private function photo(Shoot $shoot, string $name, array $attributes = []): ShootFile
    {
        $file = new ShootFile;
        $file->forceFill(array_replace([
            'shoot_id' => $shoot->id, 'filename' => $name.'.jpg', 'stored_filename' => $name.'.jpg',
            'path' => 'photos/'.$name.'.jpg', 'grid_path' => 'photos/'.$name.'-grid.jpg',
            'web_path' => 'photos/'.$name.'-web.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'media_type' => 'edited', 'workflow_stage' => ShootFile::STAGE_VERIFIED, 'file_size' => 100,
            'is_hidden' => false, 'is_extra' => false, 'uploaded_by' => $shoot->client_id,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ], $attributes))->saveQuietly();
        return $file;
    }
}
