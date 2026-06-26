<?php

namespace Tests\Unit\Services\ExternalBooking;

use App\Http\Requests\ExternalBookingRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\ExternalBooking\Data\ExternalBookingData;
use App\Services\ExternalBooking\ExternalBookingScheduleNormalizer;
use App\Services\ExternalBooking\NormalizedBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validates that ExternalBookingScheduleNormalizer collapses the accepted external
 * booking input shapes into the consistent NormalizedBooking structure: photographer
 * alias resolution + de-duplication, ordered selected services, preserved explicit
 * service assignments, and safe handling of empty/absent inputs.
 *
 * Covers Requirements 2.2.
 */
class ExternalBookingScheduleNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private ExternalBookingScheduleNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ExternalBookingScheduleNormalizer();
    }

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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'Normalizer Client',
            'client_email' => 'normalizer-client@example.com',
            'address' => '700 Normalize Blvd',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ], $overrides);
    }

    #[Test]
    public function it_resolves_photographer_aliases_into_requested_photographers(): void
    {
        $service = Service::factory()->create();
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();

        $payload = $this->basePayload([
            'services' => [['id' => $service->id, 'quantity' => 1]],
            'preferred_date' => '2026-03-01',
            'preferred_time' => '10:30',
            // Aliases spread across all four accepted keys.
            'selected_photographer_id' => $photographerA->id,
            'selected_photographers' => [$photographerB->id],
        ]);

        $data = ExternalBookingData::fromRequest($this->makeValidatedRequest($payload));
        $normalized = $this->normalizer->normalize($data);

        $this->assertInstanceOf(NormalizedBooking::class, $normalized);
        $this->assertSame([$photographerA->id, $photographerB->id], $normalized->requested_photographers);
        $this->assertSame(['date' => '2026-03-01', 'time' => '10:30'], $normalized->preferred);
    }

    #[Test]
    public function it_deduplicates_photographer_ids_repeated_across_aliases(): void
    {
        $service = Service::factory()->create();
        $photographer = User::factory()->create();

        $payload = $this->basePayload([
            'services' => [['id' => $service->id, 'quantity' => 1]],
            'preferred_date' => '2026-03-01',
            'preferred_time' => '10:30',
            'selected_photographer_id' => $photographer->id,
            'photographer_id' => $photographer->id,
            'selected_photographers' => [$photographer->id],
            'requested_photographers' => [$photographer->id],
        ]);

        $data = ExternalBookingData::fromRequest($this->makeValidatedRequest($payload));
        $normalized = $this->normalizer->normalize($data);

        $this->assertSame([$photographer->id], $normalized->requested_photographers);
    }

    #[Test]
    public function it_preserves_ordered_services_and_explicit_assignments(): void
    {
        $service1 = Service::factory()->create();
        $service2 = Service::factory()->create();
        $photographer = User::factory()->create();

        $payload = $this->basePayload([
            'services' => [
                ['id' => $service1->id, 'quantity' => 2],
                ['id' => $service2->id],
            ],
            'preferred_date' => '2026-03-01',
            'preferred_time' => '09:00',
            'alternate_date' => '2026-03-02',
            'alternate_time' => '13:00',
            'service_assignments' => [
                [
                    'service_id' => $service2->id,
                    'photographer_id' => $photographer->id,
                    'scheduled_date' => '2026-03-03',
                    'scheduled_time' => '08:15',
                ],
            ],
        ]);

        $data = ExternalBookingData::fromRequest($this->makeValidatedRequest($payload));
        $normalized = $this->normalizer->normalize($data);

        $this->assertSame([
            ['id' => $service1->id, 'quantity' => 2],
            ['id' => $service2->id, 'quantity' => null],
        ], $normalized->selected_services);

        $this->assertSame(['date' => '2026-03-02', 'time' => '13:00'], $normalized->alternate);

        $this->assertSame([
            [
                'service_id' => $service2->id,
                'photographer_id' => $photographer->id,
                'scheduled_date' => '2026-03-03',
                'scheduled_time' => '08:15',
            ],
        ], $normalized->service_assignments);
    }

    #[Test]
    public function it_handles_empty_and_absent_inputs(): void
    {
        // Construct the DTO directly to exercise the normalizer with fully empty inputs.
        $data = new ExternalBookingData(
            rawPayload: [],
            services: [],
            preferredDate: null,
            preferredTime: null,
            alternateDate: null,
            alternateTime: null,
            requestedPhotographerIds: [],
            serviceAssignments: [],
            source: 'external_website',
        );

        $normalized = $this->normalizer->normalize($data);

        $this->assertSame(['date' => null, 'time' => null], $normalized->preferred);
        $this->assertSame(['date' => null, 'time' => null], $normalized->alternate);
        $this->assertSame([], $normalized->requested_photographers);
        $this->assertSame([], $normalized->selected_services);
        $this->assertSame([], $normalized->service_assignments);
    }

    #[Test]
    public function it_preserves_a_date_only_preferred_with_null_time(): void
    {
        $service = Service::factory()->create();

        $payload = $this->basePayload([
            'services' => [['id' => $service->id, 'quantity' => 1]],
            'preferred_date' => '2026-03-01',
            // No preferred_time provided.
        ]);

        $data = ExternalBookingData::fromRequest($this->makeValidatedRequest($payload));
        $normalized = $this->normalizer->normalize($data);

        $this->assertSame(['date' => '2026-03-01', 'time' => null], $normalized->preferred);
        $this->assertSame(['date' => null, 'time' => null], $normalized->alternate);
    }
}
