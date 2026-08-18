<?php

namespace Tests\Feature;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Service;
use App\Models\Shoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CubiCasaWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = '9ba65f04-3ee2-4de9-a098-ece787ceee57';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.owner_email', 'orders@reprophotos.com');
        config()->set('services.cubicasa.base_url', 'https://app.cubi.casa/api/integrate/v3');
        config()->set('services.cubicasa.webhook_secret', null);
    }

    private function attachCubicasaService(Shoot $shoot): void
    {
        $service = Service::factory()->create(['name' => '2D Floor plans']);
        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 195,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeOrderDetail(): array
    {
        return [
            'id' => self::ORDER_ID,
            'info' => [
                'status' => 'Ready',
                'order_type' => 'Tier3-LiDAR',
                'first_delivered_at' => 1778406163.891521,
                'external_id' => null,
            ],
            'address' => [
                'full_address' => '521 Brightfield Road, Lutherville Timonium, MD 21093',
                'street' => 'Brightfield Road',
                'city' => 'Lutherville Timonium',
                'state' => 'Maryland',
                'postalCode' => '21093',
                'country' => 'United States',
            ],
            'delivery_assets' => [
                'listing_floorplans' => [
                    'pdf_urls_dim' => ['https://s3.example.com/521-merged-dim.pdf'],
                    'pdf_urls' => ['https://s3.example.com/521-merged.pdf'],
                    'jpg_urls_dim' => ['https://s3.example.com/floor-1-dim.jpg'],
                    'jpg_urls' => [],
                    'png_urls' => [],
                    'svg_urls' => [],
                ],
                'home_report' => ['pdf_urls' => ['https://s3.example.com/home-report.pdf']],
                'tour' => [
                    'link' => 'https://visithome.ai/abc?mu=ft',
                    'mls_compliance_link' => 'https://unbranded.visithome.ai/abc?mu=ft',
                    'type' => 'floorplan_tour',
                ],
            ],
        ];
    }

    private function buildWebhookPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => self::ORDER_ID,
            'current_status' => 'Ready',
            'previous_status' => 'Pending',
            'product_type' => 'products_2d',
            'delivery_type' => 'moved_to_ready',
        ], $overrides);
    }

    public function test_matches_shoot_by_order_id_and_dispatches_ingestion(): void
    {
        Queue::fake();
        Http::fake([
            'https://app.cubi.casa/api/integrate/v3/orders/' . self::ORDER_ID
                => Http::response($this->fakeOrderDetail(), 200),
        ]);

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
            'address' => '521 Brightfield Road',
        ]);
        $this->attachCubicasaService($shoot);

        $response = $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload());
        $response->assertStatus(200)->assertJsonPath('success', true);

        $shoot->refresh();
        $this->assertSame('Ready', $shoot->cubicasa_status);
        $this->assertSame('Tier3-LiDAR', $shoot->cubicasa_product_type);
        $this->assertSame('https://visithome.ai/abc?mu=ft', $shoot->cubicasa_tour_url);
        $this->assertNotNull($shoot->cubicasa_last_synced_at);

        Queue::assertPushed(IngestCubiCasaAssetsJob::class, function ($job) use ($shoot) {
            return $job->shootId === $shoot->id && !empty($job->floorplans);
        });
    }

    public function test_returns_200_when_no_shoot_matches(): void
    {
        Queue::fake();

        $response = $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload([
            'id' => 'unknown-order-id',
        ]));
        $response->assertStatus(200)->assertJsonPath('success', false);

        Queue::assertNothingPushed();
    }

    public function test_skips_ingestion_when_shoot_has_no_eligible_service(): void
    {
        Queue::fake();
        Http::fake([
            'https://app.cubi.casa/api/integrate/v3/orders/' . self::ORDER_ID
                => Http::response($this->fakeOrderDetail(), 200),
        ]);

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
        ]);
        // No CubiCasa service attached.

        $response = $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload());
        $response->assertStatus(200)->assertJsonPath('success', true);

        Queue::assertNotPushed(IngestCubiCasaAssetsJob::class);

        // Light metadata still applied.
        $shoot->refresh();
        $this->assertSame('Ready', $shoot->cubicasa_status);
    }

    public function test_skips_asset_fetch_for_non_ready_status(): void
    {
        Queue::fake();
        Http::fake();

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
        ]);
        $this->attachCubicasaService($shoot);

        $response = $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload([
            'current_status' => 'Pending',
            'delivery_type' => 'moved_to_pending',
        ]));
        $response->assertStatus(200)->assertJsonPath('success', true);

        $shoot->refresh();
        $this->assertSame('Pending', $shoot->cubicasa_status);
        $this->assertNull($shoot->cubicasa_tour_url); // No outbound fetch happened.
        Http::assertNothingSent();
        Queue::assertNotPushed(IngestCubiCasaAssetsJob::class);
    }

    public function test_idempotent_replay_returns_duplicate_message(): void
    {
        Queue::fake();
        Http::fake([
            'https://app.cubi.casa/api/integrate/v3/orders/' . self::ORDER_ID
                => Http::response($this->fakeOrderDetail(), 200),
        ]);

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
        ]);
        $this->attachCubicasaService($shoot);

        $payload = $this->buildWebhookPayload();
        $this->postJson('/cubicasa_webhook.php', $payload)->assertStatus(200);
        $this->postJson('/cubicasa_webhook.php', $payload)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Duplicate event ignored');
    }

    public function test_does_not_promote_visithome_links_into_tour_links(): void
    {
        // CubiCasa is treated as a floor-plan provider only. Even if the order
        // payload includes a visithome.ai tour, we keep it in cubicasa_data
        // for reference but never write it to the managed tour_links slots.
        Queue::fake();
        Http::fake([
            'https://app.cubi.casa/api/integrate/v3/orders/' . self::ORDER_ID
                => Http::response($this->fakeOrderDetail(), 200),
        ]);

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
        ]);
        $this->attachCubicasaService($shoot);

        $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload());

        $shoot->refresh();
        $tourLinks = is_array($shoot->tour_links) ? $shoot->tour_links : [];
        $this->assertArrayNotHasKey('cubicasa_branded', $tourLinks);
        $this->assertArrayNotHasKey('cubicasa_mls', $tourLinks);
        // The raw tour data is still preserved for callers who want it.
        $cubicasaData = is_array($shoot->cubicasa_data) ? $shoot->cubicasa_data : [];
        $this->assertSame('https://visithome.ai/abc?mu=ft', $cubicasaData['tour']['link'] ?? null);
    }
}
