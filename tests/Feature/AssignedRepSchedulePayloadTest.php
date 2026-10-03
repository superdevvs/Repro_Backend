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
        $this->assertSame($shoot->photographer_id, $normalized['photographer_id']);
        $this->assertSame(
            [['service_id' => $payload['service_photographers'][0]['service_id'], 'photographer_id' => $shoot->photographer_id]],
            $normalized['service_photographers']
        );
        $this->assertSame(
            ['scheduled_date', 'time', 'photographer_id', 'services', 'service_photographers', 'notify_client'],
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
        $this->assertSame([
            ['service_id' => $existing->id, 'photographer_id' => $shoot->photographer_id],
            ['service_id' => $zillow->id, 'photographer_id' => $shoot->photographer_id],
        ], $normalized['service_photographers']);
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
            'property' => $payload['property_details']['beds'] = 9,
            'unknown_service' => $payload['services'][0]['id'] = 999999,
            'duplicate_service' => $payload['services'][] = $payload['services'][0],
            'unknown_service_field' => $payload['services'][0]['admin_override_price'] = 1,
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
            'address', 'client', 'property',
            'unknown_service', 'duplicate_service', 'unknown_service_field',
        ]);
    }

    public function test_assigned_rep_may_reassign_shoot_and_service_photographers(): void
    {
        [$shoot, $payload, $existing] = $this->fixture();
        $payload['photographer_id'] = 424242;
        $payload['service_photographers'] = [[
            'service_id' => $existing->id,
            'photographer_id' => 424242,
        ]];

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);

        $this->assertSame(424242, $normalized['photographer_id']);
        $this->assertSame([
            ['service_id' => $existing->id, 'photographer_id' => 424242],
        ], $normalized['service_photographers']);
    }

    public function test_service_photographer_for_unknown_service_still_rejected(): void
    {
        [$shoot, $payload] = $this->fixture();
        $payload['service_photographers'] = [[
            'service_id' => 999999,
            'photographer_id' => 424242,
        ]];

        try {
            app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);
            $this->fail('Unknown service photographer rows must stay blocked.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_existing_line_price_and_pay_echoes_are_stripped_but_quantity_changes_survive(): void
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
            ['id' => $existing->id, 'quantity' => 2, 'scheduled_at' => '2026-10-05 11:00:00'],
            ['id' => $zillow->id, 'quantity' => 1, 'scheduled_at' => '2026-10-05 11:00:00'],
        ], $normalized['services']);
        $this->assertArrayNotHasKey('price', $normalized['services'][0]);
        $this->assertArrayNotHasKey('photographer_pay', $normalized['services'][0]);
        $this->assertSame(275.0, (float) $shoot->fresh()->serviceItems()->where('service_id', $existing->id)->value('price'));
    }

    public function test_services_row_photographer_id_is_lifted_into_service_photographers(): void
    {
        [$shoot, $payload, $existing] = $this->fixture();
        unset($payload['service_photographers']);
        $payload['photographer_id'] = 424242;
        $payload['services'][0]['photographer_id'] = 424242;
        $payload['services'][0]['editor_id'] = 999;
        $payload['services'][0]['is_deliverable'] = true;

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);

        $this->assertSame(424242, $normalized['photographer_id']);
        $this->assertSame([
            ['service_id' => $existing->id, 'photographer_id' => 424242],
        ], $normalized['service_photographers']);
        $this->assertSame(
            [['id' => $existing->id, 'scheduled_at' => '2026-10-05 11:00:00']],
            $normalized['services']
        );
    }

    public function test_explicit_service_photographers_wins_over_services_row_photographer_id(): void
    {
        [$shoot, $payload, $existing] = $this->fixture();
        $payload['services'][0]['photographer_id'] = 111;
        $payload['service_photographers'] = [[
            'service_id' => $existing->id,
            'photographer_id' => 222,
        ]];

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);

        $this->assertSame([
            ['service_id' => $existing->id, 'photographer_id' => 222],
        ], $normalized['service_photographers']);
    }

    public function test_overview_top_level_readonly_echoes_are_stripped_without_403(): void
    {
        [$shoot, $payload] = $this->fixture();
        $payload['status'] = 'scheduled';
        $payload['workflow_status'] = 'scheduled';
        $payload['listing_type'] = 'for_sale';
        $payload['property_status'] = 'available';
        $payload['notes'] = 'echo';
        $payload['base_quote'] = '199.00';
        $payload['total_quote'] = '210.94';
        $payload['tax_amount'] = '11.94';
        $payload['payment_status'] = 'unpaid';
        $payload['editor_id'] = null;
        $payload['video_editor_id'] = null;
        $payload['id'] = $shoot->id;
        $payload['service_id'] = 2;
        $payload['created_by'] = 'Office';
        $payload['presenceOption'] = 'self';
        $payload['lockboxCode'] = null;
        $payload['access_notes'] = null;
        $payload['unit_count'] = 0;
        $payload['tour_links'] = [
            'property_description' => 'echo',
            'realtor_client_id' => $shoot->client_id,
            'video_link' => 'https://example.test/v',
        ];

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);

        foreach ([
            'status', 'workflow_status', 'listing_type', 'property_status', 'notes',
            'base_quote', 'total_quote', 'tax_amount', 'payment_status', 'editor_id',
            'video_editor_id', 'id', 'service_id', 'created_by', 'presenceOption',
            'lockboxCode', 'access_notes', 'unit_count',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $normalized);
        }
        $this->assertSame(
            ['realtor_client_id' => $shoot->client_id],
            $normalized['tour_links']
        );
        $this->assertSame($shoot->photographer_id, $normalized['photographer_id']);
        $this->assertSame('2026-10-05', $normalized['scheduled_date']);
    }

    public function test_overview_property_details_echo_keys_and_null_presence_are_tolerated(): void
    {
        [$shoot, $payload] = $this->fixture();
        $payload['property_details']['completeAddress'] = $shoot->address;
        $payload['property_details']['livingArea'] = 1500;
        $payload['property_details']['presenceOption'] = null;
        $payload['timezone'] = null;

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);

        $this->assertArrayNotHasKey('property_details', $normalized);
        $this->assertArrayNotHasKey('timezone', $normalized);
        $this->assertSame('2026-10-05', $normalized['scheduled_date']);
    }

    public function test_real_property_details_change_still_forbidden(): void
    {
        [$shoot, $payload] = $this->fixture();
        $payload['property_details']['beds'] = 9;

        try {
            app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);
            $this->fail('Real property metric changes must stay forbidden.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }


    public function test_discount_fields_are_kept_for_assigned_rep_overview_save(): void
    {
        [$shoot, $payload] = $this->fixture();
        $payload['discount_type'] = 'percent';
        $payload['discount_value'] = 15;
        $payload['discount_amount'] = 29.85;
        $payload['notes'] = 'echo';

        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);

        $this->assertSame('percent', $normalized['discount_type']);
        $this->assertSame(15, $normalized['discount_value']);
        $this->assertSame(29.85, $normalized['discount_amount']);
        $this->assertArrayNotHasKey('notes', $normalized);
    }

}
