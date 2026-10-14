<?php

namespace Tests\Feature;

use App\Jobs\IngestIguideAssetsJob;
use App\Jobs\SyncShootIguideJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Services\IguideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IguideAutomaticSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'https://manage.youriguide.com/api/v1';
    private const PROPERTY_ID = 'igBURKITTSVILLE';
    private const ALIAS = '5506_burkittsville_rd_burkittsville_md';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.iguide.app_id' => 'fixture-app-id',
            'services.iguide.app_token' => 'fixture-app-token',
            'services.iguide.api_key' => null,
            'services.iguide.api_username' => null,
            'services.iguide.api_password' => null,
            'services.iguide.base_url' => self::PORTAL,
        ]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_portal_metadata_sync_fetches_separate_floorplan_assets_and_builds_tour_urls(): void
    {
        Http::fake([
            $this->propertyUrl() => Http::response($this->metadata()),
            $this->propertyUrl().'/asset-urls' => Http::response($this->assets()),
        ]);

        $result = app(IguideService::class)->syncProperty(self::PROPERTY_ID);

        $this->assertNotNull($result);
        $this->assertSame(self::PROPERTY_ID, $result['property_id']);
        $this->assertSame('default-view', $result['default_view_id']);
        $this->assertSame('https://youriguide.com/'.self::ALIAS.'/', $result['tour_url']);
        $this->assertSame('https://unbranded.youriguide.com/'.self::ALIAS.'/', $result['unbranded_url']);
        $this->assertSame('https://youriguide.com/embed/'.self::ALIAS.'/', $result['embedded_url']);
        $this->assertSixFloorplans($result['floorplans']);
        $this->assertSame($this->assets()['languages']['en']['pdfImperial'], $result['pdf_imperial_url']);
        $this->assertCount(2, $result['jpg_metric']);
        $this->assertSame('Main Floor', $result['jpg_metric'][0]['floor_name']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === $this->propertyUrl().'/asset-urls'
            && $request->method() === 'GET'
            && $request->hasHeader('X-Plntr-App-Id', 'fixture-app-id')
            && $request->hasHeader('X-Plntr-App-Token', 'fixture-app-token'));
    }

    public function test_automatic_sync_persists_all_six_floorplans_and_queues_ingestion(): void
    {
        Http::fake([
            $this->propertyUrl() => Http::response($this->metadata()),
            $this->propertyUrl().'/asset-urls' => Http::response($this->assets()),
        ]);
        $shoot = $this->shootWithFloorplan(['iguide_property_id' => self::PROPERTY_ID]);

        (new SyncShootIguideJob($shoot->id))->handle(app(IguideService::class));

        $shoot->refresh();
        $this->assertSixFloorplans($shoot->iguide_floorplans);
        $this->assertSame('default-view', $shoot->iguide_data['default_view_id']);
        $this->assertSame('https://youriguide.com/'.self::ALIAS.'/', $shoot->tour_links['iguide_branded']);
        $this->assertSame('https://unbranded.youriguide.com/'.self::ALIAS.'/', $shoot->tour_links['iguide_mls']);
        $this->assertNotNull($shoot->iguide_last_synced_at);
        Queue::assertPushed(IngestIguideAssetsJob::class, function (IngestIguideAssetsJob $job) use ($shoot): bool {
            if ($job->shootId !== $shoot->id) {
                return false;
            }
            $this->assertSixFloorplans($job->floorplans);

            return true;
        });
        Queue::assertPushed(IngestIguideAssetsJob::class, 1);
        Http::assertSentCount(2);
    }

    public function test_rich_ready_metadata_keeps_provider_urls_and_does_not_refetch_assets(): void
    {
        $metadata = $this->metadata();
        $metadata['urls'] = [
            'publicUrl' => 'https://youriguide.com/provider-selected-view/',
            'unbrandedUrl' => 'https://unbranded.youriguide.com/provider-selected-view/',
            'embeddedUrl' => 'https://youriguide.com/embed/provider-selected-view/',
            'mediaUrls' => $this->assets()['languages'],
        ];
        Http::fake([$this->propertyUrl() => Http::response($metadata)]);

        $result = app(IguideService::class)->syncProperty(self::PROPERTY_ID);

        $this->assertNotNull($result);
        $this->assertSixFloorplans($result['floorplans']);
        $this->assertSame($metadata['urls']['publicUrl'], $result['tour_url']);
        $this->assertSame($metadata['urls']['unbrandedUrl'], $result['unbranded_url']);
        $this->assertSame($metadata['urls']['embeddedUrl'], $result['embedded_url']);
        Http::assertSentCount(1);
    }

    public function test_address_discovery_hydrates_assets_for_the_matching_portal_list_record(): void
    {
        $metadata = $this->metadata();
        // Keep the documented full address in the fixture too: the matcher must
        // choose this record rather than blindly take the first search result.
        $metadata['property']['fullAddress'] = '5506 Burkittsville Rd, Burkittsville, MD 21755';
        $neighbour = $metadata;
        $neighbour['id'] = 'igNEIGHBOUR';
        $neighbour['alias'] = '5510_burkittsville_rd_burkittsville_md';
        $neighbour['property']['house'] = '5510';
        $neighbour['property']['fullAddress'] = '5510 Burkittsville Rd, Burkittsville, MD 21755';
        Http::fake([
            self::PORTAL.'/iguides?*' => Http::response(['iguides' => [$neighbour, $metadata]]),
            $this->propertyUrl().'/asset-urls' => Http::response($this->assets()),
        ]);

        $result = app(IguideService::class)->searchByAddress('5506 Burkittsville Road, Burkittsville, MD 21755');

        $this->assertNotNull($result);
        $this->assertSame(self::PROPERTY_ID, $result['property_id']);
        $this->assertSixFloorplans($result['floorplans']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === $this->propertyUrl().'/asset-urls');
    }

    public function test_asset_language_fallback_imports_jpg_and_pdf_when_english_is_absent(): void
    {
        $assets = ['languages' => ['fr' => $this->assets()['languages']['en']]];
        $assets['languages']['fr']['jpgMetric'][0]['floorName'] = 'Rez-de-chaussée';
        $assets['languages']['fr']['jpgImperial'][0]['floorName'] = 'Rez-de-chaussée';
        Http::fake([
            $this->propertyUrl() => Http::response($this->metadata()),
            $this->propertyUrl().'/asset-urls' => Http::response($assets),
        ]);

        $result = app(IguideService::class)->syncProperty(self::PROPERTY_ID);

        $this->assertNotNull($result);
        $this->assertSixFloorplans($result['floorplans']);
        $this->assertSame('Rez-de-chaussée', $result['jpg_metric'][0]['floor_name']);
        $this->assertSame('Rez-de-chaussée', $result['jpg_imperial'][0]['floor_name']);
        $this->assertSame($assets['languages']['fr']['pdfMetric'], $result['pdf_metric_url']);
        Http::assertSentCount(2);
    }

    public static function flatMediaContainers(): array
    {
        return [
            'root mediaUrls' => [false],
            'nested urls.mediaUrls' => [true],
        ];
    }

    #[DataProvider('flatMediaContainers')]
    public function test_flat_media_urls_parse_all_jpg_and_pdf_assets(bool $nested): void
    {
        $media = $this->assets()['languages']['en'];
        $payload = ['id' => self::PROPERTY_ID];
        if ($nested) {
            $payload['urls']['mediaUrls'] = $media;
        } else {
            $payload['mediaUrls'] = $media;
        }

        $result = app(IguideService::class)->parsePropertyData($payload);

        $this->assertSixFloorplans($result['floorplans']);
        $this->assertSame($media['pdfMetric'], $result['pdf_metric_url']);
        $this->assertSame($media['pdfImperial'], $result['pdf_imperial_url']);
        $this->assertSame('Main Floor', $result['jpg_metric'][0]['floor_name']);
        $this->assertCount(2, $result['jpg_imperial']);
        Http::assertNothingSent();
    }

    public static function topLevelTourUrls(): array
    {
        return [
            'no top-level tour URL' => [null],
            'explicit top-level tour URL' => ['https://youriguide.com/explicit-provider-view/'],
        ];
    }

    #[DataProvider('topLevelTourUrls')]
    public function test_alias_reconstructs_blank_tour_urls_but_preserves_an_explicit_top_level_tour_url(?string $tourUrl): void
    {
        $metadata = $this->metadata();
        $metadata['tourUrl'] = $tourUrl;
        $metadata['urls'] = ['publicUrl' => null, 'unbrandedUrl' => '', 'embeddedUrl' => null];
        Http::fake([
            $this->propertyUrl() => Http::response($metadata),
            $this->propertyUrl().'/asset-urls' => Http::response($this->assets()),
        ]);

        $result = app(IguideService::class)->syncProperty(self::PROPERTY_ID);

        $this->assertNotNull($result);
        $this->assertSame($tourUrl ?? 'https://youriguide.com/'.self::ALIAS.'/', $result['tour_url']);
        $this->assertSame('https://unbranded.youriguide.com/'.self::ALIAS.'/', $result['unbranded_url']);
        $this->assertSame('https://youriguide.com/embed/'.self::ALIAS.'/', $result['embedded_url']);
        $this->assertSixFloorplans($result['floorplans']);
        Http::assertSentCount(2);
    }

    public function test_switching_property_id_does_not_retain_previous_provider_assets_or_access_token(): void
    {
        $offlinePackage = ['id' => 'existing-private-package', 'storage_path' => 'offline/package.zip'];
        $shoot = $this->shootWithFloorplan([
            'iguide_property_id' => self::PROPERTY_ID,
            'iguide_floorplans' => [[
                'asset_key' => 'pdf_imperial',
                'url' => 'https://youriguide.com/previous/doc/floorplan_imperial.pdf',
            ]],
            'iguide_data' => [
                'property_id' => self::PROPERTY_ID,
                'authtoken' => 'previous-property-access-token',
                'pdf_imperial_url' => 'https://youriguide.com/previous/doc/floorplan_imperial.pdf',
                'jpg_imperial' => [['id' => 1, 'url' => 'https://youriguide.com/previous/doc/main.jpg']],
                'manual_offline_package' => $offlinePackage,
            ],
        ]);
        $service = app(IguideService::class);

        $service->applyShootData($shoot, $service->parsePropertyData(['id' => 'igNEWPROPERTY']));

        $shoot->refresh();
        $this->assertSame('igNEWPROPERTY', $shoot->iguide_property_id);
        $this->assertSame('igNEWPROPERTY', $shoot->iguide_data['property_id']);
        $this->assertEmpty($shoot->iguide_floorplans);
        foreach (['authtoken', 'pdf_imperial_url', 'jpg_imperial'] as $key) {
            $this->assertArrayNotHasKey($key, $shoot->iguide_data, 'Previous property data was retained: '.$key);
        }
        $this->assertSame($offlinePackage, $shoot->iguide_data['manual_offline_package']);
        Http::assertNothingSent();
    }

    public static function assetFailures(): array
    {
        return [
            'provider service unavailable' => [false],
            'provider connection timeout' => [true],
        ];
    }

    #[DataProvider('assetFailures')]
    public function test_partial_metadata_during_asset_failure_preserves_attached_deliverables(bool $connectionFails): void
    {
        $floorplans = [[
            'asset_key' => 'pdf_imperial',
            'url' => 'https://youriguide.com/previous/doc/floorplan_imperial.pdf',
            'filename' => 'floorplan_imperial.pdf',
            'label' => 'Floor Plan (Imperial)',
            'type' => 'pdf',
            'units' => 'imperial',
        ]];
        $existingProviderData = [
            'property_id' => self::PROPERTY_ID,
            'tour_url' => 'https://youriguide.com/previous/',
            'unbranded_url' => 'https://unbranded.youriguide.com/previous/',
            'pdf_imperial_url' => $floorplans[0]['url'],
            'jpg_imperial' => [['id' => 1, 'floor_name' => 'Main Floor', 'url' => 'https://youriguide.com/previous/doc/main.jpg']],
            'billing' => ['iguideType' => 'premium'],
            'manual_offline_package' => ['id' => 'existing-private-package'],
        ];
        $shoot = $this->shootWithFloorplan([
            'iguide_property_id' => self::PROPERTY_ID,
            'iguide_tour_url' => $existingProviderData['tour_url'],
            'iguide_floorplans' => $floorplans,
            'iguide_data' => $existingProviderData,
            'tour_links' => [
                'iguide_branded' => $existingProviderData['tour_url'],
                'iguide_mls' => $existingProviderData['unbranded_url'],
                'iguide_mls_source' => 'unbranded_url',
            ],
        ]);
        Http::fake([
            $this->propertyUrl() => Http::response(['id' => self::PROPERTY_ID]),
            $this->propertyUrl().'/asset-urls' => function () use ($connectionFails) {
                if ($connectionFails) {
                    throw new ConnectionException('Synthetic provider timeout');
                }

                return Http::response(['message' => 'Temporarily unavailable'], 503);
            },
        ]);

        $result = app(IguideService::class)->syncShoot($shoot);

        $this->assertNotNull($result, 'A failed asset refresh must retain the successfully read property metadata.');
        $shoot->refresh();
        $this->assertSame($floorplans, $shoot->iguide_floorplans);
        $this->assertSame($existingProviderData['tour_url'], $shoot->iguide_tour_url);
        foreach ($existingProviderData as $key => $value) {
            $this->assertSame($value, $shoot->iguide_data[$key] ?? null, 'Provider data was lost: '.$key);
        }
        $this->assertSame($existingProviderData['tour_url'], $shoot->tour_links['iguide_branded']);
        $this->assertSame($existingProviderData['unbranded_url'], $shoot->tour_links['iguide_mls']);
        $this->assertNotNull($shoot->iguide_last_synced_at);
    }

    public function test_metadata_alias_refresh_preserves_saved_tour_access_tokens_and_view_fragments(): void
    {
        $tourUrl = 'https://youriguide.com/'.self::ALIAS.'/?accessToken=fixture-access-token#default-view';
        $unbrandedUrl = 'https://unbranded.youriguide.com/'.self::ALIAS.'/?accessToken=fixture-access-token#default-view';
        $embeddedUrl = 'https://youriguide.com/embed/'.self::ALIAS.'/?accessToken=fixture-access-token#default-view';
        $floorplans = [[
            'asset_key' => 'pdf_imperial',
            'url' => 'https://youriguide.com/'.self::ALIAS.'/doc/floorplan_imperial.pdf?accessToken=fixture-access-token',
        ]];
        $shoot = $this->shootWithFloorplan([
            'iguide_property_id' => self::PROPERTY_ID,
            'iguide_tour_url' => $tourUrl,
            'iguide_floorplans' => $floorplans,
            'iguide_data' => [
                'property_id' => self::PROPERTY_ID,
                'iguide_alias' => self::ALIAS,
                'authtoken' => 'fixture-access-token',
                'tour_url' => $tourUrl,
                'unbranded_url' => $unbrandedUrl,
                'embedded_url' => $embeddedUrl,
            ],
            'tour_links' => [
                'iguide_branded' => $tourUrl,
                'iguide_mls' => $unbrandedUrl,
                'iguide_mls_source' => 'unbranded_url',
            ],
        ]);
        Http::fake([
            $this->propertyUrl() => Http::response($this->metadata()),
            $this->propertyUrl().'/asset-urls' => Http::response(['message' => 'Temporarily unavailable'], 503),
        ]);

        $result = app(IguideService::class)->syncShoot($shoot);

        $this->assertNotNull($result);
        $shoot->refresh();
        $this->assertSame($tourUrl, $shoot->iguide_tour_url);
        $this->assertSame($tourUrl, $shoot->iguide_data['tour_url']);
        $this->assertSame($unbrandedUrl, $shoot->iguide_data['unbranded_url']);
        $this->assertSame($embeddedUrl, $shoot->iguide_data['embedded_url']);
        $this->assertSame('fixture-access-token', $shoot->iguide_data['authtoken']);
        $this->assertSame($tourUrl, $shoot->tour_links['iguide_branded']);
        $this->assertSame($unbrandedUrl, $shoot->tour_links['iguide_mls']);
        $this->assertSame($floorplans, $shoot->iguide_floorplans);
        Http::assertSentCount(2);
    }

    private function propertyUrl(): string
    {
        return self::PORTAL.'/iguides/'.self::PROPERTY_ID;
    }

    private function metadata(): array
    {
        // The Portal detail endpoint returns metadata, unlike the rich ready webhook.
        return [
            'id' => self::PROPERTY_ID,
            'alias' => self::ALIAS,
            'type' => 'premium',
            'property' => [
                'house' => '5506',
                'street' => 'Burkittsville Rd',
                'city' => 'Burkittsville',
                'province' => 'MD',
                'code' => '21755',
                'country' => 'US',
            ],
            'views' => [
                ['id' => 'other-view', 'isDefault' => false, 'expiresAt' => null],
                ['id' => 'default-view', 'isDefault' => true, 'expiresAt' => null],
            ],
        ];
    }

    private function assets(): array
    {
        $base = 'https://youriguide.com/'.self::ALIAS.'/doc/';

        return ['languages' => ['en' => [
            'pdfMetric' => $base.'floorplan_metric.pdf',
            'pdfImperial' => $base.'floorplan_imperial.pdf',
            'jpgMetric' => [
                ['id' => 1, 'floorName' => 'Main Floor', 'url' => $base.'floor_metric_1.jpg'],
                ['id' => 2, 'floorName' => 'Second Floor', 'url' => $base.'floor_metric_2.jpg'],
            ],
            'jpgImperial' => [
                ['id' => 1, 'floorName' => 'Main Floor', 'url' => $base.'floor_imperial_1.jpg'],
                ['id' => 2, 'floorName' => 'Second Floor', 'url' => $base.'floor_imperial_2.jpg'],
            ],
        ]]];
    }

    private function assertSixFloorplans(array $floorplans): void
    {
        $this->assertCount(6, $floorplans);
        $this->assertEqualsCanonicalizing([
            'pdf_metric', 'pdf_imperial',
            'jpg_metric_floor_1', 'jpg_metric_floor_2',
            'jpg_imperial_floor_1', 'jpg_imperial_floor_2',
        ], array_column($floorplans, 'asset_key'));
        $this->assertCount(2, array_filter($floorplans, fn (array $floorplan) => $floorplan['type'] === 'pdf'));
        $this->assertCount(4, array_filter($floorplans, fn (array $floorplan) => $floorplan['type'] === 'jpg'));
    }

    private function shootWithFloorplan(array $attributes = []): Shoot
    {
        $service = Service::factory()->noIntake()->create(['name' => 'Premium iGuide']);

        return Shoot::factory()->create(array_merge([
            'service_id' => $service->id,
            'address' => '5506 Burkittsville Rd',
            'city' => 'Burkittsville',
            'state' => 'MD',
            'zip' => '21755',
        ], $attributes));
    }
}
