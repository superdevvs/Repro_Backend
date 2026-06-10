<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature coverage for `IntegrationController::createCubicasa` (Req 19.1, 19.4).
 *
 * Verifies:
 *  - Unlinked shoot: createOrder runs, the shoot is linked, status updated,
 *    and the response carries the parsed payload.
 *  - Already-linked shoot: createOrder syncs the existing order rather than
 *    creating a duplicate (AC 19.5).
 *  - syncCubicasa preserves its 409 for unlinked shoots (AC 19.4) — this
 *    behavior is independent of and unaffected by the new create endpoint.
 */
class CubiCasaCreateOrderEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const NEW_ORDER_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
    }

    private function fakeOrderPayload(string $externalId, string $status = 'New'): array
    {
        return [
            'id' => self::NEW_ORDER_ID,
            'info' => [
                'external_id' => $externalId,
                'status' => $status,
                'order_type' => 'Tier3-LiDAR',
            ],
            'address' => [
                'full_address' => '521 Brightfield Road',
            ],
        ];
    }

    public function test_create_endpoint_creates_and_links_unlinked_shoot(): void
    {
        Http::fake([
            self::BASE_URL . '/orders' => Http::response($this->fakeOrderPayload('shoot-1'), 200),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
            'address' => '521 Brightfield Road',
            'city' => 'Ottawa',
            'state' => 'ON',
            'zip' => 'K1A0B1',
        ]);

        $response = $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/order")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('shoot.id', $shoot->id)
            ->assertJsonPath('shoot.cubicasa_order_id', self::NEW_ORDER_ID)
            ->assertJsonPath('shoot.cubicasa_status', 'New');

        $shoot->refresh();
        $this->assertSame(self::NEW_ORDER_ID, $shoot->cubicasa_order_id);
        $this->assertSame('New', $shoot->cubicasa_status);
        $this->assertNotEmpty($shoot->cubicasa_idempotency_key);
        $this->assertSame($shoot->cubicasa_idempotency_key, $response->json('shoot.cubicasa_idempotency_key'));

        // The create POST went out with the per-shoot Idempotency-Key (AC 19.6).
        Http::assertSent(function ($request) use ($shoot) {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), self::BASE_URL . '/orders')
                && $request->hasHeader('Idempotency-Key', $shoot->cubicasa_idempotency_key);
        });

        // Exactly one audit entry for the create (AC 19.10).
        $this->assertSame(
            1,
            UserActivityLog::where('event_type', 'cubicasa.manual_create')
                ->where('target_id', $shoot->id)
                ->count()
        );
    }

    public function test_create_endpoint_syncs_already_linked_shoot_instead_of_duplicating(): void
    {
        Http::fake([
            // Sync path on an already-linked shoot fetches by id; never POST /orders.
            self::BASE_URL . '/orders/*' => Http::response($this->fakeOrderPayload('shoot-2', 'In Progress'), 200),
            self::BASE_URL . '/orders' => Http::response(['id' => 'should-not-be-created'], 200),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::NEW_ORDER_ID,
            'cubicasa_status' => 'New',
        ]);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/order")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('shoot.cubicasa_order_id', self::NEW_ORDER_ID)
            ->assertJsonPath('shoot.cubicasa_status', 'In Progress');

        // No new-order POST for an already-linked shoot (AC 19.5).
        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === self::BASE_URL . '/orders';
        });

        $this->assertSame(
            1,
            UserActivityLog::where('event_type', 'cubicasa.manual_sync')
                ->where('target_id', $shoot->id)
                ->count()
        );
        $this->assertSame(
            0,
            UserActivityLog::where('event_type', 'cubicasa.manual_create')
                ->where('target_id', $shoot->id)
                ->count()
        );
    }

    public function test_sync_endpoint_still_returns_409_for_unlinked_shoot(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
        ]);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/sync")
            ->assertStatus(409)
            ->assertJsonPath('mode', 'not-linked')
            ->assertJsonPath('sync.sync_status', CubiCasaService::SYNC_STATUS_NOT_LINKED);
    }
}
