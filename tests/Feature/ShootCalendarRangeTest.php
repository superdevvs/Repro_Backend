<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootHistoryService;
use App\Services\Shoots\ShootListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShootCalendarRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private User $photographer;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->photographer = User::factory()->create(['role' => 'photographer']);
        $this->service = Service::factory()->create();
    }

    public function test_scheduled_range_is_inclusive_and_applies_before_pagination_and_cache(): void
    {
        $expected = [];
        foreach (range(1, 13) as $index) {
            $expected[] = $this->shoot(['scheduled_date' => $index === 13 ? '2026-09-29' : '2026-09-28'])->id;
        }
        $before = $this->shoot(['scheduled_date' => '2026-09-27']);
        $this->shoot(['scheduled_date' => '2026-09-30']);
        $this->shoot(['scheduled_date' => null]);
        $params = ['scheduled_start' => '2026-09-28', 'scheduled_end' => '2026-09-29', 'per_page' => 9];
        $first = $this->listing($this->admin, $params);
        $second = $this->listing($this->admin, $params + ['page' => 2]);
        $this->assertSame($expected, [...array_column($first['data'], 'id'), ...array_column($second['data'], 'id')]);
        $this->assertSame(13, $first['meta']['count']);
        $this->assertSame(2, $first['meta']['last_page']);
        $this->assertSame([$before->id], array_column($this->listing($this->admin, ['scheduled_start' => '2026-09-27', 'scheduled_end' => '2026-09-27'])['data'], 'id'));
    }

    public function test_every_operational_tab_uses_the_booked_date_and_delivery_filters_keep_their_meaning(): void
    {
        foreach (['scheduled' => 'scheduled', 'completed' => 'uploaded', 'hold' => 'on_hold', 'delivered' => 'delivered', 'featured' => 'delivered'] as $tab => $status) {
            $client = User::factory()->create(['role' => 'client']);
            $base = ['client_id' => $client->id, 'status' => $status, 'workflow_status' => $status, 'is_featured' => $tab === 'featured'];
            $booked = $this->shoot($base + ['scheduled_date' => '2026-09-28', 'admin_verified_at' => '2026-10-02']);
            $delivered = $this->shoot($base + ['scheduled_date' => '2026-09-01', 'admin_verified_at' => '2026-09-28']);
            $params = ['tab' => $tab, 'client_id' => $client->id, 'scheduled_start' => '2026-09-28', 'scheduled_end' => '2026-09-28'];
            $this->assertSame([$booked->id], array_column($this->listing($this->admin, $params)['data'], 'id'), $tab);
            if ($tab === 'delivered') {
                $deliveryDates = ['tab' => $tab, 'client_id' => $client->id, 'date_from' => '2026-09-28', 'date_to' => '2026-09-28'];
                $this->assertSame([$delivered->id], array_column($this->listing($this->admin, $deliveryDates)['data'], 'id'));
                $this->assertSame([], $this->listing($this->admin, $params + $deliveryDates)['data']);
            }
        }
    }

    public function test_calendar_range_keeps_operational_role_and_requested_scopes(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $owned = $this->shoot(['editor_id' => $editor->id, 'status' => 'requested', 'workflow_status' => 'requested']);
        $other = $this->shoot(['client_id' => User::factory()->create(['role' => 'client'])->id, 'photographer_id' => null, 'editor_id' => null, 'status' => 'requested', 'workflow_status' => 'requested']);
        $this->shoot(['scheduled_date' => '2026-10-01', 'status' => 'requested', 'workflow_status' => 'requested']);
        $this->shoot();
        $params = ['scheduled_start' => '2026-09-28', 'scheduled_end' => '2026-09-28', 'scheduled_status' => 'requested'];
        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertSame([$owned->id, $other->id], array_column($this->listing($user, $params)['data'], 'id'), $role);
        }
        foreach ([$this->client, $this->photographer, $editor] as $user) {
            $this->assertSame([$owned->id], array_column($this->listing($user, $params)['data'], 'id'), $user->role);
        }
    }

    public function test_history_range_preserves_booking_clock_timezone_and_completed_date_filter(): void
    {
        $shoot = $this->shoot(['time' => '9:30 AM', 'timezone' => 'America/New_York', 'scheduled_at' => '2026-09-28 13:30:00', 'admin_verified_at' => '2026-10-02']);
        $this->shoot(['scheduled_date' => '2026-10-01']);
        $request = Request::create('/api/shoots/history', 'GET', ['scheduled_start' => '2026-09-28', 'scheduled_end' => '2026-09-28', 'completed_start' => '2026-10-01', 'sort' => 'date_asc']);
        $response = app(ShootHistoryService::class)->history($request, $this->admin);
        $this->assertSame(200, $response->status(), $response->getContent());
        $payload = $response->getData(true);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame($shoot->id, $payload['data'][0]['id']);
        $this->assertSame('2026-09-28', $payload['data'][0]['scheduledDate']);
        $this->assertSame('9:30 AM', $payload['data'][0]['time']);
        $this->assertSame('America/New_York', $payload['data'][0]['timezone']);
        $this->assertSame($shoot->scheduled_at->toIso8601String(), $payload['data'][0]['scheduledAt']);
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id, 'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_date' => '2026-09-28', 'scheduled_at' => null, 'timezone' => null, 'time' => '10:00',
        ], $attributes));
    }

    private function listing(User $user, array $params): array
    {
        $request = Request::create('/api/shoots', 'GET', $params + ['tab' => 'scheduled', 'sort' => 'date_asc']);
        $request->setUserResolver(fn () => $user);
        $response = app(ShootListingService::class)->index($request, $user, fn (Shoot $shoot) => ['id' => $shoot->id]);
        $this->assertSame(200, $response->status(), $response->getContent());
        return $response->getData(true);
    }
}
