<?php

namespace Tests\Unit;

use App\Services\CubiCasaService;
use Tests\TestCase;

class CubiCasaServiceParseOrderDataTest extends TestCase
{
    /**
     * Trimmed-down representation of a real `GET /orders/{id}` payload from the
     * CubiCasa Integrate API v3 (verified live against the Brightfield order).
     */
    private function sampleReadyOrder(): array
    {
        return [
            'id' => '9ba65f04-3ee2-4de9-a098-ece787ceee57',
            'info' => [
                'status' => 'Ready',
                'order_type' => 'Tier3-LiDAR',
                'first_delivered_at' => 1778406163.891521,
                'created_at' => 1778341836.708469,
                'is_scan_order' => true,
                'external_id' => null,
                'product' => ['package_type' => 'plus', 'add_ons' => []],
            ],
            'address' => [
                'full_address' => '521 Brightfield Road, Lutherville Timonium, MD 21093, United States',
                'street' => 'Brightfield Road',
                'number' => '521',
                'city' => 'Lutherville Timonium',
                'state' => 'Maryland',
                'postalCode' => '21093',
                'country' => 'United States',
                'latitude' => 39.40732,
                'longitude' => -76.66468,
            ],
            'delivery_assets' => [
                'listing_floorplans' => [
                    'jpg_urls' => [
                        'https://s3-us-west-2.amazonaws.com/example/0_521-brightfield_0.jpg',
                        'https://s3-us-west-2.amazonaws.com/example/0_521-brightfield_1.jpg',
                    ],
                    'jpg_urls_dim' => [
                        'https://s3-us-west-2.amazonaws.com/example-dim/0_521-brightfield_0.jpg',
                        'https://s3-us-west-2.amazonaws.com/example-dim/0_521-brightfield_1.jpg',
                    ],
                    'png_urls' => ['https://s3.example.com/0_521.png'],
                    'svg_urls' => [],
                    'pdf_urls' => ['https://s3.example.com/521-merged.pdf'],
                    'pdf_urls_dim' => ['https://s3.example.com/521-merged-dim.pdf'],
                    'zip_urls' => [],
                    'zip_urls_dim' => [],
                ],
                'home_report' => [
                    'pdf_urls' => ['https://s3.example.com/521-home-report.pdf'],
                ],
                'tour' => [
                    'link' => 'https://visithome.ai/KYMfzsb9AWKpqemFRkS3s6?mu=ft',
                    'mls_compliance_link' => 'https://unbranded.visithome.ai/KYMfzsb9AWKpqemFRkS3s6?mu=ft',
                    'type' => 'floorplan_tour',
                ],
                'floorplans_3d' => null,
                'video_3d' => null,
                'gla_package' => null,
                'snapshot' => ['image_urls' => null, 'pdf_urls' => null],
                'property_data' => ['json_urls' => null],
                'cad_files' => null,
            ],
        ];
    }

    public function test_parses_ready_order_into_normalized_shape(): void
    {
        $service = new CubiCasaService();
        $parsed = $service->parseOrderData($this->sampleReadyOrder());

        $this->assertSame('9ba65f04-3ee2-4de9-a098-ece787ceee57', $parsed['order_id']);
        $this->assertSame('Ready', $parsed['status']);
        $this->assertSame('Tier3-LiDAR', $parsed['product_type']);
        $this->assertNull($parsed['external_id']);
        $this->assertSame('https://visithome.ai/KYMfzsb9AWKpqemFRkS3s6?mu=ft', $parsed['tour_url']);
        $this->assertSame('https://visithome.ai/KYMfzsb9AWKpqemFRkS3s6?mu=ft', $parsed['tour']['link']);
        $this->assertSame('https://unbranded.visithome.ai/KYMfzsb9AWKpqemFRkS3s6?mu=ft', $parsed['tour']['mls_compliance_link']);
        $this->assertSame('521 Brightfield Road, Lutherville Timonium, MD 21093, United States', $parsed['address']['full']);
        $this->assertSame('21093', $parsed['address']['postal_code']);

        // listing_floorplans broken out by format for the UI.
        $this->assertCount(1, $parsed['listing_floorplans']['pdf_dim']);
        $this->assertCount(1, $parsed['listing_floorplans']['pdf_plain']);
        $this->assertCount(2, $parsed['listing_floorplans']['jpg_dim']);
        $this->assertCount(1, $parsed['home_report_pdfs']);
    }

    public function test_floorplan_keeplist_skips_png_svg_and_plain_jpgs(): void
    {
        $service = new CubiCasaService();
        $parsed = $service->parseOrderData($this->sampleReadyOrder());

        // Per the plan keeplist: 1 dim PDF + 1 plain PDF + 2 dim JPGs + 1 home report = 5 ingestion items.
        $this->assertCount(5, $parsed['floorplans']);

        $keys = array_column($parsed['floorplans'], 'asset_key');
        $this->assertContains('pdf_listing_dim_0', $keys);
        $this->assertContains('pdf_listing_0', $keys);
        $this->assertContains('jpg_listing_dim_0', $keys);
        $this->assertContains('jpg_listing_dim_1', $keys);
        $this->assertContains('pdf_home_report_0', $keys);

        // PNG/SVG/plain-JPG explicitly excluded.
        foreach ($keys as $k) {
            $this->assertStringNotContainsString('png', $k);
            $this->assertStringNotContainsString('svg', $k);
            $this->assertStringNotContainsString('jpg_listing_0', $k);
        }
    }

    public function test_handles_missing_optional_fields_gracefully(): void
    {
        $service = new CubiCasaService();
        $parsed = $service->parseOrderData([
            'id' => 'abc',
            'info' => ['status' => 'Pending'],
            'address' => [],
            'delivery_assets' => [],
        ]);

        $this->assertSame('abc', $parsed['order_id']);
        $this->assertSame('Pending', $parsed['status']);
        $this->assertNull($parsed['tour_url']);
        $this->assertNull($parsed['tour']);
        $this->assertEmpty($parsed['floorplans']);
    }
}
