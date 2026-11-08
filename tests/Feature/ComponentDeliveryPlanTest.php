<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{ActiveVideoLinkOutput, DeliveryPlanService, ShootActiveWorkLifecycle, ShootSubmissionCapabilityService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Tests\TestCase;

class ComponentDeliveryPlanTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        Http::preventStrayRequests(); Queue::fake(); Mail::fake(); Notification::fake(); Storage::fake('public');
    }
    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function fixture(string $kind = 'photo_video', bool $editing = true): array
    {
        $actor = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create(['status' => 'ready', 'workflow_status' => 'ready', 'admin_verified_at' => null, 'completed_at' => null,
            'scheduled_at' => now()->subDay(), 'scheduled_date' => '2026-11-04', 'created_at' => now()->subDays(2), 'timezone' => 'UTC', 'total_quote' => 250, 'payment_status' => 'unpaid']);
        $service = Service::factory()->create(['name' => 'Booked package', 'upload_intake_type' => $kind, 'requires_editing' => $editing,
            'included_components' => [['name' => 'Photos', 'upload_intake_type' => $kind, 'uses_hdr_brackets' => false]]]);
        $item = $shoot->serviceItems()->create(['service_id' => $service->id, 'price' => 250, 'quantity' => 1,
            'is_deliverable' => true, 'workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        return [$shoot, $item, $actor];
    }
    private function file(Shoot $shoot, ShootService $item, User $actor, array $attributes = []): ShootFile
    {
        $file = new ShootFile;
        $file->forceFill($attributes + ['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'filename' => 'output.jpg', 'stored_filename' => 'output.jpg',
            'path' => 'shoots/'.$shoot->id.'/output.jpg', 'file_size' => 100, 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'media_type' => 'edited', 'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor->id])->save();
        return $file;
    }
    private function deliver(Shoot $shoot, User $actor, array $intent = []): void
    {
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot->fresh(), $actor, $intent);
        (new FinalizeShootJob($shoot->id, $actor->id, null, null, false, $intent['idempotency_key'], $intent))->handle(app(ShootActivityLogger::class));
    }

    public function test_partial_components_release_without_closing_required_video_or_starting_active(): void
    {
        [$shoot, $item, $actor] = $this->fixture();
        $photo = $this->file($shoot, $item, $actor);
        $raw = $this->file($shoot, $item, $actor, ['workflow_stage' => 'todo', 'media_type' => 'video', 'file_type' => 'video/mp4', 'mime_type' => 'video/mp4', 'created_at' => now()->subDay()]);
        $plan = app(DeliveryPlanService::class)->plan($shoot, $actor);
        $this->assertSame([$item->id.':photo'], $plan['ready_component_ids']);
        $this->deliver($shoot, $actor);
        $this->assertSame('verified', $photo->fresh()->workflow_stage);
        $this->assertSame('todo', $raw->fresh()->workflow_stage);
        $this->assertSame('editing', $shoot->fresh()->workflow_status);
        $this->assertFalse(app(ShootActiveWorkLifecycle::class)->hasActiveWork($shoot->fresh()));
        $this->assertSame([$item->id], app(ShootActiveWorkLifecycle::class)->initialDeliveryItemIds($shoot->fresh()));
        $this->assertContains($raw->id, app(\App\Services\Shoots\ServiceDeliveryEligibility::class)->currentCycleFiles($shoot->fresh(), $shoot->files()->get())->modelKeys());
        $this->assertNull($shoot->fresh()->admin_verified_at);
        $this->assertNotSame('delivered', $item->fresh()->workflow_status);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($photo->fresh()));
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
    }

    public function test_video_remains_required_without_human_editing_and_wrong_mime_cannot_finish_it(): void
    {
        [$shoot, $item, $actor] = $this->fixture(editing: false);
        $this->file($shoot, $item, $actor, ['media_type' => 'video']); // Actual image MIME wins.
        $this->deliver($shoot, $actor);
        $plan = app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor);
        $video = collect($plan['components'])->firstWhere('kind', 'video');
        $this->assertSame('pending', $video['status']);
        $this->assertContains('video_pending', $video['reason_codes']);
        $this->assertSame('partially_delivered', $shoot->fresh()->delivery_status);
    }

    public function test_unsafe_hidden_reference_and_raw_files_cannot_supply_completion(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        foreach ([['scan_status' => 'quarantined'], ['scan_status' => 'infected'], ['is_hidden' => true], ['media_type' => 'reference'], ['media_type' => 'raw'], ['is_extra' => true]] as $attributes) $this->file($shoot, $item, $actor, $attributes);
        $this->assertSame([], app(DeliveryPlanService::class)->plan($shoot, $actor)['ready_component_ids']);
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
    }

    public function test_scoped_manual_completion_from_scheduled_requires_reason_and_keeps_other_components_pending(): void
    {
        [$shoot, $item] = $this->fixture();
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $shoot->forceFill(['status' => 'scheduled', 'workflow_status' => 'scheduled'])->save();
        $this->deliver($shoot, $manager, ['component_ids' => [$item->id.':photo'], 'mode' => 'manual_no_content', 'reason' => 'Delivered outside the application', 'confirmed' => true]);
        $this->assertDatabaseHas('shoot_delivery_receipts', ['shoot_service_id' => $item->id, 'method' => 'manual_no_content', 'actor_id' => $manager->id]);
        $this->assertSame('editing', $shoot->fresh()->workflow_status);
        $this->assertNotSame('delivered', $item->fresh()->delivery_status);
        $this->assertSame([], app(DeliveryPlanService::class)->releasedFileIds($shoot));
    }

    public function test_external_iguide_and_hosted_floorplans_can_be_reviewed_from_scheduled_without_media(): void
    {
        [$shoot, $item, $actor] = $this->fixture('none');
        $item->service->forceFill(['included_components' => [
            ['name' => 'iGuide tour', 'upload_intake_type' => 'none', 'uses_hdr_brackets' => false],
            ['name' => 'Floorplans', 'upload_intake_type' => 'none', 'uses_hdr_brackets' => false]]])->save();
        $item->forceFill(['delivery_requirements' => null])->save();
        $shoot->forceFill(['status' => 'scheduled', 'workflow_status' => 'scheduled', 'iguide_tour_url' => 'https://youriguide.com/reviewed-property', 'iguide_property_id' => 'property-123'])->save();
        $plan = app(DeliveryPlanService::class)->plan($shoot, $actor);
        $this->assertSame([], $plan['ready_component_ids']); // A URL alone is not readiness.
        $this->assertSame('external_review_required', $plan['components'][0]['reason_codes'][0]);
        $this->deliver($shoot, $actor, ['component_ids' => array_column($plan['components'], 'id'), 'mode' => 'external', 'confirmed' => true]);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame('delivered', $item->fresh()->workflow_status);
        $this->assertDatabaseCount('shoot_files', 0);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_external_receipt_ignores_json_key_order_but_preserves_exact_output_identity(): void
    {
        [$shoot, $item, $actor] = $this->fixture('none');
        $item->service->forceFill(['included_components' => [
            ['name' => 'iGuide tour', 'upload_intake_type' => 'none', 'uses_hdr_brackets' => false],
        ]])->save();
        $item->forceFill(['delivery_requirements' => null])->save();
        $shoot->forceFill(['iguide_tour_url' => 'https://youriguide.com/reviewed-identity',
            'iguide_property_id' => 'reviewed-property'])->save();
        $planner = app(DeliveryPlanService::class);
        $selection = $planner->validateSelection($shoot->fresh(), $actor,
            ['component_ids' => [$item->id.':tour'], 'mode' => 'external', 'confirmed' => true]);
        DB::transaction(fn () => $planner->commitSelection($shoot->fresh(), $actor, $selection));
        $receipt = DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->sole();
        $evidence = json_decode($receipt->evidence, true, 512, JSON_THROW_ON_ERROR);
        // Exercise reordered persisted JSON on SQLite as well as native MySQL
        // JSON, which chooses its own object-key order on every write.
        $evidence['external'] = array_reverse($evidence['external'], true);
        $evidence['external']['readiness'] = 'ready';
        $save = function (array $proof) use ($receipt, $planner, $shoot): void {
            DB::table('shoot_delivery_receipts')->where('id', $receipt->id)
                ->update(['evidence' => json_encode($proof, JSON_THROW_ON_ERROR)]);
            $planner->forgetReleasedFiles($shoot);
        };
        $save($evidence);
        $component = $planner->plan($shoot->fresh(), $actor)['components'][0];
        $this->assertTrue($component['resolved']);
        $this->assertSame('delivered', $component['status']);
        $this->assertSame([$item->id], $planner->releasedServiceIds($shoot->fresh()));

        foreach ([['url', 'https://youriguide.com/different-output'], ['property_id', 'different-property'],
            ['service_id', $item->id + 1], ['service_id', (string) $item->id]] as [$field, $value]) {
            $changed = $evidence;
            $changed['external'][$field] = $value;
            $save($changed);
            $this->assertFalse($planner->plan($shoot->fresh(), $actor)['components'][0]['resolved'],
                'A changed external identity must invalidate approval: '.$field);
            $this->assertSame([], $planner->releasedServiceIds($shoot->fresh()));
        }
        $save($evidence);
        $this->assertTrue($planner->plan($shoot->fresh(), $actor)['components'][0]['resolved']);
        DB::table('shoot_delivery_receipts')->where('id', $receipt->id)->update(['revoked_at' => now()]);
        $planner->forgetReleasedFiles($shoot);
        $this->assertFalse($planner->plan($shoot->fresh(), $actor)['components'][0]['resolved']);
    }

    public function test_worker_rechecks_stale_versions_and_actor_permissions_before_any_receipt(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        $file = $this->file($shoot, $item, $actor);
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot, $actor, []);
        $file->forceFill(['content_version' => 2])->save();
        (new FinalizeShootJob($shoot->id, $actor->id, null, null, false, $intent['idempotency_key'], $intent))->handle(app(ShootActivityLogger::class));
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot->fresh(), $manager, []);
        $manager->forceFill(['permission_overrides' => ['deny' => ['shoots-manage']]])->save();
        (new FinalizeShootJob($shoot->id, $manager->id, null, null, false, $intent['idempotency_key'], $intent))->handle(app(ShootActivityLogger::class));
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
    }

    public function test_manual_missing_reason_future_or_suspended_work_is_rejected(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', ['mode' => 'manual_no_content', 'component_ids' => [$item->id.':photo']])->assertUnprocessable();
        $item->forceFill(['scheduled_at' => now()->addDay()])->save();
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', ['mode' => 'manual_no_content', 'component_ids' => [$item->id.':photo'], 'reason' => 'Reviewed exception', 'confirmed' => true])->assertUnprocessable();
        $item->forceFill(['scheduled_at' => now()->subDay()])->save();
        $shoot->forceFill(['status' => 'on_hold', 'workflow_status' => 'editing'])->save();
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', ['mode' => 'manual_no_content', 'component_ids' => [$item->id.':photo'], 'reason' => 'Reviewed exception', 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
    }

    public function test_initial_original_raw_is_preserved(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        $artist = User::factory()->photographer()->create();
        $item->forceFill(['photographer_id' => $artist->id])->save();
        $raw = $this->file($shoot, $item, $actor, ['workflow_stage' => 'todo', 'media_type' => 'raw', 'created_at' => now()->subHour()]);
        $shoot->forceFill(['status' => 'active', 'workflow_status' => 'active', 'active_work_started_at' => now(), 'active_work_is_reopened' => false])->save();
        app(ShootActiveWorkLifecycle::class)->reconcile($shoot);
        $this->assertTrue(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot->fresh(), $artist));
        $this->assertSame('editing', $shoot->fresh()->status);
        $this->assertNull($shoot->fresh()->admin_verified_at);
        $this->assertNotNull($raw->fresh());
    }

    public function test_known_unready_provider_cannot_be_externally_finalized_from_its_url(): void
    {
        [$shoot, $item, $actor] = $this->fixture('none');
        $item->service->forceFill(['included_components' => [['name' => 'iGuide tour', 'upload_intake_type' => 'none', 'uses_hdr_brackets' => false]]])->save();
        $item->forceFill(['delivery_requirements' => null])->save();
        $url = 'https://youriguide.com/not-ready-property';
        $shoot->forceFill(['iguide_tour_url' => $url, 'iguide_property_id' => 'ig-not-ready', 'iguide_data' => ['delivery_readiness' => ['state' => 'not_ready', 'property_id' => 'ig-not-ready', 'tour_url' => $url]]])->save();
        $component = app(DeliveryPlanService::class)->plan($shoot, $actor)['components'][0];
        $this->assertSame('pending', $component['status']);
        $this->assertContains('provider_not_ready', $component['reason_codes']);
        $this->assertNotContains('external', $component['methods']);
        $this->assertContains('manual_no_content', $component['methods']);
    }

    public function test_reverting_a_bundle_link_does_not_let_a_fresh_lane_stamp_complete_video(): void
    {
        [$shoot, $item, $actor] = $this->fixture();
        $shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'delivered', 'admin_verified_at' => now()->subDay(), 'completed_at' => now()->subDay(), 'tour_links' => ['video_link' => 'https://video.test/old']])->save();
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $this->travel(1)->minutes();
        $this->file($shoot, $item, $actor);
        $shoot->forceFill(['tour_links' => ['video_link' => 'https://video.test/new']])->save();
        app(ActiveVideoLinkOutput::class)->recordChangedLinks($shoot->fresh());
        $item->forceFill(['video_editing_completed_at' => now()])->save();
        $shoot->forceFill(['tour_links' => ['video_link' => 'https://video.test/old']])->save();
        app(ActiveVideoLinkOutput::class)->recordChangedLinks($shoot->fresh());
        $this->deliver($shoot, $actor);
        $video = collect(app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['components'])->firstWhere('kind', 'video');
        $this->assertSame('pending', $video['status']);
        $this->assertNotSame('delivered', $item->fresh()->delivery_status);
        $this->assertSame('active', $shoot->fresh()->status);
    }

    public function test_unscheduled_reopened_work_today_precedes_another_future_appointment(): void
    {
        [$shoot, $item] = $this->fixture('photo');
        $item->forceFill(['scheduled_at' => now()->addDays(5)])->save();
        $shoot->serviceItems()->create(['service_id' => Service::factory()->videoIntake()->create()->id, 'quantity' => 1, 'price' => 50,
            'workflow_status' => 'pending', 'delivery_status' => 'not_started', 'is_deliverable' => true]);
        $shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'delivered', 'admin_verified_at' => now()->subDay(), 'completed_at' => now()->subDay()])->save();
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $this->assertSame('2026-11-05', $shoot->active_work_date);
        $this->assertNull($shoot->active_work_time);
        $this->assertSame('2026-11-04', $shoot->scheduled_date->toDateString());
    }

    public function test_duplicate_delivery_operation_does_not_duplicate_receipts_or_history(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        $this->file($shoot, $item, $actor);
        $intent = app(DeliveryPlanService::class)->validateSelection($shoot, $actor, []);
        $job = new FinalizeShootJob($shoot->id, $actor->id, null, null, false, $intent['idempotency_key'], $intent);
        $job->handle(app(ShootActivityLogger::class));
        $first = $shoot->fresh()->admin_verified_at->toIso8601String();
        $this->travel(1)->hours();
        $job->handle(app(ShootActivityLogger::class));
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
        $this->assertSame($first, $shoot->fresh()->admin_verified_at->toIso8601String());
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', ['idempotency_key' => $intent['idempotency_key'],
            'component_ids' => $intent['component_ids'], 'mode' => 'manual_no_content', 'reason' => 'Different intent', 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
    }

    public function test_deliberate_legacy_reopening_preserves_old_release_and_requires_fresh_component_output(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        $oldAt = now()->subDay();
        $old = $this->file($shoot, $item, $actor, ['workflow_stage' => 'verified', 'verified_at' => $oldAt,
            'created_at' => now()->subDays(2), 'updated_at' => $oldAt]);
        $item->forceFill(['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivered_at' => $oldAt,
            'delivery_requirements' => [['key' => 'legacy', 'kind' => 'legacy', 'label' => 'Historical delivery']]])->save();
        $shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'delivered', 'admin_verified_at' => $oldAt, 'completed_at' => $oldAt])->save();
        $this->travel(1)->minutes();
        $item->forceFill(['workflow_status' => 'in_progress', 'delivery_status' => 'not_started'])->save();
        $this->assertSame('active', $shoot->fresh()->status);
        $this->assertSame('photo', $item->fresh()->delivery_requirements[0]['kind']);
        $this->assertStringStartsWith('line:', $item->fresh()->delivery_requirements[0]['execution_key']);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($old->fresh()));
        $this->assertSame([], app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['ready_component_ids']);
        $this->travel(1)->minutes();
        $this->file($shoot, $item, $actor);
        $this->deliver($shoot, $actor);
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertEquals($oldAt, $shoot->fresh()->admin_verified_at);
        $this->assertEquals($oldAt, $old->fresh()->verified_at);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_cancelling_last_original_obligation_after_partial_delivery_completes_once(): void
    {
        [$shoot, $photo, $actor] = $this->fixture('photo');
        $video = $shoot->serviceItems()->create(['service_id' => Service::factory()->videoIntake()->create()->id,
            'quantity' => 1, 'price' => 100, 'workflow_status' => 'pending', 'delivery_status' => 'not_started']);
        $this->file($shoot, $photo, $actor);
        $this->deliver($shoot, $actor);
        $this->assertSame('editing', $shoot->fresh()->status);
        $this->assertNull($shoot->fresh()->admin_verified_at);
        $this->travel(1)->minutes();
        $video->forceFill(['workflow_status' => 'cancelled', 'delivery_status' => 'cancelled', 'cancelled_at' => now()])->save();
        $first = $shoot->fresh()->admin_verified_at;
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertNotNull($first);
        $this->assertSame('delivered', $shoot->fresh()->delivery_status);
        $this->travel(1)->hours();
        $video->forceFill(['price' => 100])->save();
        $this->assertEquals($first, $shoot->fresh()->admin_verified_at);
        $this->assertSame('2026-11-04', $shoot->fresh()->scheduled_date->toDateString());
    }

    public function test_ledger_migration_can_resume_and_repeat_without_rewriting_new_snapshots_or_receipts(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        $this->file($shoot, $item, $actor);
        $this->deliver($shoot, $actor);
        $requirements = $item->fresh()->getRawOriginal('delivery_requirements');
        $receipts = DB::table('shoot_delivery_receipts')->get()->toJson();
        $shoot->forceFill(['active_work_is_reopened' => false])->save();
        $migration = require base_path('database/migrations/2026_11_05_190000_add_component_delivery_ledger.php');
        $migration->up(); $migration->up();
        $this->assertSame($requirements, $item->fresh()->getRawOriginal('delivery_requirements'));
        $this->assertSame($receipts, DB::table('shoot_delivery_receipts')->get()->toJson());
        $this->assertFalse($shoot->fresh()->active_work_is_reopened);
    }

    public function test_no_editor_video_can_use_an_explicitly_reviewed_current_external_link(): void
    {
        [$shoot, $item, $actor] = $this->fixture('video', false);
        $shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'delivered', 'admin_verified_at' => now()->subDay(), 'completed_at' => now()->subDay(),
            'tour_links' => ['video_link' => 'https://video.test/prior']])->save();
        app(ShootActiveWorkLifecycle::class)->begin($shoot);
        $this->travel(1)->minutes();
        $shoot->forceFill(['tour_links' => ['video_link' => 'https://video.test/current']])->save();
        app(ActiveVideoLinkOutput::class)->recordChangedLinks($shoot->fresh());
        $component = app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['components'][0];
        $this->assertContains('external', $component['methods']);
        $this->deliver($shoot, $actor, ['component_ids' => [$component['id']], 'mode' => 'external', 'confirmed' => true]);
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertNull($item->fresh()->editing_completed_at);
    }

    public function test_reviewed_publication_approves_only_its_exact_version_and_keeps_other_pending_content_private(): void
    {
        [$shoot, $item, $actor] = $this->fixture();
        $a = $this->file($shoot, $item, $actor);
        $b = $this->file($shoot, $item, $actor, ['filename' => 'other.jpg', 'stored_filename' => 'other.jpg', 'path' => 'shoots/'.$shoot->id.'/other.jpg']);
        $this->deliver($shoot, $actor);
        $firstAt = $a->fresh()->verified_at;
        $unreviewed = $this->file($shoot, $item, $actor, ['filename' => 'pending.jpg', 'stored_filename' => 'pending.jpg', 'path' => 'shoots/'.$shoot->id.'/pending.jpg']);
        $before = clone $a->fresh();
        $a->forceFill(['content_version' => 2])->save();
        $id = app(DeliveryPlanService::class)->recordReviewedVersion($shoot, $actor, $a->fresh(), 'media-version:review-a', $before);
        $this->assertNotNull($id);
        $proof = json_decode(DB::table('shoot_delivery_receipts')->where('id', $id)->value('evidence'), true);
        $this->assertSame([['id' => $a->id, 'version' => 2]], $proof['files']);
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($a->fresh()));
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($b->fresh()));
        $this->assertFalse(app(DeliveryPlanService::class)->releasedFile($unreviewed->fresh()));
        $this->assertEquals($firstAt, $a->fresh()->verified_at);
        $video = collect(app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['components'])->firstWhere('kind', 'video');
        $this->assertSame('pending', $video['status']);
        $this->assertSame('editing', $shoot->fresh()->status);
        $beforeB = clone $b->fresh();
        $b->forceFill(['content_version' => 2])->save();
        $this->assertNotNull(app(DeliveryPlanService::class)->recordReviewedVersion($shoot, $actor, $b->fresh(), 'media-version:review-b', $beforeB));
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($a->fresh()));
        $this->assertTrue(app(DeliveryPlanService::class)->releasedFile($b->fresh()));
    }

    public function test_a_prior_execution_approval_cannot_be_carried_into_deliberately_reopened_work(): void
    {
        [$shoot, $item, $actor] = $this->fixture('photo');
        $file = $this->file($shoot, $item, $actor);
        $this->deliver($shoot, $actor);
        $before = clone $file->fresh();
        $this->travel(1)->minutes();
        $item->refresh()->forceFill(['workflow_status' => 'in_progress', 'delivery_status' => 'not_started'])->save();
        $file->forceFill(['content_version' => 2])->save();
        $this->assertNull(app(DeliveryPlanService::class)->recordReviewedVersion($shoot->fresh(), $actor, $file->fresh(), 'media-version:wrong-cycle', $before));
        $this->assertDatabaseCount('shoot_delivery_receipts', 1);
        $this->assertFalse(app(DeliveryPlanService::class)->releasedFile($file->fresh()));
        $this->assertSame('active', $shoot->fresh()->status);
    }
}
