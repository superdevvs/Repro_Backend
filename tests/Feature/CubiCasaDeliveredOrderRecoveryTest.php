<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootUnit;
use App\Services\CubiCasaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CubiCasaDeliveredOrderRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://cubicasa.test';
    private const DRAFT = 'a2403d4d-d027-4707-88bd-76f66232a4f5';
    private const READY = 'b7d371bc-b640-433c-bcd8-baeea1c926ef';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE);
        Http::preventStrayRequests();
    }

    public function test_sync_replaces_an_unused_draft_with_one_exact_delivered_scan_and_fetches_its_details(): void
    {
        $shoot = $this->shoot();
        $this->fakeProvider([$this->summary($this->ready())], $this->ready());

        $parsed = app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::READY, $parsed['order_id']);
        $this->assertCount(1, $parsed['floorplans']);
        $this->assertSame(self::READY, $shoot->fresh()->cubicasa_order_id);
        $this->assertSame('shoot-'.$shoot->id, $shoot->fresh()->cubicasa_external_id);
        $this->assertSame('Ready', $shoot->fresh()->cubicasa_status);
        Http::assertSent(fn ($request) => $request->url() === self::BASE.'/orders/'.self::READY);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_external_id_lookup_fetches_full_details_instead_of_persisting_a_list_summary(): void
    {
        $shoot = $this->shoot(['cubicasa_order_id' => null]);
        $ready = $this->ready();
        $ready['info']['external_id'] = 'shoot-'.$shoot->id;
        $this->fakeProvider([$this->summary($ready)], $ready);

        $parsed = app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertCount(1, $parsed['floorplans']);
        $this->assertCount(1, $shoot->fresh()->cubicasa_floorplans);
        Http::assertSent(fn ($request) => $request->url() === self::BASE.'/orders/'.self::READY);
    }

    #[DataProvider('progressedStatuses')]
    public function test_sync_never_replaces_a_progressed_order(string $status): void
    {
        $shoot = $this->shoot(['cubicasa_status' => $status]);
        $this->fakeProvider([], $this->ready(), $status);

        app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::DRAFT, $shoot->fresh()->cubicasa_order_id);
        Http::assertSentCount(1);
    }

    public static function progressedStatuses(): array
    {
        return [['Pending'], ['Processing'], ['Fixing'], ['Ready']];
    }

    #[DataProvider('unsafeCandidates')]
    public function test_matching_rejects_conflicting_or_incomplete_identity(array $overrides): void
    {
        $shoot = $this->shoot();
        $candidate = array_replace_recursive($this->ready(), $overrides);

        $this->assertFalse(app(CubiCasaService::class)->matchesDeliveredOrderToShoot($shoot, $candidate));
        Http::assertNothingSent();
    }

    public static function unsafeCandidates(): array
    {
        return [
            'different building' => [['address' => ['number' => '2361']]],
            'range punctuation is significant' => [['address' => ['number' => '236173']]],
            'different city' => [['address' => ['city' => 'Pittsburgh']]],
            'different state' => [['address' => ['state' => 'Maryland']]],
            'different zip' => [['address' => ['postalCode' => '19115']]],
            'different unit' => [['address' => ['suite' => '2']]],
            'different country' => [['address' => ['country' => 'Canada']]],
            'missing country' => [['address' => ['country' => null]]],
            'external id assigned elsewhere' => [['info' => ['external_id' => 'shoot-987654']]],
            'not delivered' => [['info' => ['status' => 'Pending']]],
            'missing scan time' => [['info' => ['created_at' => null]]],
            'old scan' => [['info' => ['created_at' => '2026-09-01T20:00:00Z']]],
        ];
    }

    public function test_existing_floorplans_prevent_relinking_an_inconsistent_draft(): void
    {
        $shoot = $this->shoot(['cubicasa_floorplans' => [['asset_key' => 'already-delivered']]]);
        $this->assertFalse(app(CubiCasaService::class)->matchesDeliveredOrderToShoot($shoot, $this->ready()));
    }

    public function test_a_delivered_order_claimed_by_another_shoot_is_rejected(): void
    {
        $shoot = $this->shoot();
        $this->shoot(['cubicasa_order_id' => self::READY, 'cubicasa_status' => 'Ready']);
        $this->assertFalse(app(CubiCasaService::class)->matchesDeliveredOrderToShoot($shoot, $this->ready()));
    }

    public function test_a_delivered_order_claimed_by_a_nested_unit_line_is_rejected(): void
    {
        $shoot = $this->shoot();
        $other = $this->shoot(['cubicasa_order_id' => null]);
        ShootUnit::create([
            'shoot_id' => $other->id,
            'client_key' => 'unit-a',
            'label' => 'Unit A',
            'kind' => 'unit',
            'provider_data' => ['lines' => ['123' => ['cubicasa_order_id' => self::READY]]],
        ]);
        $this->assertFalse(app(CubiCasaService::class)->matchesDeliveredOrderToShoot($shoot, $this->ready()));
    }

    public function test_multiple_delivered_scans_leave_the_draft_unchanged(): void
    {
        $shoot = $this->shoot();
        $second = $this->summary($this->ready());
        $second['id'] = 'second-ready-order';
        $this->fakeProvider([$this->summary($this->ready()), $second], $this->ready());

        app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::DRAFT, $shoot->fresh()->cubicasa_order_id);
        Http::assertNotSent(fn ($request) => $request->url() === self::BASE.'/orders/'.self::READY);
    }

    public function test_multiple_matching_bookings_leave_the_draft_unchanged(): void
    {
        $shoot = $this->shoot();
        $this->shoot();
        $this->fakeProvider([$this->summary($this->ready())], $this->ready());

        app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::DRAFT, $shoot->fresh()->cubicasa_order_id);
    }

    public function test_a_failed_candidate_detail_fetch_does_not_relink_the_draft(): void
    {
        $shoot = $this->shoot();
        $this->fakeProvider([$this->summary($this->ready())], null);

        app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::DRAFT, $shoot->fresh()->cubicasa_order_id);
        $this->assertEmpty($shoot->fresh()->cubicasa_floorplans);
    }

    public function test_a_ready_detail_without_deliverables_does_not_relink_the_draft(): void
    {
        $shoot = $this->shoot();
        $this->fakeProvider([$this->summary($this->ready())], $this->summary($this->ready()));

        app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::DRAFT, $shoot->fresh()->cubicasa_order_id);
    }

    public function test_incomplete_pagination_cannot_establish_a_unique_candidate(): void
    {
        $shoot = $this->shoot();
        Http::fake([
            self::BASE.'/orders/'.self::DRAFT => Http::response(['id' => self::DRAFT, 'info' => ['status' => 'Draft']]),
            self::BASE.'/orders?*' => Http::response([
                'items' => [$this->summary($this->ready())],
                'pagination' => ['has_more' => true, 'next_offset' => 0],
            ]),
        ]);

        app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::DRAFT, $shoot->fresh()->cubicasa_order_id);
    }

    public function test_recovery_follows_provider_offsets_before_deciding_uniqueness(): void
    {
        $shoot = $this->shoot();
        Http::fake([
            self::BASE.'/orders/'.self::DRAFT => Http::response(['id' => self::DRAFT, 'info' => ['status' => 'Draft']]),
            self::BASE.'/orders/'.self::READY => Http::response($this->ready()),
            self::BASE.'/orders?limit=100&offset=0' => Http::response(['items' => [], 'pagination' => ['has_more' => true, 'next_offset' => 101]]),
            self::BASE.'/orders?limit=100&offset=101' => Http::response(['items' => [$this->summary($this->ready())], 'pagination' => ['has_more' => false]]),
        ]);

        app(CubiCasaService::class)->syncShoot($shoot);

        $this->assertSame(self::READY, $shoot->fresh()->cubicasa_order_id);
        Http::assertSentCount(4);
    }

    private function shoot(array $attributes = []): Shoot
    {
        $shoot = Shoot::factory()->create(array_replace([
            'address' => '2361-73 Welsh Road',
            'city' => 'Philadelphia',
            'state' => 'PA',
            'zip' => '19114',
            'scheduled_at' => '2026-10-01 20:00:00',
            'cubicasa_order_id' => self::DRAFT,
            'cubicasa_status' => 'Draft',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'property_details' => [],
        ], $attributes));
        $shoot->forceFill(['cubicasa_external_id' => 'shoot-'.$shoot->id])->saveQuietly();
        $shoot->services()->attach(Service::factory()->create(['name' => '2D Floor plans'])->id, ['price' => 195, 'quantity' => 1]);
        return $shoot->fresh();
    }

    private function ready(): array
    {
        return [
            'id' => self::READY,
            'info' => ['status' => 'Ready', 'external_id' => null, 'created_at' => CarbonImmutable::parse('2026-10-01T19:00:00Z')->getTimestamp()],
            'address' => ['number' => '2361-73', 'street' => 'Welsh Rd.', 'city' => 'Philadelphia', 'state' => 'Pennsylvania', 'postalCode' => '19114', 'country' => 'United States'],
            'delivery_assets' => ['listing_floorplans' => ['pdf_urls_dim' => ['https://example.test/floorplan.pdf']]],
        ];
    }

    private function summary(array $order): array
    {
        unset($order['delivery_assets']);
        return $order;
    }

    private function fakeProvider(array $summaries, ?array $detail, string $draftStatus = 'Draft'): void
    {
        Http::fake([
            self::BASE.'/orders/'.self::DRAFT => Http::response(['id' => self::DRAFT, 'info' => ['status' => $draftStatus]]),
            self::BASE.'/orders/'.self::READY => Http::response($detail ?? [], $detail === null ? 503 : 200),
            self::BASE.'/orders?*' => Http::response(['items' => $summaries, 'pagination' => ['has_more' => false]]),
        ]);
    }
}
