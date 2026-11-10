<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{DeliveryPlanService, ShootServiceItemSupport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Tests\TestCase;

class SimpleFinalizeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake(); Storage::fake('public');
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function fixture(): array
    {
        $actor = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create(['status' => 'ready', 'workflow_status' => 'ready',
            'scheduled_at' => now()->subDay(), 'scheduled_date' => '2026-11-09', 'timezone' => 'UTC',
            'total_quote' => 295, 'payment_status' => 'unpaid', 'admin_verified_at' => null, 'completed_at' => null]);
        $service = Service::factory()->create(['name' => 'Photos and tour', 'upload_intake_type' => 'photo', 'requires_editing' => false]);
        $item = $shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 250, 'quantity' => 1,
            'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $item->forceFill(['delivery_requirements' => [
            ['key' => 'photo', 'kind' => 'photo', 'label' => 'Photos'],
            ['key' => 'tour', 'kind' => 'tour', 'label' => 'Property tour'],
        ]])->save();
        $floorplan = $shoot->serviceItems()->create(['service_id' => Service::factory()->create(['name' => 'Floorplan', 'upload_intake_type' => 'none'])->id,
            'price' => 45, 'quantity' => 1, 'workflow_status' => 'pending', 'delivery_status' => 'not_started', 'scheduled_at' => now()->subDay()]);
        $floorplan->forceFill(['delivery_requirements' => [['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplan']]])->save();
        $url = 'https://youriguide.com/simple-finalise-example';
        $shoot->forceFill(['iguide_tour_url' => $url, 'iguide_property_id' => 'simple-example', 'iguide_data' => [
            'service_line_id' => $item->id, 'ownership_property_id' => 'simple-example', 'ownership_tour_url' => $url,
            'delivery_readiness' => ['state' => 'ready', 'property_id' => 'simple-example', 'tour_url' => $url],
        ]])->save();
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'photo.jpg',
            'stored_filename' => 'photo.jpg', 'path' => 'shoots/'.$shoot->id.'/photo.jpg', 'file_size' => 100,
            'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'media_type' => 'edited',
            'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor->id])->save();
        return [$shoot->fresh(), $item, $floorplan, $file, $actor];
    }

    private function runDelivery($shoot, $actor, array $ids, array $options = []): array
    {
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot, $actor, $options + [
            'mode' => 'auto', 'component_ids' => $ids, 'confirmed' => true,
            'plan_revision' => app(DeliveryPlanService::class)->plan($shoot, $actor)['revision'],
        ]);
        $job = new FinalizeShootJob($shoot->id, $actor->id, null, null, false, $intent['idempotency_key'], $intent);
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));
        return $intent;
    }

    public function test_one_action_releases_ready_files_and_provider_output_and_keeps_floorplan_open(): void
    {
        [$shoot, $item, $floorplan, $file, $actor] = $this->fixture();
        $intent = $this->runDelivery($shoot, $actor, [$item->id.':photo', $item->id.':tour']);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertSame('partially_delivered', $shoot->fresh()->delivery_status);
        $this->assertNull($shoot->fresh()->admin_verified_at);
        $this->assertNotSame('delivered', $floorplan->fresh()->delivery_status);
        $this->assertSame(['media', 'external'], DB::table('shoot_delivery_receipts')->orderBy('id')->pluck('method')->all());
        $this->assertTrue(app(DeliveryPlanService::class)->isReplay($shoot->fresh(), $actor, $intent));
        $summary = collect(app(ShootServiceItemSupport::class)->summaries($shoot->fresh()))->firstWhere('id', $item->id);
        $this->assertSame(['delivered', 'delivered'], array_column($summary['delivery_components'], 'status'));
        $this->assertStringNotContainsString('youriguide', json_encode($summary['delivery_components']));
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
    }

    public function test_without_content_keeps_real_files_and_provider_receipts_and_only_exempts_missing_work(): void
    {
        [$shoot, $item, $floorplan, $file, $actor] = $this->fixture();
        $this->runDelivery($shoot, $actor, [$item->id.':photo', $item->id.':tour', $floorplan->id.':floorplan'], [
            'allow_without_content' => true, 'reason' => 'Client waived the floorplan',
        ]);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertSame('delivered', $shoot->fresh()->delivery_status);
        $this->assertSame(['media', 'external', 'manual_no_content'], DB::table('shoot_delivery_receipts')->orderBy('id')->pluck('method')->all());
        $this->assertSame([$file->id], app(DeliveryPlanService::class)->releasedFileIds($shoot->fresh()));
        $summary = app(DeliveryPlanService::class)->componentSummaries($shoot->fresh());
        $this->assertTrue($summary[$floorplan->id][0]['without_content']);
    }

    public function test_empty_duplicate_foreign_or_unconfirmed_selections_and_missing_exception_reason_are_rejected(): void
    {
        [$shoot, $item, $floorplan, , $actor] = $this->fixture();
        foreach ([
            ['component_ids' => []], ['component_ids' => [$item->id.':photo', $item->id.':photo']],
            ['component_ids' => ['999999:photo']], ['component_ids' => [$floorplan->id.':floorplan']],
            ['component_ids' => [$floorplan->id.':floorplan'], 'allow_without_content' => true],
            ['component_ids' => [$item->id.':tour'], 'confirmed' => false],
        ] as $payload) $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload + ['mode' => 'auto', 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
    }

    public function test_stale_mixed_selection_cannot_partially_commit(): void
    {
        [$shoot, $item, , $file, $actor] = $this->fixture();
        $plan = app(DeliveryPlanService::class)->plan($shoot, $actor);
        $file->forceFill(['content_version' => 2])->save();
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', [
            'mode' => 'auto', 'component_ids' => [$item->id.':photo', $item->id.':tour'],
            'confirmed' => true, 'plan_revision' => $plan['revision'],
        ])->assertConflict();
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
    }

    public function test_client_delivery_list_includes_partial_content_without_closing_the_staff_work_queue(): void
    {
        [$shoot, $item, , , $actor] = $this->fixture();
        $client = User::factory()->create(['role' => 'client']);
        $shoot->forceFill(['client_id' => $client->id])->save();
        $this->runDelivery($shoot->fresh(), $actor, [$item->id.':photo']);
        $shoot->fresh()->forceFill(['status' => 'editing', 'workflow_status' => 'editing'])->save();
        $this->actingAs($client)->getJson('/api/shoots?tab=delivered&no_cache=1')->assertOk()->assertJsonPath('meta.count', 1);
        $this->actingAs($actor)->getJson('/api/shoots?tab=delivered&no_cache=1')->assertOk()->assertJsonPath('meta.count', 0);
        $this->actingAs(User::factory()->create(['role' => 'client']))->getJson('/api/shoots?tab=delivered&no_cache=1')->assertOk()->assertJsonPath('meta.count', 0);
    }

    public function test_unconfirmed_provider_readiness_requires_explicit_external_review_and_never_auto_selects(): void
    {
        [$shoot, $item, , , $actor] = $this->fixture();
        $data = $shoot->iguide_data; unset($data['delivery_readiness']);
        $shoot->forceFill(['iguide_data' => $data])->save();
        $plan = app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor);
        $this->assertNotContains($item->id.':tour', $plan['ready_component_ids']);
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', [
            'mode' => 'auto', 'component_ids' => [$item->id.':tour'], 'confirmed' => false,
        ])->assertUnprocessable();
        $this->runDelivery($shoot->fresh(), $actor, [$item->id.':tour']);
        $this->assertSame('external', DB::table('shoot_delivery_receipts')->value('method'));
    }

    public function test_future_held_and_unauthorized_work_cannot_use_the_exception_toggle(): void
    {
        [$shoot, $item, , , $actor] = $this->fixture();
        $payload = ['mode' => 'auto', 'component_ids' => [$item->id.':photo'], 'confirmed' => true,
            'allow_without_content' => true, 'reason' => 'Reviewed exception'];
        $item->forceFill(['scheduled_at' => now()->addDay()])->save();
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload)->assertUnprocessable();
        $item->forceFill(['scheduled_at' => now()->subDay()])->save();
        $shoot->forceFill(['status' => 'on_hold', 'workflow_status' => 'on_hold'])->save();
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload)->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'client']))->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload)->assertForbidden();
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
    }
}
