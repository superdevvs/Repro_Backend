<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * Producer replay (P2-08): before crm:calendar-sync is released after the
 * switch, a dry run compares every linked follow-up with Google, including
 * changes the poller cannot see, and writes nothing.
 */
class CrmCalendarReconcileTest extends CrmTestCase
{
    private User $rep;

    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->rep = $this->rep();
        GoogleCalendarConnection::create(['user_id' => $this->rep->id, 'provider_email' => 'rep@example.test', 'calendar_id' => 'primary',
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'token_expires_at' => now()->addHour(), 'sync_enabled' => true]);
        $google = Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('getEvent')->andReturnUsing(fn ($connection, string $id) => $this->remote[$id] ?? null);
        $google->shouldNotReceive('getValidAccessToken', 'createEvent', 'updateLinkedEvent', 'deleteEvent');
        $this->app->instance(GoogleCalendarService::class, $google);
    }

    /** A planned follow-up linked to Google event $googleId, as the sync leaves it. */
    private function linked(string $googleId, Carbon $startsAt, array $link = [], array $event = []): CrmEvent
    {
        $contact = $this->contactFor($this->rep);
        $model = CrmEvent::query()->forceCreate(array_merge(['contact_id' => $contact->id, 'assigned_user_id' => $this->rep->id,
            'type_id' => (int) DB::table('crm_event_types')->value('id'), 'status' => 'planned', 'starts_at' => $startsAt,
            'duration_min' => 30], $event));
        DB::table('crm_event_calendar_links')->insert(array_merge(['event_id' => $model->id, 'user_id' => $this->rep->id,
            'calendar_id' => 'primary', 'google_event_id' => $googleId, 'fingerprint' => str_repeat('a', 64), 'push_pending' => false,
            'last_synced_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()], $link));

        return $model;
    }

    private function remoteAt(Carbon $start, int $minutes = 30): array
    {
        return ['status' => 'confirmed', 'start' => ['dateTime' => $start->toRfc3339String()],
            'end' => ['dateTime' => $start->copy()->addMinutes($minutes)->toRfc3339String()]];
    }

    private function reconcile(array $options = []): array
    {
        $exit = Artisan::call('crm:calendar-reconcile', ['--dry-run' => true, '--json' => true] + $options);

        return [$exit, json_decode(Artisan::output(), true)];
    }

    public function test_dry_run_reports_moved_deleted_out_of_window_and_pending_push_without_writing(): void
    {
        $base = now()->addDays(2)->setTime(15, 0)->utc();
        $inSync = $this->linked('g_same', $base);
        $this->remote['g_same'] = $this->remoteAt($base);
        $moved = $this->linked('g_moved', $base);
        $this->remote['g_moved'] = $this->remoteAt($base->copy()->addHours(2), 45);
        $deleted = $this->linked('g_deleted', $base);
        $far = $this->linked('g_far', now()->addDays(90)->setTime(10, 0)->utc());
        $this->remote['g_far'] = $this->remoteAt(now()->addDays(91)->setTime(10, 0)->utc());
        $pending = $this->linked('g_pending', $base, ['push_pending' => true]);
        $this->remote['g_pending'] = $this->remoteAt($base->copy()->addDay());
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        [$exit, $report] = $this->reconcile();

        $this->assertSame([], $writes);
        $this->assertSame(1, $exit);
        $rows = collect($report['rows'])->keyBy('event_id');
        $this->assertSame('in_sync', $rows[$inSync->id]['category']);
        $this->assertSame('moved_in_google', $rows[$moved->id]['category']);
        $this->assertSame(45, $rows[$moved->id]['remote_duration_min']);
        $this->assertSame('deleted_in_google', $rows[$deleted->id]['category']);
        $this->assertSame('moved_in_google', $rows[$far->id]['category']);
        $this->assertFalse($rows[$far->id]['in_poll_window']);
        $this->assertSame('push_pending', $rows[$pending->id]['category']);
        $this->assertTrue($rows[$pending->id]['remote_differs']);
        $this->assertSame(['deleted_in_google' => 1, 'in_sync' => 1, 'moved_in_google' => 2, 'push_pending' => 1], $report['counts']);
        $this->assertSame(30, (int) $moved->fresh()->duration_min);
        $this->assertTrue($base->equalTo($moved->fresh()->starts_at));
    }

    public function test_expired_tokens_are_reported_not_refreshed_unless_allowed(): void
    {
        GoogleCalendarConnection::query()->update(['token_expires_at' => now()->subMinute()]);
        $this->linked('g_expired', now()->addDay()->utc());

        [$exit, $report] = $this->reconcile();

        $this->assertSame(1, $exit);
        $this->assertSame(['token_refresh_needed' => 1], $report['counts']);
    }

    public function test_all_in_sync_exits_zero_and_the_command_refuses_to_run_without_dry_run(): void
    {
        $start = now()->addDay()->setTime(9, 0)->utc();
        $this->linked('g_ok', $start);
        $this->remote['g_ok'] = $this->remoteAt($start);

        $this->assertSame(0, $this->reconcile()[0]);
        $this->assertSame(2, Artisan::call('crm:calendar-reconcile'));
    }
}
