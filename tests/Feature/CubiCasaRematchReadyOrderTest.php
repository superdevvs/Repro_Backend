<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CubiCasaRematchReadyOrderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const DRAFT_ID = 'a2403d4d-d027-4707-88bd-76f66232a4f5';
    private const READY_ID = 'b7d371bc-b640-433c-bcd8-baeea1c926ef';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
    }

    public function test_sync_rematches_empty_draft_to_ready_order_by_address(): void
    {
        $draft = [
            'id' => self::DRAFT_ID,
            'info' => ['external_id' => 'shoot-162', 'status' => 'Draft'],
            'address' => [
                'street' => '2361-73 Welsh Road',
                'full_address' => '2361-73 Welsh Road,Philadelphia,PA,United States',
                'city' => 'Philadelphia',
            ],
            'delivery_assets' => [
                'listing_floorplans' => [],
                'home_report' => [],
            ],
        ];

        $readyListRow = [
            'id' => self::READY_ID,
            'info' => ['status' => 'Ready', 'external_id' => null],
            'address' => [
                'street' => 'Welsh Road',
                'full_address' => '2361-73 Welsh Road, Pennypack, Philadelphia, Pennsylvania, United States, 19114',
                'city' => 'Philadelphia',
            ],
        ];

        $readyFull = [
            'id' => self::READY_ID,
            'info' => ['status' => 'Ready', 'external_id' => null, 'order_type' => 'Tier3-LiDAR'],
            'address' => $readyListRow['address'],
            'delivery_assets' => [
                'listing_floorplans' => [
                    'pdf_urls_dim' => ['https://assets.example/dim.pdf'],
                    'pdf_urls' => ['https://assets.example/plain.pdf'],
                    'jpg_urls_dim' => ['https://assets.example/floor1.jpg'],
                ],
                'home_report' => [
                    'pdf_urls' => ['https://assets.example/home.pdf'],
                ],
            ],
        ];

        Http::fake(function ($request) use ($draft, $readyListRow, $readyFull) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH) ?: '';

            if (str_ends_with($path, '/orders/' . self::DRAFT_ID)) {
                return Http::response($draft, 200);
            }

            if (str_ends_with($path, '/orders/' . self::READY_ID)) {
                return Http::response($readyFull, 200);
            }

            if (str_ends_with($path, '/orders')) {
                return Http::response([
                    'items' => [$readyListRow],
                    'pagination' => ['has_more' => false],
                ], 200);
            }

            return Http::response(['message' => 'unexpected ' . $url], 500);
        });

        $shoot = Shoot::factory()->create([
            'address' => '2361-73 Welsh Road',
            'city' => 'Philadelphia',
            'state' => 'PA',
            'zip' => '19114',
            'cubicasa_order_id' => self::DRAFT_ID,
            'cubicasa_external_id' => 'shoot-162',
            'cubicasa_status' => 'Draft',
            'cubicasa_floorplans' => [],
        ]);

        $parsed = app(CubiCasaService::class)->syncShoot($shoot);
        $fresh = $shoot->fresh();

        $this->assertNotNull($parsed);
        $this->assertSame(self::READY_ID, $fresh->cubicasa_order_id);
        $this->assertSame('Ready', $fresh->cubicasa_status);
        $this->assertCount(4, $fresh->cubicasa_floorplans);
        $this->assertSame('shoot-162', $fresh->cubicasa_external_id);
    }
}
