<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\ShootWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreateShootTimezonePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_timezone_and_stores_absolute_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        Queue::fake();

        $admin = User::factory()->create(['role' => 'superadmin']);
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'timezone' => 'America/New_York',
        ]);
        $service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150,
            'photographer_required' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots', [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'address' => '100 Create TZ Lane',
            'city' => 'Burke',
            'state' => 'VA',
            'zip' => '22015',
            'services' => [
                ['id' => $service->id, 'quantity' => 1],
            ],
            'scheduled_at' => '2026-10-02T13:00:00',
            'timezone' => 'America/New_York',
        ]);

        $response->assertCreated();

        $shoot = Shoot::query()->latest('id')->first();
        $this->assertNotNull($shoot);
        $this->assertSame('America/New_York', $shoot->timezone);
        $this->assertSame('2026-10-02 17:00:00', $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame('13:00', $shoot->time);
        $this->assertSame('2026-10-02', $shoot->scheduled_date?->format('Y-m-d') ?? (string) $shoot->scheduled_date);

        $item = $shoot->serviceItems()->where('service_id', $service->id)->first();
        $this->assertNotNull($item);
        $this->assertSame('2026-10-02 17:00:00', $item->getRawOriginal('scheduled_at'));
    }

    public function test_create_jaz_1pm_et_does_not_need_skip_against_existing_10am_et(): void
    {
        config([
            'app.timezone' => 'UTC',
            'availability.buffer_time_minutes' => 30,
            'availability.default_shoot_duration_minutes' => 120,
        ]);
        Queue::fake();

        $admin = User::factory()->create(['role' => 'superadmin']);
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'timezone' => 'America/New_York',
        ]);
        $service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150,
            'photographer_required' => true,
        ]);

        // Existing #134-like booking: 10:00–12:00 ET.
        $existing = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-10-02 14:00:00',
            'timezone' => 'America/New_York',
            'status' => ShootWorkflowService::STATUS_SCHEDULED,
            'workflow_status' => ShootWorkflowService::STATUS_SCHEDULED,
            'address' => '5629 Herberts Crossing Drive',
            'city' => 'Burke',
            'state' => 'VA',
            'zip' => '22015',
        ]);
        $existing->services()->attach($service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-10-02 14:00:00',
            'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots', [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'address' => '200 After Existing Ave',
            'city' => 'Burke',
            'state' => 'VA',
            'zip' => '22015',
            'services' => [
                ['id' => $service->id, 'quantity' => 1],
            ],
            'scheduled_at' => '2026-10-02T13:00:00',
            'timezone' => 'America/New_York',
            // Explicitly do NOT skip — create must pass service-item availability.
            'skip_availability_check' => false,
        ]);

        $response->assertCreated();

        $created = Shoot::query()->where('address', '200 After Existing Ave')->first();
        $this->assertNotNull($created);
        $this->assertSame('America/New_York', $created->timezone);
        $this->assertSame('2026-10-02 17:00:00', $created->getRawOriginal('scheduled_at'));
    }

    public function test_create_without_timezone_still_treats_naive_as_utc(): void
    {
        config(['app.timezone' => 'UTC']);
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150,
            'photographer_required' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots', [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'address' => '300 Legacy Naive Rd',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $service->id, 'quantity' => 1],
            ],
            'scheduled_at' => '2026-11-10 11:00:00',
        ]);

        $response->assertCreated();

        $shoot = Shoot::query()->where('address', '300 Legacy Naive Rd')->first();
        $this->assertNotNull($shoot);
        $this->assertTrue($shoot->timezone === null || $shoot->timezone === '');
        $this->assertSame('2026-11-10 11:00:00', $shoot->getRawOriginal('scheduled_at'));
    }
}
