<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies ShootResource exposes the external-booking columns so the frontend
 * "External Booking Mapping" popup section can render the preferred/alternate
 * schedule, requested photographers, payload, warnings, and mapping status.
 *
 * Covers Requirement 2.22.
 */
class ShootResourceExternalBookingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exposes_the_external_booking_fields_with_matching_formatting(): void
    {
        $payload = ['source' => 'external_site', 'services' => [1, 2]];
        $warnings = ['Multiple photographers were requested for one service. Please review manually.'];
        $requested = [11, 22, 33];

        $shoot = Shoot::factory()->create([
            'alternate_scheduled_date' => '2026-07-04',
            'alternate_time' => '14:30',
            'alternate_scheduled_at' => '2026-07-04 14:30:00',
            'requested_photographers' => $requested,
            'external_booking_payload' => $payload,
            'external_booking_warnings' => $warnings,
            'external_booking_mapping_status' => Shoot::MAPPING_STATUS_NEEDS_REVIEW,
        ]);

        $array = (new ShootResource($shoot->fresh()))->toArray(Request::create('/'));

        // date / datetime fields follow the same formatting as scheduledDate/scheduledAt
        $this->assertSame('2026-07-04', $array['alternate_scheduled_date']);
        $this->assertSame($shoot->alternate_scheduled_at->toIso8601String(), $array['alternate_scheduled_at']);

        // plain string fields
        $this->assertSame('14:30', $array['alternate_time']);
        $this->assertSame(Shoot::MAPPING_STATUS_NEEDS_REVIEW, $array['external_booking_mapping_status']);

        // array/json fields
        $this->assertSame($requested, $array['requested_photographers']);
        $this->assertSame($payload, $array['external_booking_payload']);
        $this->assertSame($warnings, $array['external_booking_warnings']);
    }

    #[Test]
    public function it_defaults_array_fields_safely_when_columns_are_null(): void
    {
        $shoot = Shoot::factory()->create([
            'alternate_scheduled_date' => null,
            'alternate_time' => null,
            'alternate_scheduled_at' => null,
            'requested_photographers' => null,
            'external_booking_payload' => null,
            'external_booking_warnings' => null,
            'external_booking_mapping_status' => null,
        ]);

        $array = (new ShootResource($shoot->fresh()))->toArray(Request::create('/'));

        $this->assertNull($array['alternate_scheduled_date']);
        $this->assertNull($array['alternate_scheduled_at']);
        $this->assertNull($array['alternate_time']);
        $this->assertNull($array['external_booking_mapping_status']);
        $this->assertNull($array['external_booking_payload']);
        $this->assertSame([], $array['requested_photographers']);
        $this->assertSame([], $array['external_booking_warnings']);
    }
}
