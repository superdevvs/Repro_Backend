<?php

namespace Tests\Unit\Services\ExternalBooking;

use App\Http\Requests\ExternalBookingRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\ExternalBooking\Data\ExternalBookingData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validates that ExternalBookingData::fromRequest builds the expected immutable shape
 * from a representative external booking request, normalizing/de-duping photographer
 * ids across their aliases and preserving the raw payload for provenance.
 *
 * Covers Requirements 2.1, 2.15.
 */
class ExternalBookingDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a validated ExternalBookingRequest from a payload.
     */
    private function makeValidatedRequest(array $payload): ExternalBookingRequest
    {
        $request = ExternalBookingRequest::create('/api/external/book-shoot', 'POST', $payload);
        $request->setContainer(app());
        $request->setRedirector(app(\Illuminate\Routing\Redirector::class));
        $request->validateResolved();

        return $request;
    }

    #[Test]
    public function from_request_builds_the_expected_shape(): void
    {
        $service1 = Service::factory()->create();
        $service2 = Service::factory()->create();
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();

        $payload = [
            'client_name' => 'Rep Client',
            'client_email' => 'rep-client@example.com',
            'address' => '500 Mapping Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $service1->id, 'quantity' => 2],
                ['id' => $service2->id],
            ],
            'preferred_date' => '2026-03-01',
            'preferred_time' => '10:30',
            'alternate_date' => '2026-03-02',
            'alternate_time' => '13:00',
            'selected_photographer_id' => $photographerA->id,
            'photographer_id' => $photographerA->id,
            'selected_photographers' => [$photographerA->id, $photographerB->id],
            'requested_photographers' => [$photographerB->id],
            'service_assignments' => [
                [
                    'service_id' => $service2->id,
                    'photographer_id' => $photographerB->id,
                    'scheduled_date' => '2026-03-03',
                    'scheduled_time' => '09:15',
                ],
            ],
            'source' => 'lovable',
        ];

        $request = $this->makeValidatedRequest($payload);
        $data = ExternalBookingData::fromRequest($request);

        // Scalar scheduling fields.
        $this->assertSame('2026-03-01', $data->preferredDate);
        $this->assertSame('10:30', $data->preferredTime);
        $this->assertSame('2026-03-02', $data->alternateDate);
        $this->assertSame('13:00', $data->alternateTime);
        $this->assertSame('lovable', $data->source);

        // Services normalized to [['id'=>int,'quantity'=>?int], ...].
        $this->assertSame([
            ['id' => $service1->id, 'quantity' => 2],
            ['id' => $service2->id, 'quantity' => null],
        ], $data->services);

        // Photographer ids normalized across all aliases, de-duped, order preserved.
        $this->assertSame([$photographerA->id, $photographerB->id], $data->requestedPhotographerIds);

        // Explicit service assignments preserved with normalized types.
        $this->assertSame([
            [
                'service_id' => $service2->id,
                'photographer_id' => $photographerB->id,
                'scheduled_date' => '2026-03-03',
                'scheduled_time' => '09:15',
            ],
        ], $data->serviceAssignments);

        // Raw payload preserved for provenance (2.15).
        $this->assertSame('Rep Client', $data->rawPayload['client_name']);
        $this->assertSame('rep-client@example.com', $data->rawPayload['client_email']);
        $this->assertSame($payload['services'], $data->rawPayload['services']);
        $this->assertArrayHasKey('service_assignments', $data->rawPayload);
    }

    #[Test]
    public function from_request_defaults_source_and_handles_absent_optional_fields(): void
    {
        $service = Service::factory()->create();

        $payload = [
            'client_name' => 'Legacy Client',
            'client_email' => 'legacy-client@example.com',
            'address' => '123 Legacy St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $service->id, 'quantity' => 1],
            ],
            'preferred_date' => '2026-03-01',
            'preferred_time' => '10:30',
            // No alternate, photographers, assignments, or source.
        ];

        $request = $this->makeValidatedRequest($payload);
        $data = ExternalBookingData::fromRequest($request);

        $this->assertSame('2026-03-01', $data->preferredDate);
        $this->assertSame('10:30', $data->preferredTime);
        $this->assertNull($data->alternateDate);
        $this->assertNull($data->alternateTime);
        $this->assertSame([], $data->requestedPhotographerIds);
        $this->assertSame([], $data->serviceAssignments);
        $this->assertSame('external_website', $data->source);
        $this->assertSame([['id' => $service->id, 'quantity' => 1]], $data->services);
    }

    #[Test]
    public function from_request_dedupes_repeated_photographer_ids_across_aliases(): void
    {
        $service = Service::factory()->create();
        $photographer = User::factory()->create();

        $payload = [
            'client_name' => 'Dup Client',
            'client_email' => 'dup-client@example.com',
            'address' => '1 Dup Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $service->id, 'quantity' => 1],
            ],
            'preferred_date' => '2026-03-01',
            'preferred_time' => '10:30',
            'selected_photographer_id' => $photographer->id,
            'photographer_id' => $photographer->id,
            'selected_photographers' => [$photographer->id],
            'requested_photographers' => [$photographer->id],
        ];

        $request = $this->makeValidatedRequest($payload);
        $data = ExternalBookingData::fromRequest($request);

        $this->assertSame([$photographer->id], $data->requestedPhotographerIds);
    }
}
