<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Services\Shoots\AssignedRepSchedulePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssignedRepSchedulePayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    private function fixture(): array
    {
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
            'timezone' => 'America/New_York', 'property_details' => ['beds' => 3, 'baths' => 2, 'sqft' => 1500],
        ]);
        $service = Service::factory()->create();
        $shoot->services()->attach($service->id, ['price' => 250, 'quantity' => 1, 'photographer_pay' => 75, 'photographer_id' => $shoot->photographer_id]);
        $payload = [
            'scheduled_date' => '2026-10-05', 'time' => '11:00', 'address' => $shoot->address,
            'client_id' => (string) $shoot->client_id, 'photographer_id' => $shoot->photographer_id,
            'timezone' => $shoot->timezone, 'bedrooms' => '3',
            'property_details' => ['beds' => 3, 'bedrooms' => '3', 'baths' => 2, 'squareFeet' => 1500, 'presenceOption' => 'self', 'lockboxCode' => null],
            'services' => [['id' => $service->id, 'price' => '250.00', 'quantity' => 1, 'photographer_pay' => 75, 'scheduled_at' => '2026-10-05 11:00:00']],
            'service_photographers' => [['service_id' => $service->id, 'photographer_id' => $shoot->photographer_id]],
            'notify_client' => false,
        ];
        return [$shoot, $payload, $service];
    }

    public function test_unchanged_overview_context_is_removed_while_schedule_fields_are_preserved(): void
    {
        [$shoot, $payload] = $this->fixture();
        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);
        $this->assertSame('2026-10-05', $normalized['scheduled_date']);
        $this->assertSame('11:00', $normalized['time']);
        $this->assertFalse($normalized['notify_client']);
        $this->assertSame(
            [['id' => $payload['services'][0]['id'], 'scheduled_at' => '2026-10-05 11:00:00']],
            $normalized['services']
        );
        $this->assertSame(
            ['scheduled_date', 'time', 'services', 'notify_client'],
            array_keys($normalized)
        );
        $this->assertSame($payload['address'], $shoot->fresh()->address);
        $this->assertSame(250.0, (float) $shoot->serviceItems()->sole()->price);
    }

    public function test_assigned_rep_may_add_a_bookable_service_to_the_plan(): void
    {
        [$shoot, $payload, $existing] = $this->fixture();
        $zillow = Service::factory()->create([
            'name' => 'Zillow 3D Home Tour',
            'price' => 180,
        ]);
        $zillow->forceFill(['is_migration_only' => false])->save();

        $payload['services'][] = [
            'id' => $zillow->id,
            'price' => 180,
            'quantity' => 1,
            'scheduled_at' => '2026-10-05 11:00:00',
        ];
        $payload['service_photographers'][] = [
            'service_id' => $zillow->id,
            'photographer_id' => $shoot->photographer_id,
        ];

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);

        $this->assertCount(2, $normalized['services']);
        $this->assertSame($existing->id, $normalized['services'][0]['id']);
        $this->assertSame('2026-10-05 11:00:00', $normalized['services'][0]['scheduled_at']);
        $this->assertSame($zillow->id, $normalized['services'][1]['id']);
        $this->assertSame(1, $normalized['services'][1]['quantity']);
        $this->assertSame('2026-10-05 11:00:00', $normalized['services'][1]['scheduled_at']);
        $this->assertArrayNotHasKey('service_photographers', $normalized);
    }

    public function test_assigned_rep_cannot_add_a_migration_only_service(): void
    {
        [$shoot, $payload] = $this->fixture();
        $legacy = Service::factory()->create(['name' => 'Legacy Migration Service', 'price' => 10]);
        $legacy->forceFill(['is_migration_only' => true])->save();

        $payload['services'][] = ['id' => $legacy->id, 'quantity' => 1];

        try {
            app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);
            $this->fail('Migration-only services must stay blocked for assigned representatives.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_assigned_rep_may_drop_a_service_from_the_plan_payload(): void
    {
        [$shoot, $payload, $existing] = $this->fixture();
        $extra = Service::factory()->create(['name' => 'Drone Photos', 'price' => 125]);
        $shoot->services()->attach($extra->id, [
            'price' => 125, 'quantity' => 1, 'photographer_pay' => 40, 'photographer_id' => $shoot->photographer_id,
        ]);

        $payload['services'] = [[
            'id' => $existing->id,
            'price' => '250.00',
            'quantity' => 1,
            'photographer_pay' => 75,
            'scheduled_at' => '2026-10-05 11:00:00',
        ]];
        $payload['service_photographers'] = [[
            'service_id' => $existing->id,
            'photographer_id' => $shoot->photographer_id,
        ]];

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);
        $this->assertSame([
            ['id' => $existing->id, 'scheduled_at' => '2026-10-05 11:00:00'],
        ], $normalized['services']);
    }

    #[DataProvider('forbiddenContext')]
    public function test_non_schedule_edits_and_service_plan_changes_are_rejected(string $change): void
    {
        [$shoot, $payload] = $this->fixture();
        match ($change) {
            'address' => $payload['address'] = 'Another property',
            'client' => $payload['client_id'] = 999999,
            'assignment' => $payload['service_photographers'][0]['photographer_id'] = 999999,
            'property' => $payload['property_details']['beds'] = 9,
            'unknown_service' => $payload['services'][0]['id'] = 999999,
            'duplicate_service' => $payload['services'][] = $payload['services'][0],
            'unknown_service_field' => $payload['services'][0]['editor_id'] = 999999,
            'unknown_property_field' => $payload['property_details']['privateAdminFlag'] = true,
        };
        try {
            app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);
            $this->fail('An assigned representative must not change '.$change.' through schedule context.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public static function forbiddenContext(): array
    {
        return array_map(fn ($change) => [$change], [
            'address', 'client', 'assignment', 'property',
            'unknown_service', 'duplicate_service', 'unknown_service_field', 'unknown_property_field',
        ]);
    }

    public function test_existing_line_price_qty_pay_echoes_are_stripped_even_when_they_drift(): void
    {
        [$shoot, $payload, $existing] = $this->fixture();
        // Simulate a legacy booked price that no longer matches catalog / FE echo.
        $shoot->services()->updateExistingPivot($existing->id, [
            'price' => 275,
            'quantity' => 1,
            'photographer_pay' => 90,
        ]);
        $zillow = Service::factory()->create(['name' => 'Zillow 3D Home Tour', 'price' => 180]);
        $zillow->forceFill(['is_migration_only' => false])->save();

        $payload['services'] = [
            [
                'id' => $existing->id,
                // Catalog / FE echo that drifts from the booked 275 line.
                'price' => 175,
                'quantity' => 2,
                'photographer_pay' => 40,
                'scheduled_at' => '2026-10-05 11:00:00',
            ],
            [
                'id' => $zillow->id,
                'price' => 180,
                'quantity' => 1,
                'scheduled_at' => '2026-10-05 11:00:00',
            ],
        ];
        $payload['service_photographers'] = [
            ['service_id' => $existing->id, 'photographer_id' => $shoot->photographer_id],
            ['service_id' => $zillow->id, 'photographer_id' => $shoot->photographer_id],
        ];

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot->fresh(), $payload);

        $this->assertSame([
            ['id' => $existing->id, 'scheduled_at' => '2026-10-05 11:00:00'],
            ['id' => $zillow->id, 'quantity' => 1, 'scheduled_at' => '2026-10-05 11:00:00'],
        ], $normalized['services']);
        $this->assertArrayNotHasKey('price', $normalized['services'][0]);
        $this->assertArrayNotHasKey('photographer_pay', $normalized['services'][0]);
        $this->assertSame(275.0, (float) $shoot->fresh()->serviceItems()->where('service_id', $existing->id)->value('price'));
    }
}
