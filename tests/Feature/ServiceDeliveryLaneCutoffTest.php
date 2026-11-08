<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, ShootFile, ShootFileVersion, ShootService, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\{DeliveryPlanService, ServiceDeliveryEligibility};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceDeliveryLaneCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(15, 0));
        Http::preventStrayRequests();
        Mail::fake(); Notification::fake(); Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    #[DataProvider('cyclesAndRawLanes')]
    public function test_later_raw_invalidates_only_its_component_and_preserves_other_approved_output(bool $reopened, string $lane, string $rawType): void
    {
        [$shoot, $item, $actor] = $this->fixture($reopened);
        $outputs = [];
        foreach (['photo', 'video', 'floorplan'] as $kind) {
            $outputs[$kind] = $this->file($shoot, $item, $actor, $kind);
            $this->receipt($shoot, $item, $actor, $kind, $outputs[$kind]);
        }
        $this->submittedRaw($shoot, $item, $actor, $lane, $rawType);

        $plan = app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor);
        $matching = collect($plan['components'])->firstWhere('kind', $lane);
        $this->assertFalse($matching['resolved']);
        $this->assertSame(0, $matching['output_count']);
        $this->assertContains('outdated_approval', $matching['reason_codes']);
        foreach (array_diff(['photo', 'video', 'floorplan'], [$lane]) as $kind) {
            $component = collect($plan['components'])->firstWhere('kind', $kind);
            $this->assertTrue($component['resolved'], $kind.' approval must survive another component raw handoff.');
            $this->assertSame('delivered', $component['status']);
            $this->assertSame([$outputs[$kind]->id], array_values($component['file_ids']));
            $this->assertNotContains('outdated_approval', $component['reason_codes']);
        }
        $this->assertSame(3, DB::table('shoot_delivery_receipts')->whereNull('revoked_at')->count());
        foreach ($outputs as $file) {
            $this->assertSame('verified', $file->fresh()->workflow_stage);
            $this->assertSame(1, (int) $file->fresh()->content_version);
        }
    }

    public static function cyclesAndRawLanes(): array
    {
        return [
            'initial partial, photo raw' => [false, 'photo', 'raw'],
            'initial partial, video raw' => [false, 'video', 'video'],
            'initial partial, video MIME despite raw classification' => [false, 'video', 'raw'],
            'reopened, photo raw' => [true, 'photo', 'raw'],
            'reopened, video MIME despite raw classification' => [true, 'video', 'raw'],
        ];
    }

    public function test_a_pending_floorplan_with_submission_metadata_does_not_restart_photo_or_video_work(): void
    {
        [$shoot, $item, $actor] = $this->fixture(true);
        $outputs = [];
        foreach (['photo', 'video'] as $kind) {
            $outputs[$kind] = $this->file($shoot, $item, $actor, $kind);
            $this->receipt($shoot, $item, $actor, $kind, $outputs[$kind]);
        }
        $this->submittedRaw($shoot, $item, $actor, 'floorplan', 'floorplan');

        $plan = app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor);
        foreach (['photo', 'video'] as $kind) {
            $component = collect($plan['components'])->firstWhere('kind', $kind);
            $this->assertTrue($component['resolved'], 'A provider floorplan is not a '.$kind.' raw handoff.');
            $this->assertSame([$outputs[$kind]->id], array_values($component['file_ids']));
        }
    }

    #[DataProvider('legacyOutputLanes')]
    public function test_legacy_finished_files_with_unknown_mime_keep_their_existing_lane_freshness(string $kind, bool $eligible): void
    {
        [$shoot, $item, $actor] = $this->fixture(true);
        $output = $this->file($shoot, $item, $actor, $kind, ['mime_type' => 'application/octet-stream',
            'file_type' => 'application/octet-stream']);
        $this->submittedRaw($shoot, $item, $actor, 'photo', 'raw');

        $current = app(ServiceDeliveryEligibility::class)->currentCycleFiles($shoot->fresh(), $shoot->files()->get());
        $this->assertSame($eligible, $current->contains('id', $output->id));
    }

    public static function legacyOutputLanes(): array
    {
        return ['legacy photo cannot evade a photo handoff' => ['photo', false],
            'legacy video survives a photo handoff' => ['video', true]];
    }

    #[DataProvider('unitScopes')]
    public function test_raw_on_another_booked_line_stays_isolated_within_or_across_units(bool $sameUnit): void
    {
        [$shoot, $item, $actor] = $this->fixture(true);
        $unit = $shoot->units()->create(['client_key' => 'one', 'label' => 'Unit one', 'kind' => 'unit']);
        $otherUnit = $sameUnit ? $unit : $shoot->units()->create(['client_key' => 'two', 'label' => 'Unit two', 'kind' => 'unit']);
        $item->forceFill(['shoot_unit_id' => $unit->id])->saveQuietly();
        $otherService = $item->service;
        // The schema allows each catalog service only once per unit. Use a
        // second catalog service within one unit, and reuse it across units.
        if ($sameUnit) {
            $otherService = $otherService->replicate();
            $otherService->saveQuietly();
        }
        $other = $this->item($shoot, $otherService, true, $otherUnit->id);
        $photo = $this->file($shoot, $item, $actor, 'photo');
        $this->receipt($shoot, $item, $actor, 'photo', $photo);
        $this->submittedRaw($shoot, $other, $actor, 'photo', 'raw');

        $component = collect(app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['components'])
            ->firstWhere('id', $item->id.':photo');
        $this->assertTrue($component['resolved']);
        $this->assertSame([$photo->id], array_values($component['file_ids']));
    }

    public static function unitScopes(): array
    {
        return ['different catalog line in same unit' => [true], 'same catalog service in different unit' => [false]];
    }

    #[DataProvider('unmatchedRawReceipts')]
    public function test_raw_from_a_different_execution_or_content_version_does_not_change_current_approval(string $mismatch): void
    {
        [$shoot, $item, $actor] = $this->fixture(true);
        $photo = $this->file($shoot, $item, $actor, 'photo');
        $this->receipt($shoot, $item, $actor, 'photo', $photo);
        $raw = $this->submittedRaw($shoot, $item, $actor, 'photo', 'raw', $mismatch === 'execution' ? ['execution_key' => 'different-execution'] : []);
        if ($mismatch === 'version') $raw->forceFill(['content_version' => 2])->saveQuietly();

        $component = collect(app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['components'])->firstWhere('kind', 'photo');
        $this->assertTrue($component['resolved']);
        $this->assertSame([$photo->id], array_values($component['file_ids']));
    }

    public static function unmatchedRawReceipts(): array
    {
        return ['different execution' => ['execution'], 'different content version' => ['version']];
    }

    public function test_new_same_lane_replacement_is_eligible_but_does_not_reuse_the_old_version_approval(): void
    {
        [$shoot, $item, $actor] = $this->fixture(true);
        $photo = $this->file($shoot, $item, $actor, 'photo');
        $this->receipt($shoot, $item, $actor, 'photo', $photo);
        $this->submittedRaw($shoot, $item, $actor, 'photo', 'raw');
        $photo->forceFill(['content_version' => 2])->saveQuietly();
        ShootFileVersion::create(['shoot_id' => $shoot->id, 'source_file_id' => $photo->id,
            'target_file_id' => $photo->id, 'published_file_id' => $photo->id, 'expected_version' => 1,
            'version' => 2, 'request_key' => 'replacement-after-photo-handoff', 'status' => 'published',
            'created_by' => $actor->id, 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);

        $component = collect(app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor)['components'])->firstWhere('kind', 'photo');
        $this->assertFalse($component['resolved']);
        $this->assertSame('ready', $component['status']);
        $this->assertSame([$photo->id], array_values($component['file_ids']));
        $this->assertContains('outdated_approval', $component['reason_codes']);
    }

    public function test_component_scoping_does_not_admit_files_from_before_the_reopened_execution(): void
    {
        [$shoot, $item, $actor] = $this->fixture(true);
        foreach (['photo', 'video', 'floorplan'] as $kind) {
            $this->file($shoot, $item, $actor, $kind, ['created_at' => now()->subHours(7)]);
        }
        $this->assertSame([], app(ServiceDeliveryEligibility::class)->currentCycleFiles($shoot->fresh(), $shoot->files()->get())->modelKeys());
    }

    public function test_authorized_finalization_completes_a_bundle_after_later_video_raw_without_reapproving_its_photos(): void
    {
        [$shoot, $item, $actor] = $this->fixture(false);
        $item->forceFill(['delivery_requirements' => [...$item->delivery_requirements,
            ['key' => 'tour', 'kind' => 'tour', 'label' => 'Zillow tour', 'provider' => 'zillow']]])->saveQuietly();
        $shoot->forceFill(['tour_links' => ['zillow_3d' => 'https://www.zillow.com/view-3d-home/reviewed-bundle']])->saveQuietly();
        foreach (['photo', 'floorplan'] as $kind) {
            $this->receipt($shoot, $item, $actor, $kind, $this->file($shoot, $item, $actor, $kind));
        }
        $policy = app(DeliveryPlanService::class);
        $tour = collect($policy->plan($shoot->fresh(), $actor)['components'])->firstWhere('kind', 'tour');
        $this->assertNotNull($tour['external_evidence']);
        DB::table('shoot_delivery_receipts')->insert(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
            'component_key' => 'tour', 'execution_key' => 'initial', 'method' => 'external',
            'evidence' => json_encode(['external' => $tour['external_evidence'], 'confirmed' => true]),
            'actor_id' => $actor->id, 'plan_revision' => str_repeat('b', 64),
            'operation_key' => 'reviewed-zillow-tour', 'delivered_at' => now()->subHours(4)]);
        $raw = $this->submittedRaw($shoot, $item, $actor, 'video', 'raw');
        $video = $this->file($shoot, $item, $actor, 'video', ['workflow_stage' => 'completed',
            'created_at' => now()->subHour(), 'verified_at' => null]);
        $plan = $policy->plan($shoot->fresh(), $actor);
        $this->assertTrue(collect($plan['components'])->firstWhere('kind', 'photo')['resolved']);
        $this->assertSame([$item->id.':video'], $plan['ready_component_ids']);

        Sanctum::actingAs(User::factory()->create(['role' => 'photographer']));
        $this->postJson('/api/shoots/'.$shoot->id.'/finalize', ['component_ids' => [$item->id.':video'], 'mode' => 'media'])->assertForbidden();
        $this->assertDatabaseCount('shoot_delivery_receipts', 3);
        Queue::assertNotPushed(FinalizeShootJob::class);

        Sanctum::actingAs($actor);
        $this->postJson('/api/shoots/'.$shoot->id.'/finalize', ['component_ids' => [$item->id.':video'],
            'mode' => 'media', 'plan_revision' => $plan['revision']])->assertAccepted();
        $job = Queue::pushed(FinalizeShootJob::class)->first(fn ($job) => $job->shootId === $shoot->id);
        $this->assertNotNull($job);
        $job->handle(app(ShootActivityLogger::class));

        $finalPlan = $policy->plan($shoot->fresh(), $actor);
        $this->assertCount(4, $finalPlan['components']);
        $this->assertTrue(collect($finalPlan['components'])->every(fn ($component) => $component['resolved']));
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame('delivered', $shoot->fresh()->delivery_status);
        $this->assertSame('delivered', $item->fresh()->workflow_status);
        $this->assertSame('delivered', $item->fresh()->delivery_status);
        $this->assertSame('verified', $video->fresh()->workflow_stage);
        $this->assertSame('todo', $raw->fresh()->workflow_stage);
        $this->assertDatabaseCount('shoot_delivery_receipts', 4);
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
    }

    private function fixture(bool $reopened): array
    {
        return Model::withoutEvents(function () use ($reopened): array {
            $actor = User::factory()->create(['role' => 'superadmin']);
            $shoot = Shoot::factory()->create(['status' => 'active', 'workflow_status' => 'active',
                'delivery_status' => 'partially_delivered', 'timezone' => 'UTC', 'payment_status' => 'unpaid',
                'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(),
                'created_at' => now()->subDays(2), 'active_work_started_at' => now()->subHours($reopened ? 6 : 4),
                'active_work_completed_at' => null, 'active_work_is_reopened' => $reopened,
                'admin_verified_at' => $reopened ? now()->subDay() : null, 'completed_at' => $reopened ? now()->subDay() : null]);
            $service = Service::factory()->create(['name' => 'Mixed photo and video package', 'requires_editing' => true,
                'upload_intake_type' => 'photo_video']);
            return [$shoot, $this->item($shoot, $service, $reopened), $actor];
        });
    }

    private function item(Shoot $shoot, Service $service, bool $reopened, ?int $unitId = null): ShootService
    {
        $item = new ShootService;
        $item->forceFill(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'shoot_unit_id' => $unitId,
            'is_deliverable' => true, 'workflow_status' => 'ready', 'delivery_status' => 'ready',
            'price' => 250, 'quantity' => 1, 'scheduled_at' => now()->subDay()])->saveQuietly();
        $item->forceFill(['delivery_requirements' => array_map(fn ($kind) => ['key' => $kind, 'kind' => $kind, 'label' => ucfirst($kind)]
            + ($reopened ? ['execution_key' => 'line:'.$item->id.':current', 'execution_started_at' => now()->subHours(6)->toIso8601String()] : []),
            ['photo', 'video', 'floorplan'])])->saveQuietly();
        return $item;
    }

    private function file(Shoot $shoot, ShootService $item, User $actor, string $kind, array $attributes = []): ShootFile
    {
        $mime = match ($kind) { 'video' => 'video/mp4', 'floorplan' => 'application/pdf', default => 'image/jpeg' };
        $extension = match ($kind) { 'video' => '.mp4', 'floorplan' => '.pdf', default => '.jpg' };
        $filename = $kind.'-'.$item->id.'-'.(ShootFile::count() + 1).$extension;
        $file = new ShootFile;
        $file->forceFill($attributes + ['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
            'filename' => $filename, 'stored_filename' => $filename, 'path' => 'shoots/'.$shoot->id.'/'.$filename,
            'file_size' => 100, 'file_type' => $mime, 'mime_type' => $mime,
            'media_type' => $kind === 'photo' ? 'edited' : $kind, 'workflow_stage' => 'verified',
            'scan_status' => 'clean', 'is_hidden' => false, 'is_extra' => false, 'content_version' => 1,
            'uploaded_by' => $actor->id, 'created_at' => now()->subHours(5), 'verified_at' => now()->subHours(4)])->saveQuietly();
        return $file;
    }

    private function submittedRaw(Shoot $shoot, ShootService $item, User $actor, string $lane, string $rawType, array $receiptOverrides = []): ShootFile
    {
        return $this->file($shoot, $item, $actor, $lane, ['workflow_stage' => 'todo', 'media_type' => $rawType,
            'verified_at' => null, 'created_at' => now()->subHours(3),
            'metadata' => ['active_work_raw_submission' => $receiptOverrides + [
                'cycle' => app(DeliveryPlanService::class)->executionKey($shoot),
                'execution_key' => app(DeliveryPlanService::class)->itemExecutionKey($shoot, $item),
                'submitted_at' => now()->subHours(3)->toIso8601String(), 'content_version' => 1]]]);
    }

    private function receipt(Shoot $shoot, ShootService $item, User $actor, string $kind, ShootFile $file): void
    {
        DB::table('shoot_delivery_receipts')->insert(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
            'component_key' => $kind, 'execution_key' => app(DeliveryPlanService::class)->itemExecutionKey($shoot, $item),
            'method' => 'media', 'evidence' => json_encode(['files' => [['id' => $file->id, 'version' => 1]]]),
            'actor_id' => $actor->id, 'plan_revision' => str_repeat('a', 64),
            'operation_key' => 'approved-'.$item->id.'-'.$kind, 'delivered_at' => now()->subHours(4)]);
    }
}
