<?php

namespace Tests\Feature;

use App\Jobs\IngestIguideAssetsJob;
use App\Models\Service;
use App\Models\Shoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IguideWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function attachIguideService(Shoot $shoot): void
    {
        $service = Service::factory()->create(['name' => 'Premium iGuide w/ 2D Floor plans']);
        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 185,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'type' => 'ready',
            'iguideId' => 'igTEST001',
            'workOrderId' => 'WO-TEST-1',
            'authtoken' => 'tok',
            'urls' => [
                'publicUrl' => 'https://youriguide.com/iguide-test/',
                'unbrandedUrl' => 'https://unbranded.youriguide.com/iguide-test/',
                'mediaUrls' => [
                    'en' => [
                        'pdfImperial' => 'https://youriguide.com/iguide-test/doc/floorplan_imperial.pdf',
                    ],
                ],
            ],
            'property' => [
                'fullAddress' => '123 Sample Ave, Springfield, MD',
            ],
        ], $overrides);
    }

    public function test_matches_shoot_by_work_order_id_and_dispatches_ingestion(): void
    {
        Queue::fake();

        $shoot = Shoot::factory()->create([
            'iguide_work_order_id' => 'WO-TEST-1',
            'address' => '999 Other Rd',
        ]);
        $this->attachIguideService($shoot);

        $response = $this->postJson('/iguide_webhook.php', $this->buildPayload());
        $response->assertStatus(200)->assertJsonPath('success', true);

        $shoot->refresh();
        $this->assertSame('igTEST001', $shoot->iguide_property_id);
        $this->assertSame('WO-TEST-1', $shoot->iguide_work_order_id);
        $this->assertNotNull($shoot->iguide_tour_url);

        Queue::assertPushed(IngestIguideAssetsJob::class, function ($job) use ($shoot) {
            return $job->shootId === $shoot->id && !empty($job->floorplans);
        });
    }

    public function test_falls_back_to_address_match_when_work_order_unknown(): void
    {
        Queue::fake();

        $shoot = Shoot::factory()->create([
            'address' => '123 Sample Ave',
            'city' => 'Springfield',
            'state' => 'MD',
            'iguide_work_order_id' => null,
        ]);

        $response = $this->postJson('/iguide_webhook.php', $this->buildPayload([
            'workOrderId' => 'WO-UNKNOWN',
        ]));
        $response->assertStatus(200)->assertJsonPath('success', true);

        $shoot->refresh();
        $this->assertSame('igTEST001', $shoot->iguide_property_id);
    }

    public function test_returns_200_when_no_shoot_matches(): void
    {
        Queue::fake();

        $response = $this->postJson('/iguide_webhook.php', $this->buildPayload([
            'workOrderId' => 'WO-NONE',
            'iguideId' => 'igNONE',
            'property' => ['fullAddress' => 'Nowhere'],
        ]));

        // 200 (not 404/5xx) so iGuide does not retry.
        $response->assertStatus(200);
        Queue::assertNotPushed(IngestIguideAssetsJob::class);
    }

    public function test_skips_asset_ingestion_when_no_floorplan_service_booked(): void
    {
        Queue::fake();

        // Shoot exists and matches by work order, but it has NO floorplan/iGuide service.
        $shoot = Shoot::factory()->create([
            'iguide_work_order_id' => 'WO-TEST-1',
            'address' => '999 Other Rd',
        ]);
        // Attach an unrelated service so it doesn't qualify.
        $unrelated = Service::factory()->create(['name' => 'HDR Photos']);
        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $unrelated->id,
            'price' => 150,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/iguide_webhook.php', $this->buildPayload());
        $response->assertStatus(200)->assertJsonPath('success', true);

        $shoot->refresh();
        // Light metadata is still applied.
        $this->assertSame('igTEST001', $shoot->iguide_property_id);
        $this->assertNotNull($shoot->iguide_tour_url);
        // Tour link slots are auto-populated even without ingestion.
        $tourLinks = $shoot->tour_links ?? [];
        $this->assertNotEmpty($tourLinks['iguide_branded'] ?? null);
        $this->assertNotEmpty($tourLinks['iguide_mls'] ?? null);

        // But the heavy asset ingestion is skipped.
        Queue::assertNotPushed(IngestIguideAssetsJob::class);
    }

    public function test_auto_populates_iguide_branded_and_mls_link_slots(): void
    {
        Queue::fake();

        $shoot = Shoot::factory()->create([
            'iguide_work_order_id' => 'WO-TEST-1',
        ]);
        $this->attachIguideService($shoot);

        $this->postJson('/iguide_webhook.php', $this->buildPayload())
            ->assertStatus(200);

        $shoot->refresh();
        $tourLinks = $shoot->tour_links ?? [];
        $this->assertSame(
            'https://youriguide.com/iguide-test/?accessToken=tok',
            $tourLinks['iguide_branded'] ?? null,
        );
        $this->assertSame(
            'https://unbranded.youriguide.com/iguide-test/?accessToken=tok',
            $tourLinks['iguide_mls'] ?? null,
        );
    }

    public function test_does_not_overwrite_existing_admin_set_iguide_links(): void
    {
        Queue::fake();

        $shoot = Shoot::factory()->create([
            'iguide_work_order_id' => 'WO-TEST-1',
            'tour_links' => [
                'iguide_branded' => 'https://manual-branded.example.com/',
                'iguide_mls' => 'https://manual-mls.example.com/',
            ],
        ]);
        $this->attachIguideService($shoot);

        $this->postJson('/iguide_webhook.php', $this->buildPayload());

        $shoot->refresh();
        $tourLinks = $shoot->tour_links ?? [];
        $this->assertSame('https://manual-branded.example.com/', $tourLinks['iguide_branded']);
        $this->assertSame('https://manual-mls.example.com/', $tourLinks['iguide_mls']);
    }

    public function test_provider_webhook_preserves_the_manual_offline_package_lifecycle(): void
    {
        Queue::fake();
        $package = [
            'id' => 'upload-1',
            'upload_id' => 'upload-1',
            'status' => 'ready',
            'file_id' => 42,
            'publication_attestation' => [
                'policy' => 'authorized_staff_official_iguide_export',
                'version' => 1,
                'audiences' => ['branded', 'mls'],
                'attested_by' => 7,
                'attested_at' => '2026-09-01T00:00:00+00:00',
            ],
        ];
        $shoot = Shoot::factory()->create([
            'iguide_work_order_id' => 'WO-TEST-1',
            'iguide_data' => [
                'manual_offline_package' => $package,
                'stale_provider_value' => true,
            ],
        ]);
        $this->attachIguideService($shoot);

        $this->postJson('/iguide_webhook.php', $this->buildPayload())
            ->assertOk();

        $iguideData = $shoot->fresh()->iguide_data;
        $this->assertSame($package, $iguideData['manual_offline_package']);
        $this->assertSame('igTEST001', $iguideData['property_id']);
        $this->assertArrayNotHasKey('stale_provider_value', $iguideData);
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        Queue::fake();
        Cache::flush();

        $shoot = Shoot::factory()->create([
            'iguide_work_order_id' => 'WO-TEST-1',
        ]);
        $this->attachIguideService($shoot);

        $payload = $this->buildPayload();
        $this->postJson('/iguide_webhook.php', $payload)->assertStatus(200);
        $this->postJson('/iguide_webhook.php', $payload)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Duplicate event ignored');

        // Only the first delivery dispatches ingestion.
        Queue::assertPushed(IngestIguideAssetsJob::class, 1);
    }
}
