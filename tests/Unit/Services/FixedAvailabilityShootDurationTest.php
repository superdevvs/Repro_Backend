<?php

namespace Tests\Unit\Services;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAvailabilityShootDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_capture_duration_is_preserved_above_the_one_hour_default(): void
    {
        config([
            'availability.default_shoot_duration_minutes' => 60,
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

        $this->assertSame(240, $support->calculateShootDurationFromShoot($shoot));
        $this->assertSame(240, $support->calculateShootDurationFromServices([
            ['id' => $longService->id, 'duration_minutes' => 240],
        ]));

        // The saved appointment also wins in the legacy loaded-service fallback.
        $fresh = $shoot->fresh(['services']);
        if ($fresh->services->isNotEmpty()) {
            $fresh->setRelation('serviceItems', new \Illuminate\Database\Eloquent\Collection);
            $this->assertSame(240, $support->calculateShootDurationFromShoot($fresh));
        }
    }
}
