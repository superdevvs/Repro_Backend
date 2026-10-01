<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardOpenShootListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_staff_open_projection_keeps_old_ready_work_and_excludes_terminal_work(): void
    {
        $editing = $this->shoot('editing');
        $ready = $this->shoot('ready', ['editing_completed_at' => now(), 'payment_status' => 'paid']);
        $this->shoot('delivered', ['payment_status' => 'unpaid']);
        $this->shoot('ready_for_client');
        $this->shoot('admin_verified');
        $this->shoot('cancelled');
        $this->shoot('requested');
        $this->shoot('delivered', ['status' => 'uploaded']);

        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep'] as $role) {
            $viewer = User::factory()->create(['role' => $role]);
            $this->assertEqualsCanonicalizing([$editing->id, $ready->id], $this->ids($this->listing($viewer)));
        }
    }

    public function test_ready_projection_paginates_independently_from_delivered_history_and_cache(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $editing = $this->shoot('editing');
        $ready = $this->shoot('ready');
        $readyIds = [$ready->id];
        for ($index = 0; $index < 8; $index++) {
            $copy = $ready->replicate();
            $copy->save();
            $readyIds[] = $copy->id;
        }
        $this->shoot('delivered');
        $ordinary = $this->listing($viewer, ['dashboard_open' => false, 'per_page' => 9]);
        $this->assertSame(1, $ordinary['meta']['count']);
        $first = $this->listing($viewer, ['per_page' => 9]);
        $second = $this->listing($viewer, ['per_page' => 9, 'page' => 2]);
        $this->assertSame(10, $first['meta']['count']);
        $this->assertSame(2, $first['meta']['last_page']);
        $this->assertEqualsCanonicalizing([$editing->id, ...$readyIds], [...$this->ids($first), ...$this->ids($second)]);
        $this->assertSame($ordinary, $this->listing($viewer, ['dashboard_open' => false, 'per_page' => 9]));
    }

    public function test_photographer_projection_keeps_assignment_boundaries(): void
    {
        $viewer = User::factory()->create(['role' => 'photographer']);
        $assigned = $this->shoot('ready', ['photographer_id' => $viewer->id]);
        $serviceAssigned = $this->shoot('ready', ['photographer_id' => null]);
        $service = Service::factory()->create();
        $serviceAssigned->services()->attach($service->id, ['photographer_id' => $viewer->id]);
        $this->shoot('ready');
        $this->assertEqualsCanonicalizing([$assigned->id, $serviceAssigned->id], $this->ids($this->listing($viewer)));
    }

    public function test_editor_keeps_only_their_unfinished_lane_after_global_delivery(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $service = Service::factory()->create();
        $pending = $this->shoot('delivered');
        $pending->services()->attach($service->id, [
            'editor_id' => $other->id, 'editing_completed_at' => now(),
            'video_editor_id' => $viewer->id, 'video_editing_completed_at' => null,
        ]);
        $finished = $this->shoot('delivered');
        $finished->services()->attach($service->id, [
            'video_editor_id' => $viewer->id, 'video_editing_completed_at' => now(),
        ]);
        $unassigned = $this->shoot('delivered');
        $unassigned->services()->attach($service->id, ['video_editor_id' => $other->id]);
        $ready = $this->shoot('ready', ['editor_id' => $viewer->id]);
        $this->assertEqualsCanonicalizing([$pending->id, $ready->id], $this->ids($this->listing($viewer)));
    }

    public function test_client_flag_does_not_change_existing_listing_or_access(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $editing = $this->shoot('editing', ['client_id' => $viewer->id]);
        $this->shoot('ready', ['client_id' => $viewer->id]);
        $this->shoot('editing');
        $plain = $this->listing($viewer, ['dashboard_open' => false]);
        $this->assertSame([$editing->id], $this->ids($plain));
        $this->assertSame($plain, $this->listing($viewer));
    }

    private function shoot(string $status, array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => $status, 'workflow_status' => $status,
            'scheduled_date' => now()->subMonths(3)->toDateString(),
            'is_private_listing' => false, 'is_listing_hidden' => false,
        ], $attributes));
    }

    private function ids(array $payload): array
    {
        return array_column($payload['data'], 'id');
    }

    private function listing(User $viewer, array $params = []): array
    {
        $request = Request::create('/api/shoots', 'GET', array_merge([
            'tab' => 'completed', 'dashboard_open' => true,
        ], $params));
        $request->setUserResolver(fn () => $viewer);
        $response = app(ShootListingService::class)->index($request, $viewer, fn (Shoot $shoot) => ['id' => $shoot->id]);
        $this->assertSame(200, $response->status(), $response->getContent());
        return $response->getData(true);
    }
}
