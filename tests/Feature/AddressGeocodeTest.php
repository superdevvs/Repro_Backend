<?php

namespace Tests\Feature;

use App\Services\NominatimRequestThrottler;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddressGeocodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('services.nominatim.throttle_cache_store', 'array');
        Cache::store('array')->forget(NominatimRequestThrottler::LAST_REQUEST_STARTED_AT_KEY);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_geocodes_an_exact_address_through_the_backend(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([
                ['lat' => '39.0840', 'lon' => '-77.1528'],
            ]),
        ]);

        $response = $this->postJson('/api/address/geocode', [
            'address' => '777 QA Desktop Journey Ave',
            'city' => 'Rockville',
            'state' => 'MD',
            'zip' => '20850',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.latitude', 39.084)
            ->assertJsonPath('data.longitude', -77.1528);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search')
            && $request['q'] === '777 QA Desktop Journey Ave, Rockville, MD, 20850'
        );
    }

    #[Test]
    public function it_returns_a_successful_empty_result_when_an_address_cannot_be_geocoded(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([]),
        ]);

        $this->postJson('/api/address/geocode', [
            'address' => 'Unknown Address',
            'city' => 'Nowhere',
            'state' => 'MD',
            'zip' => '00000',
        ])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'data' => null,
            ]);
    }

    #[Test]
    public function it_falls_back_to_locality_coordinates_when_the_street_is_unknown(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');
        Sleep::fake(syncWithCarbon: true);

        Http::fakeSequence()
            ->push([], 200)
            ->push([
                ['lat' => '39.0840', 'lon' => '-77.1528'],
            ], 200);

        $this->postJson('/api/address/geocode', [
            'address' => '888 Client UI Request Ave',
            'city' => 'Rockville',
            'state' => 'MD',
            'zip' => '20850',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.latitude', 39.084)
            ->assertJsonPath('data.longitude', -77.1528);

        Http::assertSentCount(2);
        Sleep::assertSlept(
            fn ($duration) => (int) $duration->totalMilliseconds === 1000
        );
    }
}
