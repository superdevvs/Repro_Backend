<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

class AssignedRepDroneTravelTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    private function fixture(string $status = 'uploaded'): array
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        Sanctum::actingAs($rep);
        $shoot = Shoot::factory()->create([
            'rep_id' => $rep->id, 'status' => $status, 'workflow_status' => $status,
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'scheduled_at' => '2026-10-01 15:00:00', 'scheduled_date' => '2026-10-01',
            'time' => '11:00:00', 'timezone' => 'America/New_York',
            'state' => 'MD', 'tax_region' => 'MD', 'tax_percent' => 6,
            'base_quote' => 175, 'tax_amount' => 10.5, 'total_quote' => 185.5,
            'payment_status' => 'unpaid', 'photos_uploaded_at' => now(),
        ]);
        $old = Service::factory()->create(['name' => '25 HDR', 'price' => 175]);
        $shoot->services()->attach($old->id, [
            'price' => 175, 'quantity' => 1, 'photographer_id' => $shoot->photographer_id,
            'scheduled_at' => '2026-10-01 15:00:00', 'workflow_status' => 'in_progress',
        ]);
        return [$shoot, $old, $rep];
    }

    public function test_assigned_rep_adds_drone_without_next_week_travel_review_or_reassigning_staging(): void
    {
        $this->withoutExceptionHandling();
        config(['availability.hybrid_travel_enabled' => true, 'availability.scheduling_lock_store' => 'array',
            'availability.fallback_start_time' => '00:00', 'availability.fallback_end_time' => '23:59']);
        [$shoot, $old] = $this->fixture('scheduled');
        $old->update(['shoot_duration_minutes' => 120, 'photographer_required' => true]);
        $shoot->serviceItems()->update(['duration_minutes' => 120]);
        $editor = User::factory()->photographer()->create();
        $staging = Service::factory()->noIntake()->create(['name' => 'Virtual Staging', 'price' => 45,
            'allow_multiple' => true, 'photographer_required' => false]);
        $shoot->services()->attach($staging->id, ['quantity' => 2, 'price' => 45, 'duration_minutes' => 0,
            'photographer_id' => $editor->id, 'scheduled_at' => $shoot->scheduled_at, 'workflow_status' => 'scheduled']);
        $neighbor = Shoot::factory()->create(['photographer_id' => $shoot->photographer_id,
            'scheduled_at' => '2026-10-07 19:30:00', 'scheduled_date' => '2026-10-07', 'time' => '15:30',
            'timezone' => 'America/New_York', 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'address' => 'Unknown building', 'city' => '', 'state' => '', 'zip' => '']);
        $neighbor->services()->attach($old->id, ['photographer_id' => $shoot->photographer_id,
            'scheduled_at' => $neighbor->scheduled_at, 'duration_minutes' => 120, 'workflow_status' => 'scheduled']);
        $drone = Service::factory()->create(['name' => 'Drone Silver Package', 'price' => 199,
            'shoot_duration_minutes' => 45, 'photographer_required' => true]);
        $this->patchJson('/api/shoots/'.$shoot->id, ['services' => [['id' => $old->id],
            ['id' => $staging->id, 'quantity' => 2], ['id' => $drone->id]],
            'service_photographers' => [['service_id' => $old->id, 'photographer_id' => $shoot->photographer_id],
                ['service_id' => $staging->id, 'photographer_id' => $editor->id],
                ['service_id' => $drone->id, 'photographer_id' => $shoot->photographer_id]],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();
        $items = $shoot->fresh()->serviceItems()->get()->keyBy('service_id');
        $this->assertSame($shoot->photographer_id, $items[$drone->id]->photographer_id);
        $this->assertSame($shoot->photographer_id, $items[$old->id]->photographer_id);
        $this->assertSame($editor->id, $items[$staging->id]->photographer_id);
        $this->assertSame(2, (int) $items[$staging->id]->quantity);
        $this->assertSame(199.0, (float) $items[$drone->id]->price);
        $this->assertSame(120, (int) $items[$old->id]->duration_minutes);
        $this->assertSame(45, (int) $items[$drone->id]->duration_minutes);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

}
