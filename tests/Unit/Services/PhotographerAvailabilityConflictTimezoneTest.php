<?php

namespace Tests\Unit\Services;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotographerAvailabilityConflictTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_jaz_style_10am_et_shoot_does_not_block_1pm_et_naive_or_zulu(): void
    {
        config([
            'app.timezone' => 'UTC',
            'availability.buffer_time_minutes' => 30,
            'availability.default_shoot_duration_minutes' => 120,
        ]);

        $photographer = User::factory()->create([
            'role' => 'photographer',
            'timezone' => 'America/New_York',
        ]);
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create(['name' => 'HDR Photos']);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-10-02 14:00:00', // 10:00 ET stored as UTC instant
            'timezone' => 'America/New_York',
            'status' => ShootWorkflowService::STATUS_SCHEDULED,
            'address' => '5629 Herberts Crossing Drive',
            'city' => 'Burke',
            'state' => 'VA',
            'zip' => '22015',
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-10-02 14:00:00',
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
        ]);

        $support = app(ShootMutationSupportService::class);
        $availability = app(PhotographerAvailabilityService::class);

        $naive = $support->parseScheduleInstant('2026-10-02T13:00:00', 'America/New_York');
        $zulu = $support->parseScheduleInstant('2026-10-02T17:00:00.000Z', 'America/New_York');
        $offset = $support->parseScheduleInstant('2026-10-02T13:00:00-04:00', 'America/New_York');

        foreach ([$naive, $zulu, $offset] as $scheduledAt) {
            $availability->assertWithinAvailabilityBounds(
                (int) $photographer->id,
                Carbon::parse($scheduledAt),
                120,
                null,
                false,
                'America/New_York'
            );
            $this->assertTrue(
                $availability->isAvailable(
                    (int) $photographer->id,
                    Carbon::parse($scheduledAt),
                    120,
                    null,
                    'America/New_York'
                )
            );
        }

        $this->assertSame($shoot->id, Shoot::where('photographer_id', $photographer->id)->value('id'));
    }
}
