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
use Tests\Support\SignsInboundWebhooks;
use Tests\TestCase;

class CubiCasaWebhookControllerTest extends TestCase
{
    use RefreshDatabase;
    use SignsInboundWebhooks;

    private const ORDER_ID = '9ba65f04-3ee2-4de9-a098-ece787ceee57';

    private const WEBHOOK_SECRET = 'cubicasa-test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.owner_email', 'orders@reprophotos.com');
        config()->set('services.cubicasa.base_url', 'https://app.cubi.casa/api/integrate/v3');
        config()->set('services.cubicasa.webhook_secret', self::WEBHOOK_SECRET);
    }

    private function postWebhook(array $payload)
    {
        return $this->postJsonWithHmac(
            '/cubicasa_webhook.php',
            $payload,
            self::WEBHOOK_SECRET,
            'X-Cubicasa-Signature',
        );
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
                'created_at' => '2026-10-01T14:00:00Z',
                'external_id' => null,
            ],
            'address' => [
                'full_address' => '521 Brightfield Road, Lutherville Timonium, MD 21093',
                'number' => '521',
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

        $response = $this->postWebhook($this->buildWebhookPayload());
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
        Http::fake(['*' => Http::response(array_replace($this->fakeOrderDetail(), ['id' => 'unknown-order-id']), 200)]);

        $response = $this->postWebhook($this->buildWebhookPayload([
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

        $response = $this->postWebhook($this->buildWebhookPayload());
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

        $response = $this->postWebhook($this->buildWebhookPayload([
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

    public function test_identical_ready_events_are_checked_again_so_quick_edits_are_not_suppressed(): void
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
        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Webhook processed');
        Http::assertSentCount(2);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, 2);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, fn ($job) => $job->refreshExisting);
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

        $this->postWebhook($this->buildWebhookPayload());

        $shoot->refresh();
        $tourLinks = is_array($shoot->tour_links) ? $shoot->tour_links : [];
        $this->assertArrayNotHasKey('cubicasa_branded', $tourLinks);
        $this->assertArrayNotHasKey('cubicasa_mls', $tourLinks);
        // The raw tour data is still preserved for callers who want it.
        $cubicasaData = is_array($shoot->cubicasa_data) ? $shoot->cubicasa_data : [];
        $this->assertSame('https://visithome.ai/abc?mu=ft', $cubicasaData['tour']['link'] ?? null);
    }

    public function test_missing_all_credentials_rejects_webhook_and_does_not_process(): void
    {
        config()->set('services.cubicasa.webhook_secret', '');
        config()->set('services.cubicasa.api_key', '');
        Queue::fake();
        Http::fake();

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
        ]);
        $this->attachCubicasaService($shoot);

        $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload())
            ->assertStatus(503);

        $this->assertNull($shoot->fresh()->cubicasa_status);
        Queue::assertNothingPushed();
    }

    public function test_missing_signature_is_rejected_when_secret_is_configured(): void
    {
        Queue::fake();
        Http::fake();

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
        ]);
        $this->attachCubicasaService($shoot);

        $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload())
            ->assertStatus(401);

        $this->assertNull($shoot->fresh()->cubicasa_status);
        Queue::assertNothingPushed();
    }

    public function test_invalid_signature_is_rejected(): void
    {
        Queue::fake();
        Http::fake();

        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::ORDER_ID,
        ]);
        $this->attachCubicasaService($shoot);

        $this->withHeader('X-Cubicasa-Signature', 'sha256=deadbeef')
            ->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload())
            ->assertStatus(401);

        $this->assertNull($shoot->fresh()->cubicasa_status);
        Queue::assertNothingPushed();
    }

    public function test_documented_api_key_authentication_works_without_hmac_secret(): void
    {
        config()->set('services.cubicasa.webhook_secret', '');
        Queue::fake();
        Http::fake();
        $shoot = Shoot::factory()->create(['cubicasa_order_id' => self::ORDER_ID]);

        $this->withHeader('api-key', 'test-key')->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload([
            'current_status' => 'Pending', 'delivery_type' => 'moved_to_pending',
        ]))->assertOk()->assertJsonPath('success', true);

        $this->assertSame('Pending', $shoot->fresh()->cubicasa_status);
        Http::assertNothingSent();
    }

    public function test_api_key_only_configuration_rejects_missing_or_wrong_key(): void
    {
        config()->set('services.cubicasa.webhook_secret', '');
        Queue::fake();
        Http::fake();
        $shoot = Shoot::factory()->create(['cubicasa_order_id' => self::ORDER_ID]);

        $this->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload())->assertUnauthorized();
        $this->withHeader('api-key', 'wrong-key')->postJson('/cubicasa_webhook.php', $this->buildWebhookPayload())->assertUnauthorized();

        $this->assertNull($shoot->fresh()->cubicasa_status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_database_api_key_takes_precedence_over_environment_key(): void
    {
        config()->set('services.cubicasa.webhook_secret', '');
        Queue::fake();
        Http::fake();
        DB::table('settings')->insert([
            'key' => 'integrations.cubicasa', 'type' => 'json',
            'value' => json_encode(['apiKey' => 'database-key']),
        ]);
        $shoot = Shoot::factory()->create(['cubicasa_order_id' => self::ORDER_ID]);
        $payload = $this->buildWebhookPayload(['current_status' => 'Pending', 'delivery_type' => 'moved_to_pending']);

        $this->withHeader('api-key', 'test-key')->postJson('/cubicasa_webhook.php', $payload)->assertUnauthorized();
        $this->withHeader('api-key', 'database-key')->postJson('/cubicasa_webhook.php', $payload)
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame('Pending', $shoot->fresh()->cubicasa_status);
    }

    private function recoveryShoot(array $overrides = []): Shoot
    {
        $shoot = Shoot::factory()->create(array_replace([
            'address' => '521 Brightfield Rd', 'city' => 'Lutherville Timonium',
            'state' => 'MD', 'zip' => '21093', 'scheduled_at' => '2026-10-01 14:00:00',
            'cubicasa_order_id' => 'existing-draft', 'cubicasa_status' => 'Draft',
        ], $overrides));
        $this->attachCubicasaService($shoot);
        return $shoot;
    }

    public function test_status_only_ready_webhook_matches_separate_scan_by_exact_property_and_date(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->fakeOrderDetail(), 200)]);
        $shoot = $this->recoveryShoot();

        $this->postWebhook($this->buildWebhookPayload())->assertOk()->assertJsonPath('shoot_id', $shoot->id);

        $this->assertSame(self::ORDER_ID, $shoot->fresh()->cubicasa_order_id);
        $this->assertSame('Ready', $shoot->fresh()->cubicasa_status);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootId === $shoot->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('api-key', 'test-key'));
    }

    public function test_fetched_external_id_is_used_before_address_matching(): void
    {
        Queue::fake();
        $shoot = $this->recoveryShoot(['address' => 'A differently formatted provider address']);
        $raw = $this->fakeOrderDetail();
        $raw['info']['external_id'] = 'shoot-'.$shoot->id;
        Http::fake(['*' => Http::response($raw, 200)]);

        $this->postWebhook($this->buildWebhookPayload())->assertOk()->assertJsonPath('shoot_id', $shoot->id);

        $this->assertSame(self::ORDER_ID, $shoot->fresh()->cubicasa_order_id);
        $this->assertSame('shoot-'.$shoot->id, $shoot->fresh()->cubicasa_external_id);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_property_matches_are_left_untouched(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->fakeOrderDetail(), 200)]);
        $first = $this->recoveryShoot(['cubicasa_order_id' => 'draft-one']);
        $second = $this->recoveryShoot(['cubicasa_order_id' => 'draft-two']);

        $this->postWebhook($this->buildWebhookPayload())->assertOk()->assertJsonPath('success', false);

        $this->assertSame('draft-one', $first->fresh()->cubicasa_order_id);
        $this->assertSame('draft-two', $second->fresh()->cubicasa_order_id);
        Queue::assertNothingPushed();
    }

    public function test_property_match_outside_scan_date_window_is_left_untouched(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->fakeOrderDetail(), 200)]);
        $shoot = $this->recoveryShoot(['scheduled_at' => '2026-09-01 14:00:00']);

        $this->postWebhook($this->buildWebhookPayload())->assertOk()->assertJsonPath('success', false);

        $this->assertSame('existing-draft', $shoot->fresh()->cubicasa_order_id);
        Queue::assertNothingPushed();
    }

    public function test_external_id_cannot_replace_an_already_progressed_order(): void
    {
        Queue::fake();
        $shoot = $this->recoveryShoot(['cubicasa_status' => 'Processing']);
        $raw = $this->fakeOrderDetail();
        $raw['info']['external_id'] = 'shoot-'.$shoot->id;
        Http::fake(['*' => Http::response($raw, 200)]);

        $this->postWebhook($this->buildWebhookPayload())->assertOk()->assertJsonPath('success', false);

        $this->assertSame('existing-draft', $shoot->fresh()->cubicasa_order_id);
        $this->assertSame('Processing', $shoot->fresh()->cubicasa_status);
        Queue::assertNothingPushed();
    }

    public function test_conflicting_external_id_cannot_claim_an_exact_property_match(): void
    {
        Queue::fake();
        $shoot = $this->recoveryShoot(['cubicasa_external_id' => 'another-booking']);
        $raw = $this->fakeOrderDetail();
        $raw['info']['external_id'] = 'shoot-'.$shoot->id;
        Http::fake(['*' => Http::response($raw, 200)]);

        $this->postWebhook($this->buildWebhookPayload())->assertOk()->assertJsonPath('success', false);

        $this->assertSame('existing-draft', $shoot->fresh()->cubicasa_order_id);
        $this->assertSame('another-booking', $shoot->fresh()->cubicasa_external_id);
        Queue::assertNothingPushed();
    }

    public function test_unknown_ready_order_fetch_failure_is_not_cached_and_can_retry(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::sequence()->push([], 503)->push($this->fakeOrderDetail(), 200)]);
        $shoot = $this->recoveryShoot();
        $payload = $this->buildWebhookPayload();

        $this->postWebhook($payload)->assertStatus(503);
        $this->assertSame('existing-draft', $shoot->fresh()->cubicasa_order_id);
        $this->assertSame('Draft', $shoot->fresh()->cubicasa_status);
        $this->assertFalse(Cache::has('cubicasa:webhook:'.self::ORDER_ID.':Ready:moved_to_ready'));
        Queue::assertNothingPushed();

        $this->postWebhook($payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame(self::ORDER_ID, $shoot->fresh()->cubicasa_order_id);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class);
        Http::assertSentCount(2);
    }

    public function test_known_ready_order_fetch_failure_is_not_cached_and_can_retry(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::sequence()->push([], 503)->push($this->fakeOrderDetail(), 200)]);
        $shoot = $this->recoveryShoot(['cubicasa_order_id' => self::ORDER_ID]);
        $payload = $this->buildWebhookPayload();

        $this->postWebhook($payload)->assertStatus(503);
        $this->assertSame('Draft', $shoot->fresh()->cubicasa_status);
        $this->assertFalse(Cache::has('cubicasa:webhook:'.self::ORDER_ID.':Ready:moved_to_ready'));
        Queue::assertNothingPushed();

        $this->postWebhook($payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('Ready', $shoot->fresh()->cubicasa_status);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class);
        Http::assertSentCount(2);
    }
}
