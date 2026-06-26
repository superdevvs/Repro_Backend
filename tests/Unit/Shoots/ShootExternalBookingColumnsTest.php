<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the external-booking columns added to the shoots table are mass-assignable
 * (fillable) and cast correctly: json columns round-trip to arrays, date/datetime columns
 * to Carbon, and the intentionally-uncast string columns stay plain strings.
 *
 * Covers Requirements 2.15, 2.16, 3.9.
 */
class ShootExternalBookingColumnsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_mass_assigns_and_persists_the_new_external_booking_columns(): void
    {
        $payload = ['source' => 'external_site', 'services' => [1, 2]];
        $warnings = ['Multiple photographers requested for a single service.'];
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

        // Reload from the database to confirm persistence + cast on retrieval.
        $fresh = Shoot::findOrFail($shoot->id);

        // json <-> array casts
        $this->assertSame($requested, $fresh->requested_photographers);
        $this->assertSame($payload, $fresh->external_booking_payload);
        $this->assertSame($warnings, $fresh->external_booking_warnings);

        // date / datetime casts
        $this->assertInstanceOf(Carbon::class, $fresh->alternate_scheduled_date);
        $this->assertSame('2026-07-04', $fresh->alternate_scheduled_date->toDateString());
        $this->assertInstanceOf(Carbon::class, $fresh->alternate_scheduled_at);
        $this->assertSame('2026-07-04 14:30:00', $fresh->alternate_scheduled_at->format('Y-m-d H:i:s'));

        // intentionally-uncast plain strings
        $this->assertIsString($fresh->alternate_time);
        $this->assertSame('14:30', $fresh->alternate_time);
        $this->assertIsString($fresh->external_booking_mapping_status);
        $this->assertSame(Shoot::MAPPING_STATUS_NEEDS_REVIEW, $fresh->external_booking_mapping_status);
    }

    #[Test]
    public function the_new_columns_are_declared_fillable(): void
    {
        $fillable = (new Shoot())->getFillable();

        foreach ([
            'alternate_scheduled_date',
            'alternate_time',
            'alternate_scheduled_at',
            'requested_photographers',
            'external_booking_payload',
            'external_booking_warnings',
            'external_booking_mapping_status',
        ] as $column) {
            $this->assertContains($column, $fillable, "Expected {$column} to be fillable");
        }
    }

    #[Test]
    public function mapping_status_constants_have_expected_values(): void
    {
        $this->assertSame('fully_mapped', Shoot::MAPPING_STATUS_FULLY_MAPPED);
        $this->assertSame('partially_mapped', Shoot::MAPPING_STATUS_PARTIALLY_MAPPED);
        $this->assertSame('needs_review', Shoot::MAPPING_STATUS_NEEDS_REVIEW);
    }
}
