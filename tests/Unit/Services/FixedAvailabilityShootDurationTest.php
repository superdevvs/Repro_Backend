<?php

namespace Tests\Unit\Services;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product rule: availability booked blocks / conflict windows / GCal event length
 * are always availability.booked_block_duration_minutes (120), never stretched
 * from per-service durations toward max_shoot_duration_minutes (240), and never
 * tied to availability.default_shoot_duration_minutes (scheduling/catalog default).
 */
class FixedAvailabilityShootDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booked_duration_stays_120_even_when_services_report_long_durations(): void
    {
        config([
            'availability.default_shoot_duration_minutes' => 60,
            'availability.booked_block_duration_minutes' => 120,
            'availability.min_shoot_duration_minutes' => 30,
            'availability.max_shoot_duration_minutes' => 240,
        ]);

        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();

        $longService = Service::factory()->create([
            'name' => 'Long Service',
            'shoot_duration_minutes' => 30,
        ]);
        $this->assertSame(30, $longService->getShootDurationMinutes());

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);
        $shoot->services()->attach($longService->id, ['duration_minutes' => 240]);

        $support = app(ShootMutationSupportService::class);

        $this->assertSame(120, $support->calculateShootDurationFromShoot($shoot));
        $this->assertSame(120, $support->calculateShootDurationFromServices([
            ['id' => $longService->id, 'duration_minutes' => 240],
        ]));

        $fresh = $shoot->fresh(['services']);
        if ($fresh->services->isNotEmpty()) {
            $fresh->setRelation('serviceItems', new \Illuminate\Database\Eloquent\Collection);
            $this->assertSame(120, $support->calculateShootDurationFromShoot($fresh));
        }
    }

    public function test_scheduling_catalog_resolver_default_is_sixty_independent_of_booked_block(): void
    {
        config([
            'availability.default_shoot_duration_minutes' => 60,
            'availability.booked_block_duration_minutes' => 120,
            'availability.min_shoot_duration_minutes' => 30,
            'availability.max_shoot_duration_minutes' => 240,
        ]);

        $resolver = app(ShootDurationResolver::class);
        $this->assertSame(60, $resolver->defaultMinutes());
        $this->assertSame(60, $resolver->forServices([]));

        $legacy = Service::factory()->create([
            'name' => 'Unset Duration Service',
            'shoot_duration_minutes' => null,
        ]);
        $this->assertSame(60, $legacy->getShootDurationMinutes());
        $this->assertSame(
            ['default_minutes' => 60, 'min_minutes' => 30, 'max_minutes' => 240],
            $legacy->booking_duration_defaults
        );

        // Changing scheduling default must NOT move the fixed booked block.
        config(['availability.default_shoot_duration_minutes' => 45]);
        $this->assertSame(45, $resolver->defaultMinutes());
        $this->assertSame(120, app(ShootMutationSupportService::class)->calculateShootDurationFromServices([]));
    }
}
