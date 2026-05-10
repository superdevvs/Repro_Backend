<?php

namespace Tests\Unit;

use App\Services\IguideService;
use Tests\TestCase;

class IguideServiceParsePropertyDataTest extends TestCase
{
    private function sampleReadyEvent(): array
    {
        // Trimmed-down sample mirroring the documented iGUIDE Portal `ready` payload
        // at https://docs.youriguide.com/docs/integrations/webhooks
        return [
            'type' => 'ready',
            'iguideId' => 'igYGFV5GG6V8DD1',
            'defaultViewId' => 'vXY7P2R1A',
            'iguideAlias' => 'api-docs-sample',
            'workOrderId' => 'WO1234',
            'authtoken' => 'secret-token',
            'urls' => [
                'publicUrl' => 'https://youriguide.com/api-docs-sample/',
                'unbrandedUrl' => 'https://unbranded.youriguide.com/api-docs-sample/',
                'embeddedUrl' => 'https://youriguide.com/embed/api-docs-sample/',
                'manageUrl' => 'https://manage.youriguide.com/iguides/edit/igYGFV5GG6V8DD1',
                'mediaUrls' => [
                    'en' => [
                        'galleryFrontImage' => 'https://youriguide.com/api-docs-sample/doc/front.image',
                        'pdfMetric' => 'https://youriguide.com/api-docs-sample/doc/floorplan_metric_en.pdf',
                        'pdfImperial' => 'https://youriguide.com/api-docs-sample/doc/floorplan_imperial_en.pdf',
                        'galleryZip' => 'https://youriguide.com/api-docs-sample/doc/gallery.zip',
                        'sphereZip' => 'https://youriguide.com/api-docs-sample/doc/spheres.zip',
                        'embedImage' => 'https://youriguide.com/api-docs-sample/doc/embed_preview.jpg',
                        'jpgMetric' => [
                            ['id' => 1, 'floorName' => 'Main Floor', 'url' => 'https://youriguide.com/api-docs-sample/doc/floor_metric_en_1.jpg'],
                        ],
                        'jpgImperial' => [
                            ['id' => 1, 'floorName' => 'Main Floor', 'url' => 'https://youriguide.com/api-docs-sample/doc/floor_imperial_en_1.jpg'],
                        ],
                    ],
                ],
            ],
            'property' => [
                'fullAddress' => '301-301 King St E, Kitchener, ON',
                'country' => 'CA',
                'postalCode' => 'N2G 2L3',
                'stateProvince' => 'ON',
                'city' => 'Kitchener',
                'streetName' => 'King St E',
                'streetNumber' => '301',
                'unit' => '301',
                'location' => ['lat' => 43.447568, 'lng' => -80.484302],
            ],
            'billingInfo' => [
                'iguideType' => 'standard',
                'package' => '',
                'addons' => ['vr', 'advmeas'],
                'billableAreaSqFeet' => 4214.89,
                'billableAreaSqMeters' => 391.57,
            ],
        ];
    }

    public function test_parses_ready_event_into_normalized_shape(): void
    {
        $service = new IguideService();
        $parsed = $service->parsePropertyData($this->sampleReadyEvent());

        $this->assertSame('igYGFV5GG6V8DD1', $parsed['property_id']);
        $this->assertSame('WO1234', $parsed['work_order_id']);
        $this->assertSame('https://youriguide.com/api-docs-sample/?accessToken=secret-token', $parsed['tour_url']);
        $this->assertSame('https://unbranded.youriguide.com/api-docs-sample/?accessToken=secret-token', $parsed['unbranded_url']);
        $this->assertSame('https://youriguide.com/embed/api-docs-sample/?accessToken=secret-token', $parsed['embedded_url']);
        $this->assertSame('https://manage.youriguide.com/iguides/edit/igYGFV5GG6V8DD1', $parsed['manage_url']);
        $this->assertStringContainsString('floorplan_metric_en.pdf', (string) $parsed['pdf_metric_url']);
        $this->assertStringContainsString('floorplan_imperial_en.pdf', (string) $parsed['pdf_imperial_url']);
        $this->assertStringContainsString('embed_preview.jpg', (string) $parsed['embed_image_url']);
        $this->assertSame('301-301 King St E, Kitchener, ON', $parsed['address']);

        $this->assertIsArray($parsed['billing']);
        $this->assertSame('standard', $parsed['billing']['iguideType']);
        $this->assertContains('vr', $parsed['billing']['addons']);
        $this->assertEqualsWithDelta(4214.89, $parsed['billing']['billableAreaSqFeet'], 0.01);

        $this->assertCount(1, $parsed['jpg_metric']);
        $this->assertSame('Main Floor', $parsed['jpg_metric'][0]['floor_name']);

        $this->assertGreaterThanOrEqual(4, count($parsed['floorplans']));
        $assetKeys = array_column($parsed['floorplans'], 'asset_key');
        $this->assertContains('pdf_metric', $assetKeys);
        $this->assertContains('pdf_imperial', $assetKeys);
        $this->assertContains('jpg_metric_floor_1', $assetKeys);
        $this->assertContains('jpg_imperial_floor_1', $assetKeys);

        // Access tokens are appended only to youriguide.com URLs.
        foreach ($parsed['floorplans'] as $fp) {
            $this->assertStringContainsString('accessToken=secret-token', $fp['url']);
        }
    }

    public function test_handles_missing_optional_fields(): void
    {
        $service = new IguideService();
        $parsed = $service->parsePropertyData([
            'iguideId' => 'igAB123',
            'urls' => [
                'publicUrl' => 'https://youriguide.com/abc/',
            ],
        ]);

        $this->assertSame('igAB123', $parsed['property_id']);
        $this->assertSame('https://youriguide.com/abc/', $parsed['tour_url']);
        $this->assertNull($parsed['work_order_id']);
        $this->assertSame([], $parsed['floorplans']);
        $this->assertNull($parsed['billing']);
    }
}
