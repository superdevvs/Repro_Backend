<?php

namespace Tests\Unit\Services;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product rule: availability booked blocks / conflict windows / GCal event length
 * are always availability.default_shoot_duration_minutes (120), never stretched
 * from per-service getShootDurationMinutes toward max_shoot_duration_minutes.
 */
class FixedAvailabilityShootDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booked_duration_stays_120_even_when_services_report_long_durations(): void
    {
        config([
            'availability.default_shoot_duration_minutes' => 120,
            'availability.min_shoot_duration_minutes' => 60,
            'availability.max_shoot_duration_minutes' => 240,
        ]);

        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();

        $longService = Service::factory()->create([
            'name' => 'Long Service',
        ]);
        // Not persisted columns on services — force attributes so getShootDurationMinutes
        // would report 240 if the booked helpers still consulted it.
        $longService->setAttribute('shoot_duration_minutes', 240);
        $longService->setAttribute('duration_minutes', 240);
        $this->assertSame(240, $longService->getShootDurationMinutes());

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);
        $shoot->services()->attach($longService->id);

        // Swap the relation collection so the shoot sees the long-duration service
        // instance (DB reload would drop the non-column attributes).
        $shoot->setRelation('services', collect([$longService]));

        $support = app(ShootMutationSupportService::class);

        $this->assertSame(120, $support->calculateShootDurationFromShoot($shoot));
        $this->assertSame(120, $support->calculateShootDurationFromServices([
            ['id' => $longService->id],
        ]));

        // Even if the DB-backed service somehow reported a long duration via
        // attributes after reload path, the helper must stay fixed at 120.
        $fresh = $shoot->fresh(['services']);
        if ($fresh->services->isNotEmpty()) {
            $fresh->services->first()->setAttribute('shoot_duration_minutes', 240);
            $this->assertSame(120, $support->calculateShootDurationFromShoot($fresh));
        }
    }
}
