<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, ShootFileVersion, ShootService, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{DeliveryPlanService, ShootActiveWorkLifecycle};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaServiceAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->shoot = Shoot::factory()->create(['status' => 'editing', 'workflow_status' => 'editing',
            'total_quote' => 100, 'payment_status' => 'paid']);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function url(): string { return '/api/shoots/'.$this->shoot->id.'/media/assign-service'; }

    private function item(array $attributes = [], ?Service $catalog = null): ShootService
    {
        return $this->shoot->serviceItems()->create($attributes + ['service_id' => ($catalog ?? Service::factory()->unbracketedPhoto()->create())->id,
            'price' => 100, 'quantity' => 1, 'is_deliverable' => true, 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
    }

    private function file(?ShootService $owner = null, array $attributes = []): ShootFile
    {
        return ShootFile::create($attributes + ['shoot_id' => $this->shoot->id, 'shoot_service_id' => $owner?->id,
            'filename' => 'photo.jpg', 'stored_filename' => 'original.jpg', 'path' => 'original/immutable.jpg',
            'file_type' => 'image/jpeg', 'file_size' => 123, 'workflow_stage' => 'completed', 'media_type' => 'edited',
            'scan_status' => 'clean', 'content_version' => 1, 'uploaded_by' => $this->admin->id]);
    }

    private function intent(ShootFile $file, ShootService $target, array $extra = []): array
    {
        return $extra + ['dry_run' => false, 'file_ids' => [$file->id], 'shoot_service_id' => $target->id,
            'expected_files' => [['id' => $file->id, 'shoot_service_id' => $file->shoot_service_id, 'content_version' => $file->content_version ?: 1]],
            'expected_target_unit_id' => $target->shoot_unit_id, 'idempotency_key' => 'assign-'.str()->uuid()];
    }

    public function test_discovery_uses_effective_privileged_permission_and_exact_booked_targets(): void
    {
        $target = $this->item();
        foreach (['admin', 'editing_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson($this->url(), ['dry_run' => true])->assertOk()
                ->assertJsonPath('capabilities.can_assign_service', true)->assertJsonPath('targets.0.id', $target->id);
        }
        foreach (['client', 'photographer', 'editor', 'salesRep'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson($this->url(), ['dry_run' => true])->assertOk()->assertJsonPath('capabilities.can_assign_service', false)->assertJsonCount(0, 'targets');
            $this->postJson($this->url(), ['dry_run' => false])->assertForbidden();
        }
        foreach (['shoots-manage', 'media-upload'] as $denied) {
            Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager', 'permission_overrides' => ['deny' => [$denied]]]));
            $this->postJson($this->url(), ['dry_run' => true])->assertOk()->assertJsonPath('capabilities.can_assign_service', false);
        }
    }

    public function test_unassigned_move_is_atomic_preserves_originals_and_is_idempotent_without_delivery(): void
    {
        $target = $this->item(); $file = $this->file();
        $before = $file->only(['path', 'filename', 'stored_filename', 'file_size', 'created_at', 'updated_at', 'workflow_stage', 'scan_status', 'verified_at', 'content_version']);
        $intent = $this->intent($file, $target); Queue::fake();
        $this->postJson($this->url(), $intent)->assertOk()->assertJsonPath('updated_count', 1)->assertJsonPath('files.0.content_version', 1);
        $this->assertSame($target->id, $file->fresh()->shoot_service_id);
        $this->assertEquals($before, $file->fresh()->only(array_keys($before)));
        $this->assertSame('not_started', $target->fresh()->delivery_status);
        $this->assertSame('editing', $this->shoot->fresh()->status);
        $this->postJson($this->url(), $intent)->assertOk()->assertJsonPath('already_applied', true)->assertJsonPath('updated_count', 0);
        $this->assertSame(1, $this->shoot->workflowLogs()->where('action', 'media_service_assigned')->count());
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        Queue::assertNothingPushed();
    }

    public function test_stale_version_and_reused_key_with_different_choices_are_rejected(): void
    {
        $target = $this->item(); $other = $this->item(); $file = $this->file();
        $intent = $this->intent($file, $target);
        $bad = $intent; $bad['expected_files'][0]['content_version'] = 2;
        $this->postJson($this->url(), $bad)->assertConflict();
        $this->assertNull($file->fresh()->shoot_service_id);
        $this->postJson($this->url(), $intent)->assertOk();
        $intent['shoot_service_id'] = $other->id;
        $this->postJson($this->url(), $intent)->assertConflict();
        $this->assertSame($target->id, $file->fresh()->shoot_service_id);
    }

    public function test_exact_file_set_cannot_mix_another_shoot(): void
    {
        $target = $this->item(); $file = $this->file();
        $foreign = $this->file(null, ['shoot_id' => Shoot::factory()->create()->id]);
        $intent = $this->intent($file, $target); $intent['file_ids'][] = $foreign->id;
        $this->postJson($this->url(), $intent)->assertUnprocessable();
        $this->assertNull($file->fresh()->shoot_service_id);
        $this->assertNull($foreign->fresh()->shoot_service_id);
    }

    public function test_wrong_content_cancelled_and_financial_targets_are_disabled(): void
    {
        $video = $this->item([], Service::factory()->videoIntake()->create());
        $cancelled = $this->item(['workflow_status' => 'cancelled', 'delivery_status' => 'cancelled']);
        $financial = $this->item([], Service::factory()->create(['financial_only' => true]));
        $file = $this->file();
        foreach ([$video, $cancelled, $financial] as $target) $this->postJson($this->url(), $this->intent($file, $target))->assertUnprocessable();
        $this->assertNull($file->fresh()->shoot_service_id);
    }

    public function test_inflight_version_and_incomplete_hdr_groups_block_reassignment(): void
    {
        $target = $this->item(); $file = $this->file();
        ShootFileVersion::create(['shoot_id' => $this->shoot->id, 'source_file_id' => $file->id, 'status' => 'queued',
            'expected_version' => 1, 'request_key' => 'inflight', 'created_by' => $this->admin->id]);
        $this->postJson($this->url(), $this->intent($file, $target, ['dry_run' => true]))->assertOk()->assertJsonPath('can_apply', false)
            ->assertJsonFragment(['code' => 'inflight_work']);
        ShootFileVersion::query()->delete();
        $raw = $this->file(null, ['workflow_stage' => 'todo', 'media_type' => 'raw', 'bracket_group' => 1, 'sequence' => 1]);
        $this->file(null, ['workflow_stage' => 'todo', 'media_type' => 'raw', 'bracket_group' => 1, 'sequence' => 2]);
        $this->postJson($this->url(), $this->intent($raw, $target, ['dry_run' => true]))->assertOk()->assertJsonPath('can_apply', false)
            ->assertJsonFragment(['code' => 'incomplete_hdr_group']);
    }

    public function test_original_legacy_historical_move_requires_reason_confirmation_and_fresh_receipts(): void
    {
        $this->shoot->forceFill(['status' => 'delivered', 'workflow_status' => 'delivered', 'admin_verified_at' => now()->subDay()])->save();
        $source = $this->item(['workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $target = $this->item(['workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        foreach ([$source, $target] as $item) DB::table('shoot_service')->where('id', $item->id)->update(['delivery_requirements' => json_encode([['key' => 'legacy', 'kind' => 'legacy', 'label' => 'Historical delivery']])]);
        $file = $this->file($source, ['workflow_stage' => 'verified', 'verified_at' => now()->subHour()]);
        $before = $file->only(['created_at', 'verified_at', 'workflow_stage', 'content_version', 'path']);
        $intent = $this->intent($file, $target, ['confirm_historical' => true, 'reason' => 'Correct reviewed original service ownership']);
        $preview = $intent; $preview['dry_run'] = true;
        $response = $this->postJson($this->url(), $preview)->assertOk()->assertJsonPath('can_apply', true);
        $intent['expected_receipt_revision'] = $response->json('receipt_revision');
        $this->postJson($this->url(), $intent)->assertOk()->assertJsonPath('updated_count', 1);
        $this->assertSame($target->id, $file->fresh()->shoot_service_id);
        $this->assertEquals($before, $file->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
    }

    public function test_unapproved_completed_upload_can_leave_a_managed_delivered_line_without_freshening_new_work(): void
    {
        $source = $this->item(['workflow_status' => 'ready', 'delivery_status' => 'ready', 'scheduled_at' => now()->subDay()]);
        $approved = $this->file($source);
        $planner = app(DeliveryPlanService::class);
        $delivery = $planner->validateSelection($this->shoot->fresh(), $this->admin, []);
        (new FinalizeShootJob($this->shoot->id, $this->admin->id, null, null, false, $delivery['idempotency_key'], $delivery))
            ->handle(app(ShootActivityLogger::class));
        $this->assertSame('delivered', $source->fresh()->delivery_status);
        $this->assertTrue($planner->releasedFile($approved->fresh()));
        $firstDeliveredAt = $this->shoot->fresh()->admin_verified_at;
        $receipts = DB::table('shoot_delivery_receipts')->get()->toJson();
        $this->travel(1)->minutes();
        $unapproved = $this->file($source, ['filename' => 'unapproved.jpg']);
        $this->travel(1)->minutes();
        $target = $this->item(['scheduled_at' => now()->subDay(), 'editing_completed_at' => $firstDeliveredAt, 'delivered_at' => $firstDeliveredAt]);
        app(ShootActiveWorkLifecycle::class)->begin($this->shoot->fresh());
        $before = $unapproved->only(['path', 'filename', 'stored_filename', 'file_size', 'created_at', 'updated_at',
            'workflow_stage', 'scan_status', 'verified_at', 'content_version']);
        $this->assertSame('active', $this->shoot->fresh()->status);
        $intent = $this->intent($unapproved, $target, ['reason' => 'This unreviewed upload belongs to the new photo service']);
        $this->postJson($this->url(), ['dry_run' => true] + $intent)->assertOk()
            ->assertJsonPath('can_apply', true)->assertJsonPath('requires_historical_confirmation', false);
        Queue::fake();
        $this->postJson($this->url(), $intent)->assertOk()->assertJsonPath('updated_count', 1);
        $this->assertSame($target->id, $unapproved->fresh()->shoot_service_id);
        $this->assertEquals($before, $unapproved->fresh()->only(array_keys($before)));
        $this->assertSame($receipts, DB::table('shoot_delivery_receipts')->get()->toJson());
        $this->assertTrue($planner->releasedFile($approved->fresh()));
        $this->assertFalse($planner->releasedFile($unapproved->fresh()));
        $this->assertSame('not_started', $target->fresh()->delivery_status);
        $this->assertFalse($planner->itemResolved($this->shoot->fresh(), $target->fresh()));
        $this->assertSame([], $planner->plan($this->shoot->fresh(), $this->admin)['ready_component_ids']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/finalize', ['component_ids' => [$target->id.':photo'], 'mode' => 'media'])->assertUnprocessable();
        $this->assertSame('active', $this->shoot->fresh()->status);
        $this->assertEquals($firstDeliveredAt, $this->shoot->fresh()->admin_verified_at);
        Queue::assertNothingPushed();
    }

    public function test_legacy_aggregate_completed_upload_still_requires_guarded_historical_review(): void
    {
        $source = $this->item(['workflow_status' => 'delivered', 'delivery_status' => 'delivered']);
        $target = $this->item();
        $file = $this->file($source);
        $intent = $this->intent($file, $target, ['dry_run' => true, 'reason' => 'Review legacy ownership']);
        $this->postJson($this->url(), $intent)->assertOk()->assertJsonPath('can_apply', false)
            ->assertJsonPath('requires_historical_confirmation', true)->assertJsonFragment(['code' => 'historical_confirmation_required']);
        $this->postJson($this->url(), ['confirm_historical' => true] + $intent)->assertOk()
            ->assertJsonPath('can_apply', false)->assertJsonFragment(['code' => 'needs_guarded_repair']);
        $this->assertSame($source->id, $file->fresh()->shoot_service_id);
    }

    public function test_receipt_referenced_completed_file_is_still_historical_even_when_not_currently_verified(): void
    {
        $source = $this->item(['workflow_status' => 'ready', 'delivery_status' => 'ready']);
        $file = $this->file($source);
        $planner = app(DeliveryPlanService::class);
        $delivery = $planner->validateSelection($this->shoot->fresh(), $this->admin, []);
        (new FinalizeShootJob($this->shoot->id, $this->admin->id, null, null, false, $delivery['idempotency_key'], $delivery))
            ->handle(app(ShootActivityLogger::class));
        DB::table('shoot_files')->where('id', $file->id)->update(['workflow_stage' => 'completed']);
        $target = $this->item();
        $this->postJson($this->url(), $this->intent($file->fresh(), $target, ['dry_run' => true, 'reason' => 'Review approved ownership']))
            ->assertOk()->assertJsonPath('can_apply', false)->assertJsonPath('requires_historical_confirmation', true)
            ->assertJsonFragment(['code' => 'historical_confirmation_required']);
        $this->assertSame($source->id, $file->fresh()->shoot_service_id);
    }
}
