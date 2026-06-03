<?php

namespace Tests\Unit;

use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShootResourceCoordinateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_serializes_listing_coordinates_from_property_details(): void
    {
        $shoot = Shoot::factory()->create([
            'property_details' => [
                'latitude' => '33.7488',
                'longitude' => '-84.3877',
            ],
        ]);

        $payload = (new ShootResource($shoot))->toArray(Request::create('/api/shoots/'.$shoot->id));

        $this->assertSame(33.7488, $payload['location']['latitude']);
        $this->assertSame(-84.3877, $payload['location']['longitude']);
        $this->assertSame(33.7488, $payload['latitude']);
        $this->assertSame(-84.3877, $payload['longitude']);
    }

    #[Test]
    public function it_serializes_listing_coordinates_from_lat_lng_aliases(): void
    {
        $shoot = Shoot::factory()->create([
            'property_details' => [
                'lat' => 34.0522,
                'lng' => -118.2437,
            ],
        ]);

        $payload = (new ShootResource($shoot))->toArray(Request::create('/api/shoots/'.$shoot->id));

        $this->assertSame(34.0522, $payload['location']['latitude']);
        $this->assertSame(-118.2437, $payload['location']['longitude']);
        $this->assertSame(34.0522, $payload['latitude']);
        $this->assertSame(-118.2437, $payload['longitude']);
    }
}
