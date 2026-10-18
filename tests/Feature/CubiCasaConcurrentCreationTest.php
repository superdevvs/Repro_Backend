<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Services\CubiCasaService;
use App\Services\Shoots\ShootUnitTourScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CubiCasaConcurrentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.owner_email', 'orders@example.test');
        config()->set('services.cubicasa.base_url', 'https://cubicasa.test');
        Http::preventStrayRequests();
    }

    public function test_two_stale_models_reuse_the_persisted_key_after_a_provider_failure(): void
    {
        $shoot = Shoot::factory()->create();
        $first = $shoot->fresh();
        $second = $shoot->fresh();
        Http::fake(['https://cubicasa.test/orders/draft' => Http::response([], 503)]);

        app(CubiCasaService::class)->createOrder($first);
        $key = $shoot->fresh()->cubicasa_idempotency_key;
        app(CubiCasaService::class)->createOrder($second);

        $this->assertNotEmpty($key);
        $this->assertSame($key, $shoot->fresh()->cubicasa_idempotency_key);
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        foreach ($requests as [$request]) {
            $this->assertTrue($request->hasHeader('Idempotency-Key', $key));
        }
    }

    public function test_a_stale_model_syncs_the_order_created_by_another_worker(): void
    {
        $shoot = Shoot::factory()->create();
        $first = $shoot->fresh();
        $second = $shoot->fresh();
        Http::fake([
            'https://cubicasa.test/orders/draft' => Http::response($this->order()),
            'https://cubicasa.test/orders/created-order' => Http::response($this->order()),
        ]);

        app(CubiCasaService::class)->createOrder($first);
        app(CubiCasaService::class)->createOrder($second);

        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'GET'));
        $this->assertSame('created-order', $second->cubicasa_order_id);
    }

    public function test_overlapping_provider_requests_use_one_idempotency_key(): void
    {
        $shoot = Shoot::factory()->create();
        $first = $shoot->fresh();
        $second = $shoot->fresh();
        $inFirstRequest = false;
        $keys = [];
        Http::fake(function ($request) use ($second, &$inFirstRequest, &$keys) {
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;
            if (!$inFirstRequest) {
                $inFirstRequest = true;
                // Start the second worker after the first has reached the
                // provider, but before its order has been linked locally.
                app(CubiCasaService::class)->createOrder($second);
            }
            return Http::response($this->order());
        });

        app(CubiCasaService::class)->createOrder($first);

        $this->assertCount(2, $keys);
        $this->assertNotEmpty($keys[0]);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($keys[0], $shoot->fresh()->cubicasa_idempotency_key);
    }

    public function test_unit_service_line_claims_reuse_the_key_and_preserve_other_provider_data(): void
    {
        $shoot = Shoot::factory()->create();
        $unit = $shoot->units()->create([
            'client_key' => 'unit-a', 'label' => 'A', 'kind' => 'unit',
            'provider_data' => ['iguide_property_id' => 'keep-iguide', 'lines' => ['999' => ['cubicasa_idempotency_key' => 'other-line-key']]],
        ]);
        $line = $shoot->serviceItems()->create([
            'shoot_unit_id' => $unit->id,
            'service_id' => Service::factory()->create(['name' => '2D Floor plans'])->id,
            'price' => 100, 'quantity' => 1,
        ]);
        $scope = app(ShootUnitTourScope::class);
        $first = $scope->project($shoot, $unit, $line);
        $second = $scope->project($shoot, $unit, $line);
        Http::fake(['https://cubicasa.test/orders/draft' => Http::response([], 503)]);

        app(CubiCasaService::class)->createOrder($first);
        $key = data_get($unit->fresh()->provider_data, 'lines.'.$line->id.'.cubicasa_idempotency_key');
        app(CubiCasaService::class)->createOrder($second);

        $data = $unit->fresh()->provider_data;
        $this->assertNotEmpty($key);
        $this->assertSame($key, data_get($data, 'lines.'.$line->id.'.cubicasa_idempotency_key'));
        $this->assertSame('keep-iguide', $data['iguide_property_id']);
        $this->assertSame('other-line-key', data_get($data, 'lines.999.cubicasa_idempotency_key'));
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader('Idempotency-Key', $key));
        }
    }

    private function order(): array
    {
        return ['id' => 'created-order', 'info' => ['status' => 'Draft'], 'delivery_assets' => []];
    }

    public function test_new_unit_line_key_does_not_claim_a_sibling_order_and_retries_keep_line_identity(): void
    {
        $shoot = Shoot::factory()->create();
        $unit = $shoot->units()->create(['client_key' => 'two-lines', 'label' => 'A', 'kind' => 'unit']);
        $lines = collect([1, 2])->map(fn () => $shoot->serviceItems()->create([
            'shoot_unit_id' => $unit->id, 'service_id' => Service::factory()->create(['name' => '2D Floor plans'])->id,
            'price' => 100, 'quantity' => 1,
        ]));
        $scope = app(ShootUnitTourScope::class);
        $secondAttempts = 0;
        Http::fake(['https://cubicasa.test/orders/draft' => function ($request) use ($lines, &$secondAttempts) {
            if (str_ends_with($request['external_id'], '-line-'.$lines[1]->id) && ++$secondAttempts === 1) return Http::response([], 503);
            return Http::response(['id' => 'order-'.$request['external_id'], 'info' => ['status' => 'New', 'external_id' => $request['external_id']]]);
        }]);
        $service = app(CubiCasaService::class);
        $service->createOrder($scope->project($shoot, $unit->fresh(), $lines[0]));
        $firstOrder = $scope->project($shoot, $unit->fresh(), $lines[0])->cubicasa_order_id;
        $second = $scope->project($shoot, $unit->fresh(), $lines[1]);
        $this->assertNull($service->createOrder($second));
        $this->assertSame($lines[0]->id, data_get($unit->fresh()->provider_data, 'cubicasa_service_line_id'));
        $this->assertSame($firstOrder, $scope->project($shoot, $unit->fresh(), $lines[0])->cubicasa_order_id);
        $this->assertNotNull($service->createOrder($second));
        $fresh = $unit->fresh();
        $this->assertSame('order-shoot-'.$shoot->id.'-unit-'.$unit->id.'-line-'.$lines[1]->id, $scope->project($shoot, $fresh, $lines[1])->cubicasa_order_id);
        $this->assertSame(data_get($fresh->provider_data, 'lines.'.$lines[1]->id.'.cubicasa_idempotency_key'), $fresh->provider_data['cubicasa_idempotency_key']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->method() === 'GET');
    }
}
