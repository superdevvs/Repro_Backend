<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\ExternalBookingRequest;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validates the optional/nullable rules added to ExternalBookingRequest for the new
 * external booking form fields (alternate schedule, photographer selection, explicit
 * service assignments), and confirms a legacy existing-fields-only payload still validates
 * unchanged.
 *
 * Covers Requirements 2.1, 3.7.
 */
class ExternalBookingRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the ExternalBookingRequest rules against a payload.
     */
    private function validate(array $payload): \Illuminate\Validation\Validator
    {
        $rules = (new ExternalBookingRequest())->rules();

        return Validator::make($payload, $rules);
    }

    /**
     * A minimal valid base payload using only the existing (legacy) fields.
     */
    private function baseLegacyPayload(int $serviceId): array
    {
        return [
            'client_name' => 'Legacy Client',
            'client_email' => 'legacy-client@example.com',
            'address' => '123 Legacy St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $serviceId, 'quantity' => 1],
            ],
            'preferred_date' => '2026-03-01',
            'preferred_time' => '10:30',
            'source' => 'lovable',
        ];
    }

    #[Test]
    public function it_accepts_a_legacy_existing_fields_only_payload_unchanged(): void
    {
        $service = Service::factory()->create();

        $validator = $this->validate($this->baseLegacyPayload($service->id));

        $this->assertTrue($validator->passes(), 'Legacy existing-fields-only payload should still validate. Errors: '.$validator->errors()->toJson());
    }

    #[Test]
    public function it_accepts_valid_new_optional_fields(): void
    {
        $service = Service::factory()->create();
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();

        $payload = array_merge($this->baseLegacyPayload($service->id), [
            'alternate_date' => '2026-03-02',
            'alternate_time' => '13:00',
            'selected_photographer_id' => $photographerA->id,
            'photographer_id' => $photographerA->id,
            'selected_photographers' => [$photographerA->id, $photographerB->id],
            'requested_photographers' => [$photographerA->id, $photographerB->id],
            'service_assignments' => [
                [
                    'service_id' => $service->id,
                    'photographer_id' => $photographerB->id,
                    'scheduled_date' => '2026-03-03',
                    'scheduled_time' => '09:15',
                ],
            ],
        ]);

        $validator = $this->validate($payload);

        $this->assertTrue($validator->passes(), 'Valid new optional fields should pass. Errors: '.$validator->errors()->toJson());
    }

    #[Test]
    public function it_accepts_a_service_assignment_with_only_a_service_id(): void
    {
        $service = Service::factory()->create();

        $payload = array_merge($this->baseLegacyPayload($service->id), [
            'service_assignments' => [
                ['service_id' => $service->id],
            ],
        ]);

        $validator = $this->validate($payload);

        $this->assertTrue($validator->passes(), 'A service_assignments entry with only a service_id should pass. Errors: '.$validator->errors()->toJson());
    }

    #[Test]
    public function it_rejects_a_non_integer_photographer_id(): void
    {
        $service = Service::factory()->create();

        $payload = array_merge($this->baseLegacyPayload($service->id), [
            'selected_photographer_id' => 'not-an-integer',
        ]);

        $validator = $this->validate($payload);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('selected_photographer_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_rejects_an_unparseable_alternate_date(): void
    {
        $service = Service::factory()->create();

        $payload = array_merge($this->baseLegacyPayload($service->id), [
            'alternate_date' => 'not-a-date',
        ]);

        $validator = $this->validate($payload);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('alternate_date', $validator->errors()->toArray());
    }

    #[Test]
    public function it_rejects_a_malformed_service_assignments_entry(): void
    {
        $service = Service::factory()->create();

        // Missing the required service_id and supplying an invalid date.
        $payload = array_merge($this->baseLegacyPayload($service->id), [
            'service_assignments' => [
                [
                    'photographer_id' => 'not-an-integer',
                    'scheduled_date' => 'not-a-date',
                ],
            ],
        ]);

        $validator = $this->validate($payload);

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('service_assignments.0.service_id', $errors);
        $this->assertArrayHasKey('service_assignments.0.photographer_id', $errors);
        $this->assertArrayHasKey('service_assignments.0.scheduled_date', $errors);
    }

    #[Test]
    public function it_rejects_a_non_integer_entry_in_selected_photographers(): void
    {
        $service = Service::factory()->create();

        $payload = array_merge($this->baseLegacyPayload($service->id), [
            'selected_photographers' => ['not-an-integer'],
        ]);

        $validator = $this->validate($payload);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('selected_photographers.0', $validator->errors()->toArray());
    }

    #[Test]
    public function it_rejects_a_nonexistent_photographer_id(): void
    {
        $service = Service::factory()->create();

        $payload = array_merge($this->baseLegacyPayload($service->id), [
            'photographer_id' => 999999,
        ]);

        $validator = $this->validate($payload);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('photographer_id', $validator->errors()->toArray());
    }
}
