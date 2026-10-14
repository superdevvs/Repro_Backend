<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Services\IguideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IguideTourLinkDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://manage.youriguide.test/api/v1';
    private const ID = 'igTVJNLOV6ESGD1';
    private const ALIAS = '5506_burkittsville_rd_burkittsville_md';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'services.iguide.app_id' => 'test-app',
            'services.iguide.app_token' => 'test-token',
            'services.iguide.base_url' => self::BASE,
        ]);
    }

    public function test_saved_tour_recovers_floorplans_despite_duplicate_city_and_wrong_zip(): void
    {
        $this->fakeProperty();
        $shoot = $this->shoot([
            'address' => '5506 Burkittsville Rd Burkittsville',
            'city' => 'BURKITTSVILLE',
            'zip' => '21718',
            'iguide_tour_url' => 'https://youriguide.com/'.self::ALIAS.'?unbranded#floorplan',
        ]);

        $result = app(IguideService::class)->syncShoot($shoot);

        $this->assertSame(self::ID, $result['property_id']);
        $this->assertCount(6, $result['floorplans']);
        $this->assertSame(self::ID, $shoot->fresh()->iguide_property_id);
        $this->assertCount(6, $shoot->fresh()->iguide_floorplans);
        Http::assertSent(fn (Request $request) => $this->query($request) === [
            'filter.alias' => self::ALIAS, 'page.limit' => '100',
        ]);
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/iguides/'.self::ID);
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/iguides/'.self::ID.'/asset-urls');
        $this->assertOnlyPortalRequests();
    }

    #[DataProvider('managedTourLinks')]
    public function test_managed_tour_slots_resolve_official_tour_variants(string $slot, string $url): void
    {
        $this->fakeProperty();
        $shoot = $this->shoot([
            'address' => '', 'city' => '', 'state' => '', 'zip' => '',
            'tour_links' => [$slot => $url],
        ]);

        $result = app(IguideService::class)->syncShoot($shoot);

        $this->assertSame(self::ID, $result['property_id']);
        $this->assertSame($url, $shoot->fresh()->tour_links[$slot], 'A saved manual tour slot must remain intact.');
        Http::assertSent(fn (Request $request) => ($this->query($request)['filter.alias'] ?? null) === self::ALIAS);
        $this->assertOnlyPortalRequests();
    }

    public static function managedTourLinks(): array
    {
        return [
            'branded' => ['iguide_branded', 'https://youriguide.com/'.self::ALIAS.'/'],
            'MLS' => ['iguide_mls', 'https://unbranded.youriguide.com/'.self::ALIAS.'/'],
            'photos' => ['iguide_branded', 'https://iguidephotos.com/'.self::ALIAS.'/'],
            'unbranded photos' => ['iguide_mls', 'https://unbranded.iguidephotos.com/'.self::ALIAS.'/'],
            'radix' => ['iguide_branded', 'https://iguideradix.com/'.self::ALIAS.'/'],
            'unbranded radix' => ['iguide_mls', 'https://unbranded.iguideradix.com/'.self::ALIAS.'/'],
            'embedded with query and fragment' => ['iguide_branded', 'https://youriguide.com/embed/'.self::ALIAS.'/?authtoken=test-token#tour'],
            'explicit TLS port' => ['iguide_branded', 'https://youriguide.com:443/'.self::ALIAS.'/'],
        ];
    }

    public function test_existing_provider_id_takes_precedence_over_a_different_saved_link(): void
    {
        $this->fakeProperty();
        $shoot = $this->shoot([
            'iguide_property_id' => self::ID,
            'iguide_tour_url' => 'https://youriguide.com/another-property/',
        ]);

        $result = app(IguideService::class)->syncShoot($shoot);

        $this->assertSame(self::ID, $result['property_id']);
        Http::assertNotSent(fn (Request $request) => isset($this->query($request)['filter.alias']));
    }

    public function test_stale_provider_id_can_recover_through_the_saved_tour_link(): void
    {
        $this->fakeProperty();
        $shoot = $this->shoot([
            'iguide_property_id' => 'igStaleProviderId',
            'iguide_tour_url' => 'https://youriguide.com/'.self::ALIAS.'/',
        ]);

        $result = app(IguideService::class)->syncShoot($shoot);

        $this->assertSame(self::ID, $result['property_id']);
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/iguides/igStaleProviderId');
        Http::assertSent(fn (Request $request) => ($this->query($request)['filter.alias'] ?? null) === self::ALIAS);
    }

    public function test_a_provider_that_ignores_alias_filter_cannot_attach_a_different_tour(): void
    {
        Http::fake(fn () => Http::response([array_replace($this->property(), ['alias' => 'different-property'])]));
        $shoot = $this->shoot([
            'address' => '', 'city' => '', 'state' => '', 'zip' => '',
            'iguide_tour_url' => 'https://youriguide.com/'.self::ALIAS.'/',
        ]);

        $this->assertNull(app(IguideService::class)->syncShoot($shoot));

        $this->assertNull($shoot->fresh()->iguide_property_id);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/iguides/'.self::ID));
    }

    public function test_repeated_saved_alias_is_looked_up_only_once_when_provider_has_no_match(): void
    {
        Http::fake(fn () => Http::response([]));
        $shoot = $this->shoot([
            'address' => '', 'city' => '', 'state' => '', 'zip' => '',
            'iguide_tour_url' => 'https://youriguide.com/'.self::ALIAS.'/',
            'tour_links' => [
                'iguide_branded' => 'https://youriguide.com/embed/'.self::ALIAS.'/',
                'iguide_mls' => 'https://unbranded.youriguide.com/'.self::ALIAS.'/?unbranded',
            ],
        ]);

        $this->assertNull(app(IguideService::class)->syncShoot($shoot));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && ($this->query($request)['filter.alias'] ?? null) === self::ALIAS);
    }

    public function test_exhausted_read_budget_stops_pagination_and_is_restored_after_sync(): void
    {
        $service = app(IguideService::class);
        $deadline = new \ReflectionProperty($service, 'portalReadDeadline');
        $wrongProperty = array_replace_recursive($this->property(), ['address' => ['streetName' => 'Different Rd']]);
        Http::fake(function () use ($service, $deadline, $wrongProperty) {
            $deadline->setValue($service, microtime(true) - 1);
            return Http::response(array_fill(0, 100, $wrongProperty));
        });

        $this->assertNull($service->syncShoot($this->shoot([])));

        Http::assertSentCount(1);
        $this->assertNull($deadline->getValue($service), 'The sync must restore the previous deadline in its finally block.');
        $this->assertSame(IguideService::FAILURE_OTHER, $service->getLastFailureReason());
    }

    #[DataProvider('unsafeTourLinks')]
    public function test_unsafe_or_malformed_saved_links_are_never_requested(string $url): void
    {
        Http::fake();
        $shoot = $this->shoot([
            'address' => '', 'city' => '', 'state' => '', 'zip' => '',
            'iguide_tour_url' => $url,
            'tour_links' => ['iguide_branded' => $url, 'iguide_mls' => $url],
        ]);

        $this->assertNull(app(IguideService::class)->syncShoot($shoot));

        $this->assertNull($shoot->fresh()->iguide_property_id);
        Http::assertNothingSent();
    }

    public static function unsafeTourLinks(): array
    {
        $alias = self::ALIAS;
        return [
            'arbitrary host' => ["https://external.test/{$alias}"],
            'misleading suffix' => ["https://youriguide.com.external.test/{$alias}"],
            'unapproved subdomain' => ["https://anything.youriguide.com/{$alias}"],
            'userinfo' => ["https://user:password@youriguide.com/{$alias}"],
            'userinfo misleading host' => ["https://youriguide.com@external.test/{$alias}"],
            'nonstandard port' => ["https://youriguide.com:8443/{$alias}"],
            'plain HTTP' => ["http://youriguide.com/{$alias}"],
            'relative URL' => ["//youriguide.com/{$alias}"],
            'additional path segment' => ["https://youriguide.com/{$alias}/data"],
            'encoded slash' => ["https://youriguide.com/{$alias}%2Fdata"],
            'empty path' => ['https://youriguide.com/'],
            'traversal path' => ["https://youriguide.com/embed/../{$alias}"],
            'invalid alias character' => ["https://youriguide.com/{$alias}.html"],
        ];
    }

    public function test_address_only_discovery_keeps_rejecting_a_zip_mismatch(): void
    {
        Http::fake(fn () => Http::response([$this->property()]));

        $this->assertNull(app(IguideService::class)->searchByAddress(
            '5506 Burkittsville Rd, Burkittsville, MD 21718'
        ));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/asset-urls'));
    }

    public function test_provider_address_finds_a_shoot_with_city_appended_after_a_street_suffix(): void
    {
        $shoot = $this->shoot([
            'address' => '5506 Burkittsville Rd Burkittsville', 'city' => 'BURKITTSVILLE', 'zip' => '21755',
        ]);

        $found = app(IguideService::class)->findShootByAddress('5506 Burkittsville Rd, Burkittsville, MD 21755');

        $this->assertNotNull($found);
        $this->assertSame($shoot->id, $found->id);
        Http::assertNothingSent();
    }

    public function test_city_that_is_part_of_the_street_name_is_never_truncated(): void
    {
        $shoot = $this->shoot(['address' => '100 Maple Grove', 'city' => 'Grove']);
        $service = app(IguideService::class);

        $found = $service->findShootByAddress('100 Maple Grove, Grove, MD 21755');

        $this->assertNotNull($found);
        $this->assertSame($shoot->id, $found->id);
        $this->assertNull($service->findShootByAddress('100 Maple, Grove, MD 21755'));
        Http::assertNothingSent();
    }

    public function test_address_discovery_uses_documented_dotted_filters_and_abbreviation_aware_matching(): void
    {
        $this->fakeProperty();

        $result = app(IguideService::class)->searchByAddress(
            '5506 Burkittsville Road, Burkittsville, MD 21755'
        );

        $this->assertSame(self::ID, $result['property_id']);
        $this->assertCount(6, $result['floorplans']);
        Http::assertSent(function (Request $request) {
            $query = $this->query($request);
            return ($query['filter.house'] ?? null) === '5506'
                && ($query['filter.code'] ?? null) === '21755'
                && ($query['page.limit'] ?? null) === '100'
                && ($query['page.offset'] ?? null) === '0'
                && ($query['sort'] ?? null) === '-created_at'
                // Provider street/city filters are case-sensitive exact matches;
                // keep canonical spelling comparison on our side.
                && array_intersect(array_keys($query), ['filter.street', 'filter.city', 'search', 'q', 'limit']) === [];
        });
        $this->assertOnlyPortalRequests();
    }

    private function shoot(array $attributes): Shoot
    {
        return Shoot::factory()->create(array_replace([
            'address' => '5506 Burkittsville Rd', 'city' => 'Burkittsville', 'state' => 'MD', 'zip' => '21755',
            'iguide_property_id' => null, 'iguide_tour_url' => null, 'tour_links' => [],
        ], $attributes));
    }

    private function property(): array
    {
        return [
            'id' => self::ID, 'alias' => self::ALIAS, 'type' => 'standard',
            'address' => [
                'streetNumber' => '5506', 'streetName' => 'Burkittsville Rd',
                'city' => 'Burkittsville', 'provinceState' => 'MD', 'postalCode' => '21755', 'country' => 'US',
            ],
        ];
    }

    private function fakeProperty(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/v1/iguides') {
                return Http::response([$this->property()]);
            }
            if ($path === '/api/v1/iguides/'.self::ID) {
                return Http::response($this->property());
            }
            if ($path === '/api/v1/iguides/'.self::ID.'/asset-urls') {
                $media = ['pdfMetric' => 'https://youriguide.com/'.self::ALIAS.'/doc/metric.pdf',
                    'pdfImperial' => 'https://youriguide.com/'.self::ALIAS.'/doc/imperial.pdf'];
                foreach (['Metric', 'Imperial'] as $units) {
                    $media['jpg'.$units] = array_map(fn ($floor) => [
                        'id' => $floor, 'floorName' => 'Floor '.$floor,
                        'url' => 'https://youriguide.com/'.self::ALIAS.'/doc/'.strtolower($units).'-'.$floor.'.jpg',
                    ], [1, 2]);
                }
                return Http::response(['languages' => ['en' => $media]]);
            }
            return Http::response([], 404);
        });
    }

    /** Preserve dotted query names; PHP parse_str changes them to underscores. */
    private function query(Request $request): array
    {
        $query = [];
        foreach (explode('&', parse_url($request->url(), PHP_URL_QUERY) ?: '') as $pair) {
            if ($pair === '') continue;
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[urldecode($key)] = urldecode($value);
        }
        return $query;
    }

    private function assertOnlyPortalRequests(): void
    {
        Http::assertNotSent(fn (Request $request) => !str_starts_with($request->url(), self::BASE.'/'));
    }
}
