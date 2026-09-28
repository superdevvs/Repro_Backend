<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootHistoryService;
use App\Services\Shoots\ShootListingService;
use App\Services\Shoots\ShootListOrdering;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShootListOrderingTest extends TestCase
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
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-09-28 16:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->photographer = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $this->service = Service::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_next_up_orders_the_entire_filtered_schedule_before_pagination(): void
    {
        $future = [];
        foreach ([9, 2, 8, 1, 7, 3, 6, 5, 4, 10] as $day) {
            $future[$day] = $this->shoot(['scheduled_date' => '2026-10-'.sprintf('%02d', $day)])->id;
        }
        ksort($future);
        $past = $this->shoot(['scheduled_date' => '2026-09-27'])->id;
        $old = $this->shoot(['scheduled_date' => '2026-08-01'])->id;
        $missing = $this->shoot(['scheduled_date' => null, 'time' => null])->id;
        $current = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '12:00 PM'])->id;
        $later = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '1:00 PM'])->id;
        $earlier = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '11:00 AM'])->id;
        $this->shoot(['workflow_status' => Shoot::STATUS_REQUESTED, 'status' => Shoot::STATUS_REQUESTED]);
        $this->shoot(['workflow_status' => Shoot::STATUS_DELIVERED, 'status' => Shoot::STATUS_DELIVERED]);
        $params = ['sort' => 'next_up', 'per_page' => 9, 'scheduled_status' => 'scheduled', 'limit' => 1];
        $first = $this->listing($this->admin, $params);
        $second = $this->listing($this->admin, $params + ['page' => 2]);
        $expected = [$current, $later, ...array_values($future), $earlier, $past, $old, $missing];
        $this->assertSame(array_slice($expected, 0, 9), array_column($first['data'], 'id'));
        $this->assertSame(array_slice($expected, 9), array_column($second['data'], 'id'));
        $this->assertSame(16, $first['meta']['count']);
        $this->assertSame(2, $first['meta']['last_page']);
        $this->assertSame(2, $second['meta']['current_page']);
    }

    public function test_date_modes_normalize_booking_clocks_keep_missing_values_last_and_isolate_cache(): void
    {
        $afternoon = $this->shoot(['time' => '1:00 PM'])->id;
        $morning = $this->shoot(['time' => '09:30:00'])->id;
        $sameMorning = $this->shoot(['time' => '9:30 am'])->id;
        $noon = $this->shoot(['time' => '12:00 PM'])->id;
        $midnight = $this->shoot(['time' => '12:00 AM'])->id;
        $unknown = $this->shoot(['time' => null])->id;
        $invalidTime = $this->shoot(['time' => '25:90'])->id;
        $undated = $this->shoot(['scheduled_date' => null, 'time' => null])->id;
        $invalidDate = $this->shoot()->id;
        DB::table('shoots')->where('id', $invalidDate)->update(['scheduled_date' => '2026-02-30']);
        $before = $this->shoot(['scheduled_date' => '2026-09-28'])->id;
        $after = $this->shoot(['scheduled_date' => '2026-09-30'])->id;
        Cache::flush();
        $asc = $this->listing($this->admin, ['sort' => 'date_asc']);
        $desc = $this->listing($this->admin, ['sort' => 'date_desc']);
        $this->assertSame([$before, $midnight, $morning, $sameMorning, $noon, $afternoon, $unknown, $invalidTime, $after, $undated, $invalidDate], array_column($asc['data'], 'id'));
        $this->assertSame([$after, $afternoon, $noon, $morning, $sameMorning, $midnight, $unknown, $invalidTime, $before, $undated, $invalidDate], array_column($desc['data'], 'id'));
        $this->assertSame($asc, $this->listing($this->admin, ['sort' => 'date_asc']));
    }

    public function test_booking_fields_override_timestamp_and_timestamp_fallback_preserves_legacy_clock(): void
    {
        $booked = $this->shoot(['scheduled_date' => '2026-09-29T00:00:00.000000Z', 'time' => '9:00 AM',
            'scheduled_at' => '2026-10-20 20:00:00', 'timezone' => 'America/New_York'])->id;
        $legacy = $this->shoot(['scheduled_date' => null, 'time' => null, 'scheduled_at' => '2026-09-29 01:30:00', 'timezone' => null])->id;
        $zoned = $this->shoot(['scheduled_date' => null, 'time' => null, 'scheduled_at' => '2026-09-29 01:30:00', 'timezone' => 'America/New_York'])->id;
        $this->assertSame([$zoned, $legacy, $booked], array_column($this->listing($this->admin, ['sort' => 'date_asc'])['data'], 'id'));

        $localFuture = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '1:00 PM'])->id;
        $localPast = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '11:00 AM'])->id;
        $this->assertSame([$localFuture, $zoned, $legacy, $booked, $localPast], array_column($this->listing($this->admin, ['sort' => 'next_up'])['data'], 'id'));
    }

    public function test_next_up_uses_the_displayed_shoot_zone_when_the_photographer_zone_differs(): void
    {
        $this->photographer->update(['timezone' => 'America/Los_Angeles']);
        $past = $this->shoot(['scheduled_date' => null, 'time' => null, 'scheduled_at' => '2026-09-28 15:00:00', 'timezone' => 'America/New_York'])->id;
        $future = $this->shoot(['scheduled_date' => null, 'time' => null, 'scheduled_at' => '2026-09-28 17:00:00', 'timezone' => 'America/New_York'])->id;
        $booked = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '12:30 PM', 'timezone' => 'America/New_York'])->id;

        $this->assertSame([$booked, $future, $past], array_column($this->listing($this->admin, ['sort' => 'next_up'])['data'], 'id'));
    }

    public function test_sort_preserves_each_role_visibility_including_service_assignments(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $own = $this->shoot(['scheduled_date' => '2026-10-03', 'editor_id' => $editor->id]);
        $serviceAssigned = $this->shoot(['scheduled_date' => '2026-10-01', 'photographer_id' => null]);
        $serviceAssigned->services()->attach($this->service->id, [
            'price' => 100, 'photographer_id' => $this->photographer->id, 'editor_id' => $editor->id,
        ]);
        $unrelated = $this->shoot(['scheduled_date' => '2026-10-02',
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'photographer_id' => User::factory()->create(['role' => 'photographer'])->id]);
        foreach (['admin', 'superadmin', 'editing_manager', 'salesRep'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertSame([$serviceAssigned->id, $unrelated->id, $own->id], array_column($this->listing($user, ['sort' => 'date_asc'])['data'], 'id'), $role);
        }
        foreach ([$this->client, $this->photographer, $editor] as $user) {
            $result = $this->listing($user, ['sort' => 'date_asc']);
            $this->assertSame([$serviceAssigned->id, $own->id], array_column($result['data'], 'id'), $user->role);
            $this->assertSame(2, $result['meta']['count']);
        }
    }

    public function test_next_up_compares_real_instants_across_zones_and_preserves_local_date_modes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-28 12:00:00', 'UTC'));
        $la = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '09:00', 'timezone' => 'America/Los_Angeles'])->id;
        $ny = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '09:30', 'timezone' => 'America/New_York'])->id;
        $tie = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '12:00', 'timezone' => 'America/New_York'])->id;
        $params = ['sort' => 'next_up', 'no_cache' => 'true'];
        $this->assertSame([$ny, $la, $tie], array_column($this->listing($this->admin, $params)['data'], 'id'));
        $this->assertSame([$la, $ny, $tie], array_column($this->listing($this->admin, ['sort' => 'date_asc'])['data'], 'id'));
        $this->assertSame([$tie, $ny, $la], array_column($this->listing($this->admin, ['sort' => 'date_desc'])['data'], 'id'));

        Carbon::setTestNow(Carbon::parse('2026-09-28 18:00:00', 'UTC'));
        $this->assertSame([$la, $tie, $ny], array_column($this->listing($this->admin, $params)['data'], 'id'));
        Carbon::setTestNow(Carbon::parse('2026-09-28 13:30:59', 'UTC'));
        $this->assertSame([$ny, $la, $tie], array_column($this->listing($this->admin, $params)['data'], 'id'));
    }

    public function test_next_up_remains_chronological_across_local_day_boundaries_and_unknown_times(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00', 'UTC'));
        $laLate = $this->shoot(['scheduled_date' => '2026-09-29', 'time' => '23:00', 'timezone' => 'America/Los_Angeles'])->id;
        $tokyoNextDay = $this->shoot(['scheduled_date' => '2026-09-30', 'time' => '00:30', 'timezone' => 'Asia/Tokyo'])->id;
        $futureUnknown = $this->shoot(['scheduled_date' => '2026-09-29', 'time' => null, 'timezone' => 'America/New_York'])->id;
        $nyNextDay = $this->shoot(['scheduled_date' => '2026-09-30', 'time' => '01:00', 'timezone' => 'America/New_York'])->id;
        $nyPast = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '23:30', 'timezone' => 'America/New_York'])->id;
        $tokyoPast = $this->shoot(['scheduled_date' => '2026-09-29', 'time' => '09:00', 'timezone' => 'Asia/Tokyo'])->id;
        $tokyoEarly = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => '00:00', 'timezone' => 'Asia/Tokyo'])->id;
        $pastUnknown = $this->shoot(['scheduled_date' => '2026-09-28', 'time' => null, 'timezone' => 'America/Los_Angeles'])->id;
        $undated = $this->shoot(['scheduled_date' => null, 'time' => null])->id;
        $this->assertSame([$tokyoNextDay, $nyNextDay, $laLate, $futureUnknown, $nyPast, $tokyoPast, $tokyoEarly, $pastUnknown, $undated], array_column($this->listing($this->admin, ['sort' => 'next_up'])['data'], 'id'));
    }

    public function test_only_the_selected_page_loads_full_models_and_relations(): void
    {
        for ($day = 1; $day <= 15; $day++) {
            $this->shoot(['scheduled_date' => '2026-10-'.sprintf('%02d', $day)]);
        }
        DB::enableQueryLog();
        $result = app(ShootListOrdering::class)->paginate(Shoot::with('files'), 'date_asc', 9, 2);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(6, $result->items());
        $this->assertSame(15, $result->total());
        $mediaQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from "shoot_files"')));
        $this->assertCount(1, $mediaQueries);
        $expectedIds = implode(', ', array_map(fn ($shoot) => $shoot->id, $result->items()));
        $this->assertStringContainsString('in ('.$expectedIds.')', $mediaQueries[0]['query']);
        $this->assertStringNotContainsString('select *', $queries[0]['query']);
    }

    public function test_history_pagination_and_csv_share_sort_and_aggregates_are_unchanged(): void
    {
        $ids = [];
        foreach ([10, 3, 7, 1, 9, 4, 8, 2, 6, 5] as $day) {
            $shoot = $this->shoot(['scheduled_date' => '2026-09-'.sprintf('%02d', $day), 'address' => 'Sort Address '.$day,
                'workflow_status' => Shoot::STATUS_DELIVERED, 'status' => Shoot::STATUS_DELIVERED]);
            $shoot->services()->attach($this->service->id, ['price' => 100]);
            $ids[$day] = $shoot->id;
        }
        ksort($ids);
        $service = app(ShootHistoryService::class);
        $first = $service->history(Request::create('/api/shoots/history', 'GET', ['sort' => 'date_asc', 'per_page' => 9]), $this->admin)->getData(true);
        $second = $service->history(Request::create('/api/shoots/history', 'GET', ['sort' => 'date_asc', 'per_page' => 9, 'page' => 2]), $this->admin)->getData(true);
        $this->assertSame(array_values($ids), [...array_column($first['data'], 'id'), ...array_column($second['data'], 'id')]);
        $this->assertSame(10, $first['meta']['total']);
        $response = $service->exportHistory(Request::create('/api/shoots/history/export', 'GET', ['sort' => 'date_desc']), $this->admin);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        array_shift($rows);
        $this->assertSame(array_map(fn ($day) => '2026-09-'.sprintf('%02d', $day), range(10, 1)), array_column($rows, 0));
        $aggregates = fn (array $params) => $service->history(Request::create('/api/shoots/history', 'GET', ['group_by' => 'services'] + $params), $this->admin)->getData(true);
        $this->assertSame($aggregates([]), $aggregates(['sort' => 'date_asc']));
    }

    public function test_history_sort_keeps_role_scopes_and_access_boundaries(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $own = $this->shoot(['scheduled_date' => '2026-09-15', 'editor_id' => $editor->id]);
        $other = $this->shoot(['scheduled_date' => '2026-09-16', 'client_id' => User::factory()->create(['role' => 'client'])->id]);
        $service = app(ShootHistoryService::class);
        foreach (['admin', 'superadmin', 'editing_manager', 'finance', 'accounting', 'salesRep'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $service->history(Request::create('/api/shoots/history', 'GET', ['sort' => 'date_desc']), $user);
            $this->assertSame(200, $response->status(), $role);
            $this->assertSame([$other->id, $own->id], array_column($response->getData(true)['data'], 'id'), $role);
        }
        foreach ([$this->client, $editor] as $user) {
            $response = $service->history(Request::create('/api/shoots/history', 'GET', ['sort' => 'date_desc']), $user);
            $this->assertSame([$own->id], array_column($response->getData(true)['data'], 'id'), $user->role);
        }
        $this->assertSame(403, $service->history(Request::create('/api/shoots/history', 'GET', ['sort' => 'date_desc']), $this->photographer)->status());
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $this->client->id, 'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id, 'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED, 'scheduled_date' => '2026-09-29',
            'scheduled_at' => null, 'timezone' => null, 'time' => '10:00',
        ], $attributes));
    }

    private function listing(User $user, array $params = []): array
    {
        $request = Request::create('/api/shoots', 'GET', $params + ['tab' => 'scheduled', 'per_page' => 25]);
        $request->setUserResolver(fn () => $user);
        $response = app(ShootListingService::class)->index($request, $user, fn (Shoot $shoot) => ['id' => $shoot->id]);
        $this->assertSame(200, $response->status(), $response->getContent());

        return $response->getData(true);
    }
}
