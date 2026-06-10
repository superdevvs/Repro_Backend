<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CubiCasaCreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const NEW_ORDER_ID = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
    }

    private function createdOrderPayload(string $externalId): array
    {
        return [
            'id' => self::NEW_ORDER_ID,
            'info' => [
                'external_id' => $externalId,
                'status' => 'New',
                'order_type' => 'Tier3-LiDAR',
            ],
            'address' => [
                'full_address' => '521 Brightfield Road',
            ],
        ];
    }

    public function test_create_when_unlinked_creates_links_and_audits(): void
    {
        Http::fake([
            self::BASE_URL . '/orders' => Http::response(
                $this->createdOrderPayload('shoot-PLACEHOLDER'),
                200
            ),
        ]);

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
            'address' => '521 Brightfield Road',
            'city' => 'Ottawa',
            'state' => 'ON',
            'zip' => 'K1A0B1',
        ]);

        $service = app(CubiCasaService::class);
        $parsed = $service->createOrder($shoot, $actor);

        $this->assertNotNull($parsed);

        $shoot->refresh();
        $this->assertSame(self::NEW_ORDER_ID, $shoot->cubicasa_order_id);
        $this->assertSame('New', $shoot->cubicasa_status);
        $this->assertNotEmpty($shoot->cubicasa_idempotency_key, 'A per-shoot idempotency key should be persisted.');

        // The create request carried the per-shoot Idempotency-Key header.
        Http::assertSent(function ($request) use ($shoot) {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), self::BASE_URL . '/orders')
                && $request->hasHeader('Idempotency-Key', $shoot->cubicasa_idempotency_key);
        });

        // AC 19.10 — exactly one audit entry for the create.
        $this->assertSame(
            1,
            UserActivityLog::where('event_type', 'cubicasa.manual_create')
                ->where('target_id', $shoot->id)
                ->count()
        );
    }

    public function test_create_when_already_linked_syncs_instead_of_creating(): void
    {
        Http::fake([
            self::BASE_URL . '/orders/*' => Http::response($this->createdOrderPayload('shoot-1'), 200),
            self::BASE_URL . '/orders' => Http::response(['id' => 'should-not-be-created'], 200),
        ]);

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => self::NEW_ORDER_ID,
        ]);

        $service = app(CubiCasaService::class);
        $service->createOrder($shoot, $actor);

        // No order-creation POST should ever be issued for an already-linked shoot.
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

    public function test_repeated_create_reuses_same_idempotency_key(): void
    {
        // Provider rejects so the shoot never links, allowing a second create attempt.
        Http::fake([
            self::BASE_URL . '/orders' => Http::response(['message' => 'upstream error'], 502),
        ]);

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
        ]);

        $service = app(CubiCasaService::class);

        $this->assertNull($service->createOrder($shoot, $actor));
        $key = $shoot->fresh()->cubicasa_idempotency_key;
        $this->assertNotEmpty($key);

        // Second attempt: still unlinked, must reuse the persisted key.
        $this->assertNull($service->createOrder($shoot->fresh(), $actor));
        $this->assertSame($key, $shoot->fresh()->cubicasa_idempotency_key);

        // Every create POST used the same Idempotency-Key.
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($key) {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), self::BASE_URL . '/orders')
                && $request->hasHeader('Idempotency-Key', $key);
        });
    }
}
