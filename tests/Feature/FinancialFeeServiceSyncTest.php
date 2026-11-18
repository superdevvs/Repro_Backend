<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, User};
use App\Services\Shoots\{DeliveryPlanService, ShootMutationSupportService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue};
use Tests\TestCase;

/** Shoot 5996 (1148 Old Manchester Rd): a travel fee added by editing the order showed Pending and needed delivery. */
class FinancialFeeServiceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(20, 0));
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    public function test_fee_added_through_service_sync_is_financial_only_and_needs_no_delivery(): void
    {
        $actor = User::factory()->create(['role' => 'superadmin']);
        $photo = Service::factory()->unbracketedPhoto(25)->create(['price' => 175]);
        $fee = Service::factory()->noIntake()->create(['name' => 'Travel fee - 100 miles', 'price' => 50,
            'photographer_required' => false, 'financial_only' => true]);
        $shoot = Shoot::factory()->create(['service_id' => $photo->id, 'status' => 'editing', 'workflow_status' => 'editing',
            'scheduled_at' => '2026-11-13 17:00:00', 'scheduled_date' => '2026-11-13', 'timezone' => 'UTC', 'photographer_id' => null]);
        $shoot->serviceItems()->create(['service_id' => $photo->id, 'price' => 175, 'quantity' => 1,
            'scheduled_at' => '2026-11-13 17:00:00', 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);

        app(ShootMutationSupportService::class)->attachServices($shoot->fresh(), [
            ['id' => $photo->id], ['id' => $fee->id, 'price' => 50, 'quantity' => 1],
        ]);

        $line = $shoot->serviceItems()->where('service_id', $fee->id)->sole();
        $this->assertFalse($line->is_deliverable, 'A fee is applied, not delivered.');
        $this->assertSame('financial_only', data_get($line->payout_snapshot, 'delivery_requirement'));
        $plan = app(DeliveryPlanService::class)->plan($shoot->fresh(), $actor);
        $this->assertEmpty(collect($plan['components'])->where('shoot_service_id', $line->id)->all());

        // The booked photo line is still ordinary work.
        $work = $shoot->serviceItems()->where('service_id', $photo->id)->sole();
        $this->assertTrue($work->is_deliverable);
        $this->assertSame('work', data_get($work->payout_snapshot, 'delivery_requirement'));
    }
}
