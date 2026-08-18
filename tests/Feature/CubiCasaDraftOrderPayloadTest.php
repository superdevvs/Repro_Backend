<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CubiCasa Integrate API v3 order creation contract.
 *
 * The previous implementation POSTed a nested body
 * `{"info":{"external_id":...},"address":{"street":...,"city":...}}` to
 * `/orders`. The live API rejects that with HTTP 400 — captured verbatim from
 * app.cubi.casa on 2026-08-18 while creating an order for shoot 27:
 *
 *   {"validation_error":{"body_params":[
 *     {"loc":["street"], "msg":"field required"},
 *     {"loc":["city"],   "msg":"field required"},
 *     {"loc":["country"],"msg":"field required"},
 *     {"loc":["info"],   "msg":"str type expected"},
 *     {"loc":["source"], "msg":"field required"}]}}
 *
 * Because 400 is neither 401/403 nor 404, classifyFailure() bucketed it as
 * FAILURE_OTHER, and the one log line carrying the status+body was emitted at
 * warning level — discarded under LOG_LEVEL=error. That is why the failure was
 * invisible and zero orders were ever created.
 *
 * The documented contract is a FLAT body posted to `/orders/draft`:
 * https://integrate.docs.cubi.casa/create-a-draft-order-20093452e0
 *
 * These tests pin that contract at the HTTP boundary.
 */
class CubiCasaDraftOrderPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const OWNER_EMAIL = 'orders@reprophotos.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
        config()->set('services.cubicasa.owner_email', self::OWNER_EMAIL);

        Http::fake(['*' => Http::response($this->draftSuccess(), 200)]);
    }

    private function draftSuccess(): array
    {
        return [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'info' => ['external_id' => 'shoot-1', 'status' => 'New'],
            'address' => ['full_address' => '22154 Del Valle St'],
        ];
    }

    private function shootWithServices(array $serviceNames): Shoot
    {
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
            'address' => '22154 Del Valle St',
            'city' => 'Woodland Hills',
            'state' => 'CA',
            'zip' => '91364',
            'property_details' => [],
        ]);

        foreach ($serviceNames as $name) {
            $service = Service::factory()->create(['name' => $name]);
            DB::table('shoot_service')->insert([
                'shoot_id' => $shoot->id,
                'service_id' => $service->id,
                'price' => 195,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $shoot->fresh();
    }

    /** Recorded body of the single outbound create request. */
    private function sentBody(): array
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'Expected an outbound CubiCasa request.');

        return $recorded[0][0]->data();
    }

    private function sentUrl(): string
    {
        return Http::recorded()[0][0]->url();
    }

    public function test_create_order_posts_to_the_orders_draft_endpoint(): void
    {
        app(CubiCasaService::class)->createOrder($this->shootWithServices(['2D Floor Plan']));

        $this->assertSame(
            self::BASE_URL . '/orders/draft',
            $this->sentUrl(),
            'CubiCasa v3 creates orders at POST /orders/draft; /orders rejects the body with 400.'
        );
    }

    public function test_payload_sends_street_city_and_country_at_top_level(): void
    {
        app(CubiCasaService::class)->createOrder($this->shootWithServices(['2D Floor Plan']));

        $body = $this->sentBody();

        $this->assertSame('22154 Del Valle St', $body['street'] ?? null);
        $this->assertSame('Woodland Hills', $body['city'] ?? null);
        $this->assertSame('United States', $body['country'] ?? null);
        $this->assertArrayNotHasKey('address', $body, 'v3 takes a flat body, not a nested address object.');
    }

    public function test_payload_sends_state_and_postal_code_at_top_level(): void
    {
        app(CubiCasaService::class)->createOrder($this->shootWithServices(['2D Floor Plan']));

        $body = $this->sentBody();

        $this->assertSame('CA', $body['state'] ?? null);
        $this->assertSame('91364', $body['postalCode'] ?? null);
    }

    public function test_payload_sends_owner_email_from_config(): void
    {
        app(CubiCasaService::class)->createOrder($this->shootWithServices(['2D Floor Plan']));

        $this->assertSame(
            self::OWNER_EMAIL,
            $this->sentBody()['owner_email'] ?? null,
            'owner_email is required by v3 and was never sent by the old builder.'
        );
    }

    public function test_payload_sends_external_id_at_top_level(): void
    {
        $shoot = $this->shootWithServices(['2D Floor Plan']);

        app(CubiCasaService::class)->createOrder($shoot);

        $this->assertSame('shoot-' . $shoot->id, $this->sentBody()['external_id'] ?? null);
    }

    public function test_payload_sends_info_as_a_string(): void
    {
        app(CubiCasaService::class)->createOrder($this->shootWithServices(['2D Floor Plan']));

        $info = $this->sentBody()['info'] ?? null;

        $this->assertIsString($info, 'v3 rejects an object here with "str type expected".');
    }

    public function test_package_type_is_base_for_a_2d_floor_plan(): void
    {
        app(CubiCasaService::class)->createOrder($this->shootWithServices(['2D Floor Plan']));

        $this->assertSame('base', $this->sentBody()['package_type'] ?? null);
    }

    public function test_package_type_is_plus_3d_for_a_3d_floor_plan(): void
    {
        app(CubiCasaService::class)->createOrder($this->shootWithServices(['3D Floor Plan']));

        $this->assertSame('plus_3d', $this->sentBody()['package_type'] ?? null);
    }

    public function test_package_type_prefers_plus_3d_when_both_services_are_booked(): void
    {
        app(CubiCasaService::class)->createOrder(
            $this->shootWithServices(['2D Floor Plan', '3D Floor Plan'])
        );

        $this->assertSame(
            'plus_3d',
            $this->sentBody()['package_type'] ?? null,
            'A shoot selling both tiers must order the higher one, not two orders.'
        );
    }

    /**
     * owner_email is required by v3. If it is unset the old array_filter would
     * simply drop the key and we would ship a request guaranteed to 400 —
     * recreating the invisible failure this whole fix exists to remove. Abort
     * before the call instead, with a reason distinct from a transport error.
     */
    public function test_create_order_aborts_without_calling_the_api_when_owner_email_is_missing(): void
    {
        config()->set('services.cubicasa.owner_email', null);

        $service = app(CubiCasaService::class);
        $result = $service->createOrder($this->shootWithServices(['2D Floor Plan']));

        $this->assertNull($result);
        $this->assertSame('config', $service->getLastFailureReason());
        Http::assertNothingSent();
    }

    /**
     * A missing env var is not transient, so burning three queue attempts and
     * an 18-minute backoff on it is pointless. The job must complete instead.
     */
    public function test_the_job_does_not_retry_when_owner_email_is_missing(): void
    {
        config()->set('services.cubicasa.owner_email', null);

        $shoot = $this->shootWithServices(['2D Floor Plan']);

        (new \App\Jobs\CreateCubiCasaOrderJob($shoot->id))->handle(app(CubiCasaService::class));

        Http::assertNothingSent();
        $this->assertNull($shoot->fresh()->cubicasa_order_id);
    }

    public function test_suite_is_sent_at_top_level_when_present(): void
    {
        $shoot = $this->shootWithServices(['2D Floor Plan']);
        $shoot->property_details = ['apt_suite' => 'A1'];
        $shoot->save();

        app(CubiCasaService::class)->createOrder($shoot);

        $this->assertSame('A1', $this->sentBody()['suite'] ?? null);
    }
}
