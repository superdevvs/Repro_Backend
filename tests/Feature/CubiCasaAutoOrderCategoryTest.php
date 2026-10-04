<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Models\{Category, Service, Shoot};
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Queue};
use Tests\TestCase;

class CubiCasaAutoOrderCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.cubicasa.api_key' => 'test-key']);
    }

    private function service(string $category, string $name = 'Floor plan included'): Service
    {
        return Service::factory()->create([
            'name' => $name,
            'category_id' => Category::firstOrCreate(['name' => $category])->id,
        ]);
    }

    private function shoot(Service $service, array $attributes = []): Shoot
    {
        $shoot = Shoot::factory()->create(array_merge([
            'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_at' => now()->addDays(3),
            'cubicasa_order_id' => null, 'cubicasa_external_id' => null,
        ], $attributes));
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1]);
        return $shoot;
    }

    public function test_only_standalone_floor_plan_categories_authorize_automatic_orders(): void
    {
        foreach (['Floor Plans', 'Photos & Floor plans', ' FLOOR PLANS '] as $category) {
            $shoot = $this->shoot($this->service($category, 'Renamed package'));
            $this->assertTrue($shoot->hasCubiCasaAutoOrderService(), $category);
        }
        foreach (['Premium iGuide w Floor plans', 'Matterport Packages', '3D Matterport', 'iGuide Packages', 'GLA Scans', 'Photos'] as $category) {
            $shoot = $this->shoot($this->service($category));
            $this->assertFalse($shoot->hasCubiCasaAutoOrderService(), $category);
            $this->assertNull(app(CubiCasaService::class)->createOrder($shoot, null, 'auto'));
        }
        Http::assertNothingSent();
    }

    public function test_stale_primary_service_cannot_override_current_booking_but_mixed_booking_qualifies(): void
    {
        $floor = $this->service('Floor Plans');
        $shoot = $this->shoot($this->service('iGuide Packages'), ['service_id' => $floor->id]);
        $this->assertFalse($shoot->hasCubiCasaAutoOrderService());
        $shoot->services()->attach($floor->id, ['price' => 100, 'quantity' => 1]);
        $this->assertTrue($shoot->hasCubiCasaAutoOrderService());
    }

    public function test_queued_job_rechecks_removed_service_and_unscheduled_or_held_bookings(): void
    {
        $floor = $this->service('Floor Plans');
        $provider = \Mockery::mock(CubiCasaService::class);
        $provider->shouldNotReceive('createOrder');
        $provider->shouldNotReceive('hasCredentials');
        foreach ([['scheduled_at' => null], ['status' => 'on_hold'], ['workflow_status' => 'requested']] as $attributes) {
            (new CreateCubiCasaOrderJob($this->shoot($floor, $attributes)->id))->handle($provider);
        }
        $shoot = $this->shoot($floor);
        $job = new CreateCubiCasaOrderJob($shoot->id);
        $shoot->services()->sync([$this->service('Matterport Packages')->id => ['price' => 100, 'quantity' => 1]]);
        $job->handle($provider);
    }

    public function test_backfill_filters_categories_before_limiting_results(): void
    {
        $eligible = $this->shoot($this->service('Floor Plans'));
        $this->shoot($this->service('iGuide Packages'));
        $this->artisan('cubicasa:resync-pending', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(CreateCubiCasaOrderJob::class, fn ($job) => $job->shootId === $eligible->id);
    }
}
