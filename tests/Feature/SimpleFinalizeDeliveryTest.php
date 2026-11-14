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
        $revision = app(\App\Services\Shoots\ShootMediaMutationSupportService::class)->currentMediaRevision($shoot);
        $intent = $this->runDelivery($shoot, $actor, [$item->id.':photo', $item->id.':tour']);
        $this->assertGreaterThan($revision, app(\App\Services\Shoots\ShootMediaMutationSupportService::class)->currentMediaRevision($shoot));
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

    public function test_without_content_keeps_missing_work_pending_and_can_be_replayed(): void
    {
        [$shoot, $item, $floorplan, $file, $actor] = $this->fixture();
        $intent = $this->runDelivery($shoot, $actor, [$item->id.':photo', $item->id.':tour', $floorplan->id.':floorplan'], [
            'allow_without_content' => true, 'reason' => 'Deliver photos while the floorplan is pending',
        ]);
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertSame('partially_delivered', $shoot->fresh()->delivery_status);
        $this->assertNull($shoot->fresh()->completed_at);
        $this->assertSame(['media', 'external', 'defer'], DB::table('shoot_delivery_receipts')->orderBy('id')->pluck('method')->all());
        $this->assertSame([$file->id], app(DeliveryPlanService::class)->releasedFileIds($shoot->fresh()));
        $summary = app(DeliveryPlanService::class)->componentSummaries($shoot->fresh());
        $this->assertSame('pending', $summary[$floorplan->id][0]['status']);
        $this->assertFalse($summary[$floorplan->id][0]['without_content']);
        $this->assertTrue($summary[$floorplan->id][0]['deferred']);
        $this->assertFalse(app(DeliveryPlanService::class)->canFinalizeDelivery($shoot->fresh(), $actor));
        $this->assertTrue(app(DeliveryPlanService::class)->isReplay($shoot->fresh(), $actor, $intent));
        $this->actingAs($actor)->getJson('/api/shoots/'.$shoot->id)->assertOk()->assertJsonPath('data.can_finalize_delivery', false);
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

    public function test_finalise_submits_raw_then_edits_and_replay_keeps_the_reviewed_revision(): void
    {
        [$shoot, $item, , $file, $actor] = $this->fixture();
        $actor->forceFill(['role' => 'editing_manager'])->save();
        $shoot->forceFill(['status' => 'scheduled', 'workflow_status' => 'scheduled',
            'photos_uploaded_at' => null, 'editing_completed_at' => null])->save();
        $raw = $file->replicate();
        $raw->forceFill(['filename' => 'raw.jpg', 'stored_filename' => 'raw.jpg', 'workflow_stage' => 'todo', 'media_type' => 'raw'])->save();
        $payload = ['mode' => 'auto', 'component_ids' => [$item->id.':photo'], 'confirmed' => true,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'plan_revision' => app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['revision']];
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload)->assertAccepted();
        $this->assertNotNull($shoot->fresh()->photos_uploaded_at);
        $this->assertSame('ready', $shoot->fresh()->workflow_status);
        $job = Queue::pushed(FinalizeShootJob::class)->first();
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));
        $this->assertSame('verified', $file->fresh()->workflow_stage);
        $this->assertFalse(app(\App\Services\Shoots\ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot->fresh(), $actor));
        $this->assertFalse(app(\App\Services\Shoots\ShootSubmissionCapabilityService::class)->canSubmitEdits($shoot->fresh(), $actor));
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload)
            ->assertOk()->assertJsonPath('data.already_applied', true);
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
    }

    public function test_automatic_submission_does_not_silently_deliver_a_replaced_file(): void
    {
        [$shoot, $item, , $file, $actor] = $this->fixture();
        $shoot->forceFill(['status' => 'editing', 'workflow_status' => 'editing', 'editing_completed_at' => null])->save();
        $this->mock(\App\Services\Shoots\Actions\FinalizeEditedUploadAction::class)->shouldReceive('execute')->once()
            ->andReturnUsing(function () use ($file) {
                $file->forceFill(['content_version' => 2])->save();
                return ['status' => 200, 'payload' => ['workflow_status_changed' => false]];
            });
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', [
            'mode' => 'auto', 'component_ids' => [$item->id.':photo'], 'confirmed' => true,
            'plan_revision' => app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['revision'],
        ])->assertConflict();
        Queue::assertNotPushed(FinalizeShootJob::class);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertSame('completed', $file->fresh()->workflow_stage);
    }

    public function test_bundled_pending_floorplan_keeps_photo_release_and_can_finish_later(): void
    {
        [$shoot, $item, $floorplan, $file, $actor] = $this->fixture();
        $floorplan->delete();
        $item->forceFill(['delivery_requirements' => [
            ['key' => 'photo', 'kind' => 'photo', 'label' => 'Photos'],
            ['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans'],
        ]])->save();
        $shoot->forceFill(['iguide_property_id' => null, 'iguide_tour_url' => null, 'iguide_data' => null])->save();
        $this->runDelivery($shoot->fresh(), $actor, [$item->id.':photo', $item->id.':floorplan'], [
            'allow_without_content' => true, 'reason' => 'Urgent photo delivery',
        ]);
        $this->assertSame('partially_delivered', $shoot->fresh()->delivery_status);
        $this->assertNotSame('delivered', $item->fresh()->delivery_status);
        $this->assertFalse(app(DeliveryPlanService::class)->canFinalizeDelivery($shoot->fresh(), $actor));
        $original = DB::table('shoot_delivery_receipts')->where('component_key', 'photo')->first();
        $planFile = $file->replicate();
        $planFile->forceFill(['filename' => 'floorplan.jpg', 'stored_filename' => 'floorplan.jpg', 'media_type' => 'floorplan', 'workflow_stage' => 'completed'])->save();
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $this->assertTrue(app(DeliveryPlanService::class)->canFinalizeDelivery($shoot->fresh(), $actor));
        $this->runDelivery($shoot->fresh(), $actor, [$item->id.':floorplan']);
        $this->assertSame('delivered', $shoot->fresh()->delivery_status);
        $this->assertSame('verified', $planFile->fresh()->workflow_stage);
        $this->assertEquals($original, DB::table('shoot_delivery_receipts')->find($original->id));
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $this->assertFalse(app(DeliveryPlanService::class)->canFinalizeDelivery($shoot->fresh(), $actor));
    }

    public function test_scoped_no_content_receipt_correction_preserves_photo_approval_and_billing(): void
    {
        [$shoot, $item, $floorplan, $file, $actor] = $this->fixture();
        $this->runDelivery($shoot, $actor, [$item->id.':photo', $item->id.':tour']);
        $this->runDelivery($shoot->fresh(), $actor, [$floorplan->id.':floorplan'], [
            'mode' => 'manual_no_content', 'reason' => 'Urgent delivery',
        ]);
        $this->assertSame('delivered', $shoot->fresh()->delivery_status);
        $photo = DB::table('shoot_delivery_receipts')->where('component_key', 'photo')->first();
        $receipt = DB::table('shoot_delivery_receipts')->where('shoot_service_id', $floorplan->id)->first();
        DB::transaction(function () use ($receipt, $floorplan, $shoot) {
            DB::table('shoot_delivery_receipts')->where('id', $receipt->id)->update(['revoked_at' => now()]);
            $corrected = (array) $receipt;
            unset($corrected['id']);
            $corrected['method'] = 'defer';
            $corrected['operation_key'] = (string) \Illuminate\Support\Str::uuid();
            $corrected['evidence'] = json_encode(['confirmed' => true, 'corrects_receipt_id' => $receipt->id]);
            DB::table('shoot_delivery_receipts')->insert($corrected);
            DB::table('shoot_service')->where('id', $floorplan->id)->update([
                'workflow_status' => 'ready', 'delivery_status' => 'ready', 'delivered_at' => null,
            ]);
            DB::table('shoots')->where('id', $shoot->id)->update([
                'status' => 'editing', 'workflow_status' => 'editing', 'admin_verified_at' => null, 'completed_at' => null,
            ]);
        });
        app(DeliveryPlanService::class)->forgetReleasedFiles($shoot);
        $shoot->fresh()->syncServiceItemRollups();
        $this->assertSame('partially_delivered', $shoot->fresh()->delivery_status);
        $this->assertSame('pending', app(DeliveryPlanService::class)->componentSummaries($shoot->fresh())[$floorplan->id][0]['status']);
        $this->assertEquals($photo, DB::table('shoot_delivery_receipts')->find($photo->id));
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($file->fresh()));
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $this->assertEquals(295, $shoot->fresh()->total_quote);
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
