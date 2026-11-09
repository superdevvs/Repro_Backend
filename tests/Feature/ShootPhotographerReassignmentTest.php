<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootPhotographerAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ShootPhotographerReassignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_stale_header_cannot_grant_list_detail_or_media_access_after_reassignment(): void
    {
        [$shoot, $previous, $current, $service] = $this->fixture();
        $shoot->load('services');
        $shoot->services()->updateExistingPivot($service->id, ['photographer_id' => $current->id]);
        $authorization = app(ShootAuthorizationSupport::class);
        $this->assertFalse($authorization->isPhotographerAssignedToShoot($shoot, $previous));
        $this->assertTrue($authorization->isPhotographerAssignedToShoot($shoot, $current));

        Sanctum::actingAs($previous);
        foreach (['/api/shoots?tab=scheduled&view=card', '/api/photographer/shoots'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        }
        foreach (['', '/files', '/media'] as $suffix) {
            $this->getJson("/api/shoots/{$shoot->id}{$suffix}")->assertForbidden();
        }

        Sanctum::actingAs($current);
        $this->getJson('/api/shoots?tab=scheduled&view=card')->assertOk()->assertJsonPath('data.0.id', $shoot->id);
        $this->getJson("/api/shoots/{$shoot->id}")->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/shoots?tab=scheduled&photographer_id[]='.$previous->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/shoots?tab=scheduled&photographer_id[]='.$current->id)->assertOk()->assertJsonPath('data.0.id', $shoot->id);

        // Read-local authorization must not survive another request/reassignment.
        $shoot->services()->updateExistingPivot($service->id, ['photographer_id' => $previous->id]);
        Sanctum::actingAs($current);
        $this->getJson("/api/shoots/{$shoot->id}")->assertForbidden();
    }

    public function test_service_assignment_route_updates_header_without_changing_booked_service_facts(): void
    {
        [$shoot, $previous, $current, $service] = $this->fixture();
        $before = $shoot->serviceItems()->first()->only(['id', 'service_id', 'price', 'quantity', 'photographer_pay', 'duration_minutes', 'scheduled_at']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", [
            'service_id' => $service->id, 'photographer_id' => $current->id,
        ])->assertOk();
        $this->assertSame($current->id, $shoot->fresh()->photographer_id);
        $item = $shoot->serviceItems()->first();
        $this->assertSame($current->id, $item->photographer_id);
        $this->assertEquals($before, $item->only(array_keys($before)));
    }

    #[TestWith(['explicit'])]
    #[TestWith(['inherited'])]
    #[TestWith(['legacy'])]
    public function test_real_split_and_legacy_assignments_retain_header_ownership(string $mode): void
    {
        [$shoot, $previous, $current, $service] = $this->fixture();
        if ($mode === 'legacy') {
            $shoot->services()->detach();
        } else {
            $shoot->services()->updateExistingPivot($service->id, ['photographer_id' => $current->id]);
            $shoot->services()->attach(Service::factory()->create()->id, [
                'photographer_id' => $mode === 'explicit' ? $previous->id : null,
            ]);
        }
        app(ShootPhotographerAssignmentService::class)->reconcileHeader($shoot);
        $this->assertSame($previous->id, $shoot->photographer_id);
        $this->assertTrue(app(ShootAuthorizationSupport::class)->isPhotographerAssignedToShoot($shoot, $previous));
    }

    #[TestWith(['digital'])]
    #[TestWith(['fee'])]
    public function test_unassigned_digital_work_and_optional_fee_recipient_do_not_preserve_obsolete_header(string $kind): void
    {
        [$shoot, $previous, $current, $service] = $this->fixture();
        $shoot->services()->updateExistingPivot($service->id, ['photographer_id' => $current->id]);
        $extra = Service::factory()->create($kind === 'digital'
            ? ['name' => 'Virtual Staging'] : ['name' => 'Cancellation fee', 'financial_only' => true]);
        $shoot->services()->attach($extra->id, [
            'photographer_id' => null,
            'payout_snapshot' => json_encode(['delivery_requirement' => $kind === 'fee' ? 'financial_only' : 'work']),
        ]);
        $this->assertFalse(app(ShootAuthorizationSupport::class)->isPhotographerAssignedToShoot($shoot, $previous));
        app(ShootPhotographerAssignmentService::class)->reconcileHeader($shoot);
        $this->assertSame($current->id, $shoot->photographer_id);
    }

    private function fixture(): array
    {
        $previous = User::factory()->photographer()->create();
        $current = User::factory()->photographer()->create();
        $service = Service::factory()->create();
        $current->setServiceCapabilities([$service->id]);
        $current->save();
        $shoot = Shoot::factory()->create([
            'photographer_id' => $previous->id, 'service_id' => $service->id,
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => null,
        ]);
        $shoot->services()->attach($service->id, [
            'photographer_id' => $previous->id, 'price' => 125, 'quantity' => 1,
            'photographer_pay' => 40, 'duration_minutes' => 30,
        ]);

        return [$shoot, $previous, $current, $service];
    }
}
