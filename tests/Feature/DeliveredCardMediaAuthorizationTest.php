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
        return ShootFile::withoutEvents(fn () => ShootFile::create(array_replace([
            'shoot_id' => $shoot->id, 'filename' => $name.'.jpg', 'stored_filename' => $name.'.jpg',
            'path' => 'photos/'.$name.'.jpg', 'grid_path' => 'photos/'.$name.'-grid.jpg',
            'web_path' => 'photos/'.$name.'-web.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'media_type' => 'edited', 'workflow_stage' => ShootFile::STAGE_VERIFIED, 'file_size' => 100,
            'is_hidden' => false, 'is_extra' => false, 'uploaded_by' => $shoot->client_id,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ], $attributes)));
    }
}
