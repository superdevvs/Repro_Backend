<?php

namespace Tests\Feature;

use App\Models\AryeoConnection;
use App\Models\AryeoRequest;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\ShootUnit;
use App\Models\User;
use App\Services\Aryeo\AryeoCatalog;
use App\Services\Shoots\ShootServiceProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootServiceProgressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_payload_keeps_independent_statuses_on_a_partially_delivered_shoot(): void
    {
        Event::fake();
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'photographer_id' => $photographer->id,
            'status' => 'delivered', 'workflow_status' => 'delivered']);
        $catalog = Service::factory()->create(['name' => '35 HDR Photos', 'requires_editing' => true]);
        $lines = collect(['delivered', 'ready', 'pending'])->map(fn ($status) => ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => $catalog->id, 'photographer_id' => $photographer->id,
            'price' => 100, 'quantity' => 1, 'workflow_status' => $status,
            'delivery_status' => $status === 'pending' ? 'not_started' : $status, 'is_deliverable' => true,
        ]));
        foreach ([$admin, $client, $photographer] as $viewer) {
            Sanctum::actingAs($viewer);
            $response = $this->getJson('/api/shoots/'.$shoot->id)->assertOk();
            $rows = collect($response->json('data.serviceItems'));
            foreach ($lines as $line) {
                $row = $rows->firstWhere('shoot_service_id', $line->id);
                $this->assertNotNull($row, $viewer->role.' retains the service row');
                $this->assertSame($line->workflow_status, $row['progress_status']);
                $this->assertSame($row['progress_status'], $row['progressStatus']);
            }
        }
    }

    public function test_showcase_order_projection_matches_the_exact_unit_without_mutation(): void
    {
        Event::fake();
        Queue::fake();
        $shoot = Shoot::factory()->create();
        $service = Service::factory()->create(['name' => 'Zillow SHOWCASE Premium']);
        $unitA = ShootUnit::create(['shoot_id' => $shoot->id, 'client_key' => 'a', 'label' => 'A', 'kind' => 'unit']);
        $unitB = ShootUnit::create(['shoot_id' => $shoot->id, 'client_key' => 'b', 'label' => 'B', 'kind' => 'unit']);
        $lines = collect([$unitA, $unitB])->map(fn ($unit) => ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => $service->id, 'shoot_unit_id' => $unit->id,
            'workflow_status' => 'pending', 'delivery_status' => 'not_started', 'price' => 100, 'quantity' => 1,
        ]));
        $connection = AryeoConnection::create(['name' => 'Test connection', 'company_key' => 'status-test',
            'client_ids' => [$shoot->client_id], 'token_hash' => hash('sha256', 'status-test-not-a-real-token')]);
        $order = AryeoRequest::create(['connection_id' => $connection->id, 'source_id' => 'source-test', 'request_id' => 'request-test',
            'shoot_id' => $shoot->id, 'unit_id' => $unitA->id, 'match_status' => 'matched', 'discovery' => ['required' => []]]);
        $this->mock(AryeoCatalog::class)->shouldReceive('readiness')->once()->withArgs(fn ($s, $unit) => $s->id === $shoot->id && $unit === $unitA->id)
            ->andReturn(['media_version' => 'v', 'blockers' => ['missing_floorplans'], 'assets' => [], 'tours' => []]);
        $before = $order->getRawOriginal();
        $result = app(ShootServiceProgress::class)->forItems($shoot, $lines->load('service.category', 'unit'));
        $this->assertSame('awaiting_assets', $result[$lines[0]->id]);
        $this->assertSame('received', $result[$lines[1]->id]);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('aryeo_jobs', 0);
    }
}
