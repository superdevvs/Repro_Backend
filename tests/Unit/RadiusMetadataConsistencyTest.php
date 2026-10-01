<?php

namespace Tests\Unit;

use App\Services\Photographers\RadiusEligibility;
use App\Services\Shoots\Actions\AssignServicePhotographerAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RadiusMetadataConsistencyTest extends TestCase
{
    #[DataProvider('profileRadii')]
    public function test_profile_distance_has_the_same_mile_value_for_booking_and_assignment(array $metadata, ?float $expected): void
    {
        $this->assertSame($expected, RadiusEligibility::radiusFromMetadata($metadata));
        $reflection = new \ReflectionClass(AssignServicePhotographerAction::class);
        $assignment = $reflection->newInstanceWithoutConstructor();
        $this->assertSame($expected, $reflection->getMethod('resolveRadiusMiles')->invoke($assignment, $metadata));
    }

    public static function profileRadii(): array
    {
        return [
            'explicit miles wins over profile kilometres' => [['service_radius_miles' => 25, 'travel_range' => 100, 'travel_range_unit' => 'km'], 25.0],
            'explicit zero does not become unlimited' => [['service_radius_miles' => 0, 'travel_range' => 100], 0.0],
            'profile miles' => [['travel_range' => 45, 'travel_range_unit' => 'miles'], 45.0],
            'profile kilometres' => [['travel_range' => 100, 'travel_range_unit' => 'km'], 62.1],
            'camel case profile kilometres' => [['travelRange' => '100', 'travelRangeUnit' => 'KM'], 62.1],
            'missing distance' => [[], null],
            'nonnumeric distance' => [['travel_range' => 'unknown'], null],
        ];
    }

    public function test_profile_fallback_preserves_radius_boundary_and_disabled_enforcement(): void
    {
        config(['availability.radius_enforcement' => true, 'availability.radius_unlimited_when_null' => false]);
        $radius = RadiusEligibility::radiusFromMetadata(['travel_range' => 40, 'travel_range_unit' => 'miles']);
        $this->assertTrue(RadiusEligibility::evaluate($radius, 40)['eligible']);
        $this->assertSame('outside_radius', RadiusEligibility::evaluate($radius, 40.1)['reason']);
        $this->assertSame('radius_zero', RadiusEligibility::evaluate(0, 0)['reason']);
        $this->assertSame('radius_unset', RadiusEligibility::evaluate(null, 0)['reason']);
        config(['availability.radius_enforcement' => false]);
        $this->assertTrue(RadiusEligibility::evaluate(null, null)['eligible']);
    }
}
