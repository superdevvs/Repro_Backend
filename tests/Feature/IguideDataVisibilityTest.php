<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootEditablePayloadService;
use App\Services\Shoots\ShootPublicAssetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IguideDataVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function client_shoot_payloads_keep_view_state_but_redact_provider_and_package_secrets(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = $this->shootWithSensitiveIguideData($client);
        Sanctum::actingAs($client);

        $payload = $this->getJson("/api/shoots/{$shoot->id}")
            ->assertOk()
            ->json('data');

        $this->assertClientSafePayload($payload);

        // ShootResource backs mutation/workflow responses, while ShootPresenter
        // backs the show route. Pin both serializers to the same boundary.
        $request = Request::create('/api/shoots/'.$shoot->id);
        $request->setUserResolver(static fn () => $client);
        $resourcePayload = (new ShootResource($shoot->fresh()))->resolve($request);
        $this->assertClientSafePayload($resourcePayload);
    }

    #[Test]
    public function integration_managers_retain_the_internal_iguide_diagnostics(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->admin()->create();
        $shoot = $this->shootWithSensitiveIguideData($client);
        Sanctum::actingAs($admin);

        $payload = $this->getJson("/api/shoots/{$shoot->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('standalone-secret', data_get($payload, 'iguide_data.authtoken'));
        $this->assertSame('https://manage.youriguide.com/private', data_get($payload, 'iguide_data.manage_url'));
        $this->assertSame(987, data_get($payload, 'iguide_data.manual_offline_package.file_id'));
        $this->assertSame(987, data_get($payload, 'iguide_manual_offline_package.file_id'));
        $this->assertSame('IG-PROP-SECRET', $payload['iguide_property_id']);
        $this->assertSame('WO-SECRET', $payload['iguide_work_order_id']);
    }

    #[Test]
    public function clients_cannot_trigger_an_iguide_provider_sync(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/iguide/sync")
            ->assertForbidden();
    }

    #[Test]
    public function a_manually_entered_mls_url_gets_server_owned_unbranded_provenance(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'tour_links' => [
                'iguide_branded' => 'https://youriguide.com/branded-view/',
                'iguide_mls_source' => 'unbranded_url',
            ],
        ]);

        app(ShootEditablePayloadService::class)->apply($shoot, [
            'tour_links' => [
                'iguide_mls' => 'https://unbranded.youriguide.com/manual-view/',
                // Caller-controlled provider provenance is ignored.
                'iguide_mls_source' => 'unbranded_url',
            ],
        ], $admin);

        $shoot->refresh();
        $this->assertSame('manual', data_get($shoot->tour_links, 'iguide_mls_source'));
        $payload = app(ShootPublicAssetsService::class)
            ->buildTypedPublicAssets($shoot, 'mls', reconcilePayments: false);
        $this->assertSame(
            'https://unbranded.youriguide.com/manual-view/',
            $payload['iguide_viewer']['open_url']
        );
        $this->assertSame('tour_links_unbranded', $payload['iguide_viewer']['source']);
    }

    private function shootWithSensitiveIguideData(User $client): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $client->id,
            'iguide_property_id' => 'IG-PROP-SECRET',
            'iguide_work_order_id' => 'WO-SECRET',
            'iguide_data' => [
                'tour_url' => 'https://youriguide.com/view-state/?accessToken=embedded-token',
                'unbranded_url' => 'https://unbranded.youriguide.com/view-state/?accessToken=embedded-token',
                'embedded_url' => 'https://youriguide.com/embed/view-state/?accessToken=embedded-token',
                'embed_image_url' => 'https://youriguide.com/view-state/doc/embed.jpg',
                'authtoken' => 'standalone-secret',
                'manage_url' => 'https://manage.youriguide.com/private',
                'offline_zip_url' => 'https://manage.youriguide.com/private/offline.zip',
                'gallery_zip_url' => 'https://manage.youriguide.com/private/gallery.zip',
                'sphere_zip_url' => 'https://manage.youriguide.com/private/spheres.zip',
                'pdf_metric_url' => 'https://manage.youriguide.com/private/floorplan.pdf',
                'billing' => ['billableAreaSqFeet' => 9999],
                'property_id' => 'IG-PROP-SECRET',
                'work_order_id' => 'WO-SECRET',
                'manual_offline_package' => [
                    'status' => 'ready',
                    'ready_at' => '2026-09-01T00:00:00+00:00',
                    'file_id' => 987,
                    'original_filename' => 'private-offline.zip',
                    'sha256' => str_repeat('a', 64),
                    'uploaded_by' => 123,
                    'path' => 'secure/iguide-packages/private-offline.zip',
                    'publication_attestation' => [
                        'policy' => 'authorized_staff_official_iguide_export',
                        'version' => 1,
                        'audiences' => ['branded', 'mls'],
                        'attested_by' => 123,
                        'attested_at' => '2026-09-01T00:00:00+00:00',
                    ],
                ],
            ],
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function assertClientSafePayload(array $payload): void
    {
        $iguide = $payload['iguide_data'] ?? [];
        foreach (['tour_url', 'unbranded_url', 'embedded_url', 'embed_image_url', 'manual_offline_package'] as $key) {
            $this->assertArrayHasKey($key, $iguide);
        }
        foreach ([
            'authtoken', 'manage_url', 'offline_zip_url', 'gallery_zip_url', 'sphere_zip_url',
            'pdf_metric_url', 'billing', 'property_id', 'work_order_id',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $iguide, "{$key} leaked to a view-only client");
        }

        $package = $iguide['manual_offline_package'];
        $this->assertSame([
            'status' => 'ready',
            'view_only' => true,
        ], $package);
        $this->assertSame($package, $payload['iguide_manual_offline_package']);
        $this->assertArrayNotHasKey('iguide_property_id', $payload);
        $this->assertArrayNotHasKey('iguide_work_order_id', $payload);
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['standalone-secret', 'private-offline.zip', str_repeat('a', 64), '9999'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }
}
