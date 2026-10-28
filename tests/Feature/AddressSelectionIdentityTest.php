<?php

namespace Tests\Feature;

use App\Services\ZillowPropertyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AddressSelectionIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('services.google.places_api_key', null);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['www.zillowstatic.com/*' => Http::response(['results' => [[
            'display' => '4807 Osage St College Park, MD 20740',
            'metaData' => ['zpid' => 37563875, 'streetNumber' => '4807', 'streetName' => 'Osage St',
                'city' => 'College Park', 'state' => 'MD', 'zipCode' => '20740',
                'lat' => 38.992386, 'lng' => -76.930389],
        ]]])]);
    }

    private function property(array $address): void
    {
        $service = Mockery::mock(ZillowPropertyService::class);
        $service->shouldReceive('fetchPropertyDetails')->once()
            ->with('4807 Osage St College Park, MD 20740')->andReturn([
                'address' => $address, 'beds' => 3, 'baths' => 2, 'sqft' => 1477,
                'zpid' => '37563875', 'source' => 'bridge_zpid',
            ]);
        $this->app->instance(ZillowPropertyService::class, $service);
    }

    private function detailsUrl(string $id = '37563875'): string
    {
        return '/api/address/details?' . http_build_query([
            'place_id' => $id, 'address' => '4807 Osage St College Park, MD 20740',
        ]);
    }

    public static function conflicts(): array
    {
        return [
            'street suffix' => ['street_name', 'Osage Ave'],
            'city' => ['city', 'Potomac'],
            'state' => ['state', 'VA'],
            'zip' => ['zip', '20854'],
            'street number' => ['street', '4809'],
        ];
    }

    #[DataProvider('conflicts')]
    public function test_enrichment_cannot_replace_the_selected_property(string $field, string $value): void
    {
        $address = ['street' => '4807', 'street_name' => 'Osage St', 'city' => 'College Park',
            'state' => 'MD', 'zip' => '20740', 'formatted' => '4807 Osage St, College Park, MD 20740'];
        $address[$field] = $value;
        $this->property($address);
        // The previous, incorrectly merged response must not survive the release.
        Cache::put('address_details_zillow_' . md5('37563875|4807 Osage St College Park, MD 20740'),
            ['city' => 'Potomac', 'zip' => '20854'], 3600);

        $this->getJson($this->detailsUrl())->assertOk()
            ->assertJsonPath('data.address', '4807 Osage St')
            ->assertJsonPath('data.city', 'College Park')->assertJsonPath('data.state', 'MD')
            ->assertJsonPath('data.zip', '20740')->assertJsonPath('data.latitude', 38.992386)
            ->assertJsonPath('data.longitude', -76.930389)
            ->assertJsonPath('data.sqft', null)->assertJsonPath('data.property_details', null);
    }

    public function test_matching_enrichment_keeps_metrics_and_selected_coordinates_and_is_cached(): void
    {
        $this->property(['street' => '4807', 'street_name' => 'Osage Street', 'city' => 'College Park',
            'state' => 'MD', 'zip' => '20740-1234']);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson($this->detailsUrl())->assertOk()
                ->assertJsonPath('data.address', '4807 Osage St')->assertJsonPath('data.zip', '20740')
                ->assertJsonPath('data.latitude', 38.992386)->assertJsonPath('data.sqft', 1477)
                ->assertJsonPath('data.property_details.beds', 3);
        }
        Http::assertSentCount(1);
    }

    public function test_an_unconfirmed_id_does_not_use_a_different_suggestion(): void
    {
        $service = Mockery::mock(ZillowPropertyService::class);
        $service->shouldNotReceive('fetchPropertyDetails');
        $this->app->instance(ZillowPropertyService::class, $service);
        $this->getJson($this->detailsUrl('999999'))->assertOk()
            ->assertJsonPath('data.address', null)->assertJsonPath('data.city', null)
            ->assertJsonPath('data.sqft', null);
    }

    public function test_search_uses_metadata_for_the_street_without_repeating_the_city(): void
    {
        $this->getJson('/api/address/search?query=4807%20Osage')->assertOk()
            ->assertJsonPath('data.0.address', '4807 Osage St')
            ->assertJsonPath('data.0.main_text', '4807 Osage St')
            ->assertJsonPath('data.0.city', 'College Park');
    }
}
