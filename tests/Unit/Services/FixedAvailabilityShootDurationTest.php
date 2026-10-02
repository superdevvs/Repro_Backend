<?php

namespace Tests\Unit\Services;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootDurationResolver;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAvailabilityShootDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booked_duration_uses_snapshot_even_when_catalog_has_a_shorter_duration(): void
    {
        config([
            'availability.default_shoot_duration_minutes' => 60,
            'availability.min_shoot_duration_minutes' => 5,
            'availability.max_shoot_duration_minutes' => 300,
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

        $this->assertSame(240, $support->calculateShootDurationFromShoot($shoot));
        $this->assertSame(240, $support->calculateShootDurationFromServices([
            ['id' => $longService->id, 'duration_minutes' => 240],
        ]));

        $fresh = $shoot->fresh(['services']);
        if ($fresh->services->isNotEmpty()) {
            $fresh->setRelation('serviceItems', new \Illuminate\Database\Eloquent\Collection);
            $this->assertSame(240, $support->calculateShootDurationFromShoot($fresh));
        }
    }

    public function test_legacy_missing_duration_uses_configured_default(): void
    {
        config([
            'availability.default_shoot_duration_minutes' => 60,
            'availability.min_shoot_duration_minutes' => 5,
            'availability.max_shoot_duration_minutes' => 300,
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
            ['default_minutes' => 60, 'min_minutes' => 5, 'max_minutes' => 300],
            $legacy->booking_duration_defaults
        );

        // Only unsnapshotted legacy work uses the fallback default.
        config(['availability.default_shoot_duration_minutes' => 45]);
        $this->assertSame(45, $resolver->defaultMinutes());
        $this->assertSame(45, app(ShootMutationSupportService::class)->calculateShootDurationFromServices([]));
    }
}
