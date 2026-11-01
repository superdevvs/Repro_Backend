<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShootScheduledLocalDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_date' => '2026-11-01', 'scheduled_at' => null,
            'timezone' => 'America/New_York', 'time' => '10:30',
            'is_private_listing' => false, 'is_listing_hidden' => false,
        ], $attributes));
    }

    private function listing(User $user, array $params = []): array
    {
        $request = Request::create('/api/shoots', 'GET', array_merge([
            'tab' => 'all', 'scheduled_local_date' => '2026-11-01',
            'per_page' => 9, 'include_filters' => 'false', 'include_files' => 'false',
        ], $params));
        $request->setUserResolver(fn () => $user);
        $response = app(ShootListingService::class)->index($request, $user,
            fn (Shoot $shoot) => ['id' => $shoot->id, 'status' => $shoot->status]
        );
        $this->assertSame(200, $response->getStatusCode());
        return $response->getData(true);
    }

    public function test_all_statuses_are_filtered_before_pagination_without_a_two_or_thirty_row_cap(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $statuses = ['requested', 'scheduled', 'uploaded', 'editing', 'review', 'ready', 'delivered', 'on_hold', 'cancelled'];
        $expected = [];
        for ($index = 0; $index < 36; $index++) {
            $status = $statuses[$index % count($statuses)];
            $expected[] = $this->shoot(['status' => $status, 'workflow_status' => $status])->id;
        }
        $this->shoot(['scheduled_date' => '2026-10-31']);
        $this->shoot(['scheduled_date' => '2026-11-02']);
        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $result = $this->listing($admin, ['page' => $page]);
            $this->assertSame(36, $result['meta']['count']);
            $this->assertSame(4, $result['meta']['last_page']);
            $this->assertSame('2026-11-01', $result['meta']['scheduled_local_date']);
            $this->assertCount(9, $result['data']);
            $seen = [...$seen, ...array_column($result['data'], 'id')];
        }
        $this->assertEqualsCanonicalizing($expected, $seen);
        $this->assertCount(36, array_unique($seen));
    }

    public function test_property_local_date_resolves_utc_midnight_dst_and_scheduled_at_only_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $late = $this->shoot(['scheduled_at' => '2026-11-02 00:30:00', 'scheduled_date' => '2026-11-02']);
        $atOnly = $this->shoot(['scheduled_at' => '2026-11-01 15:00:00', 'scheduled_date' => null]);
        $legacy = $this->shoot(['timezone' => null]);
        $dstFirst = $this->shoot(['scheduled_at' => '2026-11-01 05:30:00']);
        $dstSecond = $this->shoot(['scheduled_at' => '2026-11-01 06:30:00']);
        $this->shoot(['scheduled_at' => '2026-11-01 00:30:00']); // Property is still on October31.
        $this->shoot(['scheduled_date' => null, 'scheduled_at' => null]);
        $result = $this->listing($admin);
        $this->assertEqualsCanonicalizing([$late->id, $atOnly->id, $legacy->id, $dstFirst->id, $dstSecond->id], array_column($result['data'], 'id'));
        $this->assertSame(5, $result['meta']['count']);
    }

    public function test_client_and_photographer_scope_remains_in_force_for_date_counts_and_rows(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $mine = $this->shoot(['client_id' => $client->id, 'photographer_id' => $photographer->id]);
        $this->shoot();
        foreach ([$client, $photographer] as $viewer) {
            $result = $this->listing($viewer);
            $this->assertSame([$mine->id], array_column($result['data'], 'id'));
            $this->assertSame(1, $result['meta']['count']);
        }
    }

    public function test_date_cache_entries_and_requested_subfilters_stay_separate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $requested = $this->shoot(['status' => 'requested', 'workflow_status' => 'requested']);
        $this->shoot();
        $tomorrow = $this->shoot(['scheduled_date' => '2026-11-02']);
        $first = $this->listing($admin);
        $this->assertSame(2, $first['meta']['count']);
        $this->assertSame([$tomorrow->id], array_column($this->listing($admin, ['scheduled_local_date' => '2026-11-02'])['data'], 'id'));
        $filtered = $this->listing($admin, ['tab' => 'scheduled', 'scheduled_status' => 'requested']);
        $this->assertSame([$requested->id], array_column($filtered['data'], 'id'));
        $this->assertSame($first, $this->listing($admin));
    }

    public function test_today_uses_server_business_calendar_and_cache_rolls_over_at_its_midnight(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'timezone' => 'Asia/Kolkata']);
        config(['schedule.calendar_timezone' => 'America/New_York']);
        $yesterday = $this->shoot(['scheduled_date' => '2026-10-31']);
        $today = $this->shoot(['scheduled_date' => '2026-11-01']);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 03:59:59', 'UTC'));
        $first = $this->listing($admin, ['scheduled_local_date' => 'today']);
        $this->assertSame('2026-10-31', $first['meta']['scheduled_local_date']);
        $this->assertSame(['timezone' => 'America/New_York', 'today' => '2026-10-31'], $first['meta']['calendar_context']);
        $this->assertSame([$yesterday->id], array_column($first['data'], 'id'));
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 04:00:00', 'UTC'));
        $next = $this->listing($admin, ['scheduled_local_date' => 'today']);
        $this->assertSame('2026-11-01', $next['meta']['scheduled_local_date']);
        $this->assertSame([$today->id], array_column($next['data'], 'id'));
        $this->travelBack();
    }

    public function test_invalid_calendar_date_is_rejected_and_empty_date_has_an_empty_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['2026-02-30', '11/01/2026', 'not-a-date'] as $date) {
            $this->actingAs($admin)->getJson('/api/shoots?tab=all&scheduled_local_date='.urlencode($date))
                ->assertUnprocessable()->assertJsonValidationErrors('scheduled_local_date');
        }
        $result = $this->listing($admin);
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['meta']['count']);
        $this->assertSame(1, $result['meta']['last_page']);
    }
}
