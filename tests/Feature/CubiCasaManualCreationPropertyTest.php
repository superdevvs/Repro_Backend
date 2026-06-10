<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 18:
 * Manual CubiCasa creation links, updates status, and is idempotent.
 *
 * Validates: Requirements 19.2, 19.3, 19.4, 19.5
 *
 * For any shoot and any combination of (linked/unlinked state, provider status,
 * number of repeated create calls) the following universal invariants hold:
 *
 *   (UNLINKED → CREATE, AC 19.2/19.3) For a shoot with no linked order, the
 *     first manual create issues exactly one POST /orders, links the shoot via
 *     `cubicasa_order_id` AND `cubicasa_external_id`, and updates
 *     `cubicasa_status` to the provider-reported status.
 *
 *   (ALREADY-LINKED → SYNC, AC 19.5) For a shoot that already has a linked
 *     order, manual create NEVER issues a POST /orders — it syncs the existing
 *     order (GET /orders/{id}) and updates `cubicasa_status` from the provider.
 *     This holds across repeated create calls (idempotent: no duplicate order).
 *
 *   (UNLINKED → SYNC ENDPOINT, AC 19.4) A manual sync request to
 *     `IntegrationController::syncCubicasa` for a shoot with no linked order
 *     returns HTTP 409 and leaves the shoot unlinked.
 *
 * Approach: no PHP property-based testing library is configured for the backend,
 * so this test follows the deterministic-generator strategy already used by the
 * other property tests in this suite (see CubiCasaPerShootIdempotencyPropertyTest,
 * ShootEditingPayloadFilteringPropertyTest): a seeded PRNG produces 25 randomized
 * cases over the input space (random scenario, random provider status, random
 * 1..4 repeated create calls) plus a handful of deterministic edge cases. The
 * same universal invariants must hold for every generated input.
 */
class CubiCasaManualCreationPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const NEW_ORDER_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    private const EXISTING_ORDER_ID = 'ffffffff-0000-1111-2222-333333333333';

    /** Spec mandates >= 25 randomized cases. */
    private const RANDOM_ITERATIONS = 25;

    /** Fixed seed so failures reproduce; bump if a counterexample is fixed. */
    private const SEED = 18_18_18;

    private const SCENARIO_UNLINKED_CREATE = 'unlinked_create';
    private const SCENARIO_LINKED_SYNC = 'linked_sync';
    private const SCENARIO_UNLINKED_SYNC_409 = 'unlinked_sync_409';

    /** Provider statuses the order may report — exercises AC 19.3 status update. */
    private const STATUSES = ['New', 'In Progress', 'Processing', 'Delivered', 'Completed', 'Cancelled'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
    }

    /**
     * Generator: 25 randomized + deterministic edge cases.
     *
     * Each case is [scenario, status, repeatCreateCalls].
     *
     * @return list<array{0:string,1:string,2:int}>
     */
    private function caseGenerator(): array
    {
        mt_srand(self::SEED);

        $scenarios = [
            self::SCENARIO_UNLINKED_CREATE,
            self::SCENARIO_LINKED_SYNC,
            self::SCENARIO_UNLINKED_SYNC_409,
        ];

        $cases = [];
        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $scenario = $scenarios[mt_rand(0, count($scenarios) - 1)];
            $status = self::STATUSES[mt_rand(0, count(self::STATUSES) - 1)];
            $repeat = mt_rand(1, 4);
            $cases[] = [$scenario, $status, $repeat];
        }

        // Deterministic edge cases.
        $cases[] = [self::SCENARIO_UNLINKED_CREATE, 'New', 1];          // single create, fresh order
        $cases[] = [self::SCENARIO_UNLINKED_CREATE, 'Delivered', 3];    // create then repeated -> sync (no dup)
        $cases[] = [self::SCENARIO_LINKED_SYNC, 'In Progress', 1];      // single sync on linked
        $cases[] = [self::SCENARIO_LINKED_SYNC, 'Completed', 4];        // repeated sync on linked (no dup)
        $cases[] = [self::SCENARIO_UNLINKED_SYNC_409, 'New', 1];        // sync endpoint on unlinked -> 409

        return $cases;
    }

    private function payload(string $orderId, string $externalId, string $status): array
    {
        return [
            'id' => $orderId,
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

    /**
     * Property 18 — for every generated input the scenario's invariants hold.
     *
     * Validates: Requirements 19.2, 19.3, 19.4, 19.5
     */
    public function test_manual_creation_links_updates_status_and_is_idempotent(): void
    {
        foreach ($this->caseGenerator() as $caseIndex => [$scenario, $status, $repeat]) {
            // Swap in a fresh Http factory each iteration so prior stubs and the
            // recorded request log cannot leak across cases (Http::fake merges).
            Http::swap(new HttpFactory(
                $this->app->bound('events') ? $this->app->make('events') : null
            ));

            $context = sprintf(
                'case %d, scenario=%s, status=%s, repeat=%d',
                $caseIndex,
                $scenario,
                $status,
                $repeat
            );

            match ($scenario) {
                self::SCENARIO_UNLINKED_CREATE => $this->assertUnlinkedCreate($status, $repeat, $context),
                self::SCENARIO_LINKED_SYNC => $this->assertLinkedSync($status, $repeat, $context),
                self::SCENARIO_UNLINKED_SYNC_409 => $this->assertUnlinkedSyncReturns409($context),
            };
        }
    }

    /**
     * AC 19.2/19.3 (+ 19.5 idempotency under repeats): an unlinked shoot is
     * created + linked on the first call, status is updated, and every
     * subsequent create call syncs the now-linked order instead of POSTing a
     * duplicate.
     */
    private function assertUnlinkedCreate(string $status, int $repeat, string $context): void
    {
        $externalId = 'shoot-prop18-' . substr(md5($context), 0, 8);

        Http::fake([
            // Sync path for the (now-linked) order on repeat calls.
            self::BASE_URL . '/orders/*' => Http::response(
                $this->payload(self::NEW_ORDER_ID, $externalId, $status),
                200
            ),
            // Create path.
            self::BASE_URL . '/orders' => Http::response(
                $this->payload(self::NEW_ORDER_ID, $externalId, $status),
                200
            ),
        ]);

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
            'cubicasa_status' => null,
            'address' => '521 Brightfield Road',
            'city' => 'Ottawa',
            'state' => 'ON',
            'zip' => 'K1A0B1',
        ]);

        $service = app(CubiCasaService::class);

        for ($i = 0; $i < $repeat; $i++) {
            $service->createOrder($shoot->fresh(), $actor);
        }

        $shoot->refresh();

        // AC 19.2 — linked via BOTH cubicasa_order_id and cubicasa_external_id.
        $this->assertSame(self::NEW_ORDER_ID, $shoot->cubicasa_order_id, "[19.2] order_id must be linked for {$context}");
        $this->assertSame($externalId, $shoot->cubicasa_external_id, "[19.2] external_id must be linked for {$context}");

        // AC 19.3 — status updated to provider-reported status.
        $this->assertSame($status, $shoot->cubicasa_status, "[19.3] cubicasa_status must be updated for {$context}");

        // AC 19.5 — exactly ONE create POST regardless of repeat count; the
        // remaining calls sync the existing order (no duplicate creation).
        $postOrders = Http::recorded(function ($request) {
            return $request->method() === 'POST' && $request->url() === self::BASE_URL . '/orders';
        });
        $this->assertCount(1, $postOrders, "[19.5] exactly one POST /orders despite {$repeat} create call(s) for {$context}");

        if ($repeat > 1) {
            $syncGets = Http::recorded(function ($request) {
                return $request->method() === 'GET' && str_starts_with($request->url(), self::BASE_URL . '/orders/');
            });
            $this->assertCount(
                $repeat - 1,
                $syncGets,
                "[19.5] post-link create calls must sync via GET /orders/{id} for {$context}"
            );
        }
    }

    /**
     * AC 19.5: a shoot that already has a linked order is synced (never a
     * duplicate POST), and its status is updated from the provider. Holds
     * across repeated create calls.
     */
    private function assertLinkedSync(string $status, int $repeat, string $context): void
    {
        $externalId = 'shoot-linked-' . substr(md5($context), 0, 8);

        Http::fake([
            self::BASE_URL . '/orders/*' => Http::response(
                $this->payload(self::EXISTING_ORDER_ID, $externalId, $status),
                200
            ),
            // Guard: must never be hit for an already-linked shoot.
            self::BASE_URL . '/orders' => Http::response(['id' => 'should-not-be-created'], 200),
        ]);

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::EXISTING_ORDER_ID,
            'cubicasa_external_id' => $externalId,
            'cubicasa_status' => 'New',
        ]);

        $service = app(CubiCasaService::class);

        for ($i = 0; $i < $repeat; $i++) {
            $service->createOrder($shoot->fresh(), $actor);
        }

        $shoot->refresh();

        // AC 19.5 — no creation POST ever issued for an already-linked shoot.
        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST' && $request->url() === self::BASE_URL . '/orders';
        });

        // Still linked to the original order — no duplicate/replacement.
        $this->assertSame(self::EXISTING_ORDER_ID, $shoot->cubicasa_order_id, "[19.5] linked order must be preserved for {$context}");

        // AC 19.3 — status synced from provider.
        $this->assertSame($status, $shoot->cubicasa_status, "[19.3] synced status must be applied for {$context}");

        // Sync path hit once per call.
        $syncGets = Http::recorded(function ($request) {
            return $request->method() === 'GET' && str_starts_with($request->url(), self::BASE_URL . '/orders/');
        });
        $this->assertCount($repeat, $syncGets, "[19.5] each create call on a linked shoot syncs via GET for {$context}");
    }

    /**
     * AC 19.4: a manual sync for a shoot with no linked order returns HTTP 409
     * and leaves the shoot unlinked.
     */
    private function assertUnlinkedSyncReturns409(string $context): void
    {
        Http::fake([
            self::BASE_URL . '/*' => Http::response([], 200),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
        ]);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/sync")
            ->assertStatus(409)
            ->assertJsonPath('sync.sync_status', CubiCasaService::SYNC_STATUS_NOT_LINKED);

        $shoot->refresh();
        $this->assertNull($shoot->cubicasa_order_id, "[19.4] shoot must remain unlinked after 409 for {$context}");
        $this->assertNull($shoot->cubicasa_external_id, "[19.4] shoot must remain unlinked after 409 for {$context}");
    }
}
