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
        return [$shoot, $payload];
    }

    public function test_unchanged_overview_context_is_removed_while_schedule_fields_are_preserved(): void
    {
        [$shoot, $payload] = $this->fixture();
        $normalized = app(AssignedRepSchedulePayload::class)->normalize($shoot, $payload);
        $this->assertSame([
            'scheduled_date' => '2026-10-05', 'time' => '11:00', 'notify_client' => false,
            'service_items' => [['service_id' => $payload['services'][0]['id'], 'scheduled_at' => '2026-10-05 11:00:00']],
        ], $normalized);
        $this->assertSame($payload['address'], $shoot->fresh()->address);
        $this->assertSame(250.0, (float) $shoot->serviceItems()->sole()->price);
    }

    #[DataProvider('forbiddenContext')]
    public function test_non_schedule_edits_and_service_plan_changes_are_rejected(string $change): void
    {
        [$shoot, $payload] = $this->fixture();
        match ($change) {
            'address' => $payload['address'] = 'Another property',
            'client' => $payload['client_id'] = 999999,
            'price' => $payload['services'][0]['price'] = 0,
            'quantity' => $payload['services'][0]['quantity'] = 2,
            'pay' => $payload['services'][0]['photographer_pay'] = 999,
            'assignment' => $payload['service_photographers'][0]['photographer_id'] = 999999,
            'property' => $payload['property_details']['beds'] = 9,
            'new_service' => $payload['services'][0]['id'] = 999999,
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
        return array_map(fn ($change) => [$change], ['address', 'client', 'price', 'quantity', 'pay', 'assignment', 'property', 'new_service', 'duplicate_service', 'unknown_service_field', 'unknown_property_field']);
    }
}
