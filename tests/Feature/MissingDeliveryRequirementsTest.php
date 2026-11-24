<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\{Service, Shoot, User};
use App\Services\ShootActivityLogger;
use App\Services\Shoots\DeliveryPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Tests\TestCase;

class MissingDeliveryRequirementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Storage::fake('public');
    }

    private function fixture(): array
    {
        $actor = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create([
            'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(),
            'timezone' => 'UTC', 'delivery_status' => 'not_started',
            'total_quote' => 250, 'payment_status' => 'unpaid',
            'admin_verified_at' => null, 'completed_at' => null,
        ]);
        $service = Service::factory()->create([
            'name' => 'Premium iGuide with Floor plans', 'upload_intake_type' => 'none',
            'requires_editing' => false, 'included_components' => null,
        ]);
        $item = $shoot->serviceItems()->create([
            'service_id' => $service->id, 'price' => 250, 'quantity' => 1,
            'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
            'scheduled_at' => now()->subDay(),
        ]);
        // Imported/pivot-inserted bookings can predate the model's snapshot hook.
        DB::table('shoot_service')->where('id', $item->id)->update(['delivery_requirements' => null]);
        $url = 'https://youriguide.com/pending-provider-example';
        $shoot->forceFill([
            'iguide_property_id' => 'pending-provider-example', 'iguide_tour_url' => $url,
            'iguide_data' => ['service_line_id' => $item->id,
                'ownership_property_id' => 'pending-provider-example', 'ownership_tour_url' => $url],
        ])->save();

        return [$shoot->fresh(), $item->fresh(), $actor];
    }

    public function test_pending_provider_booking_without_snapshot_can_open_finalise_and_defer_both_outputs(): void
    {
        [$shoot, $item, $actor] = $this->fixture();
        $this->actingAs($actor)->getJson('/api/shoots/'.$shoot->id)
            ->assertOk()->assertJsonPath('data.can_finalize_delivery', true);
        $plan = $this->getJson('/api/shoots/'.$shoot->id.'/delivery-plan')
            ->assertOk()->json('data');
        $this->assertSame([], $plan['ready_component_ids']);
        $this->assertSame(['pending', 'pending'], array_column($plan['components'], 'status'));
        $this->assertSame('pending', app(\App\Services\Shoots\ShootServiceProgress::class)->forItem($shoot, $item));
        $this->assertNull($item->fresh()->delivery_requirements);

        $payload = ['mode' => 'auto', 'component_ids' => [$item->id.':floorplan', $item->id.':tour'],
            'allow_without_content' => true, 'confirmed' => true, 'reason' => 'Waiting for iGUIDE delivery',
            'plan_revision' => $plan['revision'], 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload)->assertAccepted();
        $job = Queue::pushed(FinalizeShootJob::class)->first();
        $this->assertNotNull($job);
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));

        $this->assertSame(['defer', 'defer'], DB::table('shoot_delivery_receipts')->orderBy('id')->pluck('method')->all());
        $this->assertNotSame('delivered', $shoot->fresh()->delivery_status);
        $this->assertNotSame('delivered', $item->fresh()->delivery_status);
        $this->assertNull($shoot->fresh()->completed_at);
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $components = app(DeliveryPlanService::class)->componentSummaries($shoot->fresh())[$item->id];
        $this->assertSame(['pending', 'pending'], array_column($components, 'status'));
        $this->assertSame([true, true], array_column($components, 'deferred'));
        $this->assertSame([false, false], array_column($components, 'without_content'));
        $this->postJson('/api/shoots/'.$shoot->id.'/finalize', $payload)
            ->assertOk()->assertJsonPath('data.already_applied', true);
        $this->assertDatabaseCount('shoot_delivery_receipts', 2);
    }

    public function test_batched_and_direct_requirements_agree_without_writing_a_snapshot(): void
    {
        [$shoot, $item, $actor] = $this->fixture();
        $policy = new DeliveryPlanService;
        $policy->primeRequirements($shoot, $shoot->serviceItems()->with('service.category')->get());
        $this->assertTrue($policy->usesComponents($shoot));
        $this->assertTrue($policy->canFinalizeDelivery($shoot, $actor));
        $this->assertNotEmpty($policy->componentSummaries($shoot)[$item->id]);
        $this->assertNull($item->fresh()->delivery_requirements);
        $this->assertFalse($policy->canFinalizeDelivery($shoot, User::factory()->create(['role' => 'client'])));
        $this->assertFalse($policy->canFinalizeDelivery($shoot, null));
    }

    public function test_reviewed_external_delivery_preserves_the_concrete_requirements(): void
    {
        [$shoot, $item, $actor] = $this->fixture();
        $plan = app(DeliveryPlanService::class)->plan($shoot, $actor);
        $this->actingAs($actor)->postJson('/api/shoots/'.$shoot->id.'/finalize', [
            'mode' => 'external', 'component_ids' => [$item->id.':floorplan', $item->id.':tour'],
            'confirmed' => true, 'plan_revision' => $plan['revision'],
        ])->assertAccepted();
        $job = Queue::pushed(FinalizeShootJob::class)->first();
        $this->assertNotNull($job);
        $job->suppressSideEffects = true;
        $job->handle(app(ShootActivityLogger::class));

        $this->assertSame('delivered', $shoot->fresh()->delivery_status);
        $this->assertSame(['floorplan', 'tour'], array_column($item->fresh()->delivery_requirements, 'kind'));
        $this->assertTrue(app(DeliveryPlanService::class)->usesComponents($shoot->fresh()));
        $this->assertSame(['delivered', 'delivered'], array_column(
            app(DeliveryPlanService::class)->componentSummaries($shoot->fresh())[$item->id], 'status'));
        $this->assertFalse(app(DeliveryPlanService::class)->canFinalizeDelivery($shoot->fresh(), $actor));
    }

    public function test_historical_delivery_and_explicit_empty_requirements_keep_their_existing_policy(): void
    {
        [$shoot, $item] = $this->fixture();
        foreach ([
            ['workflow_status' => 'delivered', 'delivery_status' => 'delivered', 'delivery_requirements' => null],
            ['workflow_status' => 'scheduled', 'delivery_status' => 'not_started', 'delivery_requirements' => '[]'],
            ['delivery_requirements' => json_encode([['key' => 'legacy', 'kind' => 'legacy', 'label' => 'Historical delivery']])],
        ] as $attributes) {
            DB::table('shoot_service')->where('id', $item->id)->update($attributes);
            $policy = new DeliveryPlanService;
            $this->assertFalse($policy->usesComponents($shoot));
            $policy->primeRequirements($shoot, $shoot->serviceItems()->with('service.category')->get());
            $this->assertFalse($policy->usesComponents($shoot));
            $this->assertSame([], $policy->componentSummaries($shoot));
        }
    }

    public function test_missing_snapshot_does_not_bypass_future_or_suspended_work(): void
    {
        [$shoot, $item, $actor] = $this->fixture();
        DB::table('shoot_service')->where('id', $item->id)->update(['scheduled_at' => now()->addDay()]);
        $this->assertFalse((new DeliveryPlanService)->canFinalizeDelivery($shoot, $actor));
        DB::table('shoot_service')->where('id', $item->id)->update(['scheduled_at' => now()->subDay()]);
        $shoot->forceFill(['status' => 'on_hold', 'workflow_status' => 'on_hold'])->save();
        $this->assertFalse((new DeliveryPlanService)->canFinalizeDelivery($shoot->fresh(), $actor));
    }
}
