<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Crm\CrmEventService;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * Producer replay: the scheduler starts again at reopening and runs
 * crm:calendar-sync every five minutes. The runbook releases it only after
 * crm:calendar-reconcile (CRM step 10). CRM_CALENDAR_SYNC_HOLD keeps it from
 * calling Google or writing to the CRM until then.
 */
class CrmCalendarSyncHoldTest extends CrmTestCase
{
    private User $rep;

    /** @var array<string, array|null> What Google holds, by event id. */
    private array $remote = [];

    /** @var list<array{0: string, 1: string}> Every call made to Google: [method, event id]. */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['crm.google_sync.enabled' => true]);
        $this->rep = $this->rep();
        GoogleCalendarConnection::create(['user_id' => $this->rep->id, 'provider_email' => 'rep@example.test', 'calendar_id' => 'primary',
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'token_expires_at' => now()->addHour(), 'sync_enabled' => true]);
        $google = Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('createEvent')->andReturnUsing(function ($connection, array $payload) {
            $id = 'g_'.$payload['extendedProperties']['private']['repro_crm_event_id'];
            $this->calls[] = ['createEvent', $id];
            $this->remote[$id] = ['id' => $id, 'status' => 'confirmed', 'start' => $payload['start'], 'end' => $payload['end']];

            return ['id' => $id];
        });
        $google->shouldReceive('updateLinkedEvent')->andReturnUsing(function ($connection, string $calendarId, string $id, array $payload) {
            $this->calls[] = ['updateLinkedEvent', $id];
            $this->remote[$id] = ['id' => $id, 'status' => 'confirmed', 'start' => $payload['start'], 'end' => $payload['end']];

            return ['id' => $id];
        });
        $google->shouldReceive('deleteEvent')->andReturnUsing(function ($connection, string $calendarId, string $id) {
            $this->calls[] = ['deleteEvent', $id];
            unset($this->remote[$id]);
        });
        $google->shouldReceive('getEvent')->andReturnUsing(function ($connection, string $id) {
            $this->calls[] = ['getEvent', $id];

            return $this->remote[$id] ?? null;
        });
        $google->shouldNotReceive('getValidAccessToken');
        $this->app->instance(GoogleCalendarService::class, $google);
    }

    private function scheduled(Carbon $at): CrmEvent
    {
        return app(CrmEventService::class)->schedule($this->contactFor($this->rep),
            ['type' => 'call', 'starts_at' => $at->toIso8601String(), 'duration_min' => 30], $this->rep);
    }

    private function googleId(CrmEvent $event): string
    {
        return (string) DB::table('crm_event_calendar_links')->where('event_id', $event->id)->value('google_event_id');
    }

    private function remoteAt(Carbon $start, int $minutes = 30): array
    {
        return ['status' => 'confirmed', 'start' => ['dateTime' => $start->toRfc3339String()],
            'end' => ['dateTime' => $start->copy()->addMinutes($minutes)->toRfc3339String()]];
    }

    /**
     * Three follow-ups pushed to Google before the freeze, then changed while
     * the site was held: one moved in Google, one deleted in Google, and one
     * edited in the CRM with its push still pending while the rep also moved
     * it in Google. Each is a row crm:calendar-reconcile holds for a decision.
     *
     * @return array{0: CrmEvent, 1: CrmEvent, 2: CrmEvent, 3: Carbon}
     */
    private function changedDuringTheFreeze(): array
    {
        $base = now()->addDays(2)->setTime(15, 0)->utc();
        [$moved, $deleted, $pending] = [$this->scheduled($base), $this->scheduled($base), $this->scheduled($base)];
        $this->remote[$this->googleId($moved)] = $this->remoteAt($base->copy()->addHours(2), 45);
        unset($this->remote[$this->googleId($deleted)]);
        $this->remote[$this->googleId($pending)] = $this->remoteAt($base->copy()->addHours(3));
        CrmEvent::query()->whereKey($pending->id)->update(['starts_at' => $base->copy()->addDay()]);
        DB::table('crm_event_calendar_links')->where('event_id', $pending->id)->update(['push_pending' => true]);
        $this->calls = [];

        return [$moved, $deleted, $pending, $base];
    }

    private function reconcile(): array
    {
        $exit = Artisan::call('crm:calendar-reconcile', ['--dry-run' => true, '--json' => true]);

        return [$exit, json_decode(Artisan::output(), true)];
    }

    public function test_hold_is_off_by_default(): void
    {
        $this->assertFalse(config('crm.calendar_sync_hold'));
    }

    /**
     * Why the hold exists. Run before the reconcile, the sync overwrites the
     * rep's Google move with the pending CRM time, moves the CRM follow-up to
     * Google's time and unlinks the one deleted in Google. The reconcile then
     * exits 0 with nothing to decide: the decisions were made for the operator.
     */
    public function test_a_sync_run_before_the_reconcile_settles_every_row_without_a_decision(): void
    {
        [$moved, $deleted, $pending, $base] = $this->changedDuringTheFreeze();
        [$exit, $report] = $this->reconcile();
        $this->assertSame(1, $exit);
        $this->assertSame(['deleted_in_google' => 1, 'moved_in_google' => 1, 'push_pending' => 1], $report['counts']);

        $this->artisan('crm:calendar-sync')->assertSuccessful();

        $this->assertContains(['updateLinkedEvent', $this->googleId($pending)], $this->calls);
        $this->assertTrue(Carbon::parse($this->remote[$this->googleId($pending)]['start']['dateTime'])->equalTo($base->copy()->addDay()),
            'The pending CRM time replaced the time the rep set in Google.');
        $this->assertTrue($moved->fresh()->starts_at->equalTo($base->copy()->addHours(2)));
        $this->assertSame(45, (int) $moved->fresh()->duration_min);
        $this->assertDatabaseHas('crm_activities', ['type' => 'event_moved']);
        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $deleted->id, 'google_event_id' => null]);

        [$exit, $report] = $this->reconcile();
        $this->assertSame(0, $exit);
        $this->assertSame(['in_sync' => 2], $report['counts']);
    }

    public function test_held_sync_calls_google_for_nothing_and_writes_nothing_until_released(): void
    {
        [$moved, $deleted, $pending, $base] = $this->changedDuringTheFreeze();
        $deletedId = $this->googleId($deleted);
        config(['crm.calendar_sync_hold' => true]);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $exit = Artisan::call('crm:calendar-sync');

        $this->assertSame([], $this->calls);
        $this->assertSame([], $writes);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('held', Artisan::output());
        $this->assertTrue($moved->fresh()->starts_at->equalTo($base));
        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $deleted->id, 'google_event_id' => $deletedId]);
        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $pending->id, 'push_pending' => true]);
        [$exit, $report] = $this->reconcile();
        $this->assertSame(1, $exit);
        $this->assertSame(['deleted_in_google' => 1, 'moved_in_google' => 1, 'push_pending' => 1], $report['counts']);

        config(['crm.calendar_sync_hold' => false]);
        $this->artisan('crm:calendar-sync')->assertSuccessful();
        $this->assertContains(['updateLinkedEvent', $this->googleId($pending)], $this->calls);
    }

    /**
     * The hold covers the job only. Unlike CRM_GOOGLE_SYNC=false, which skips
     * the push on save and leaves no link for the poll to retry, a follow-up
     * saved while held still reaches Google.
     */
    public function test_a_follow_up_saved_while_held_is_still_pushed(): void
    {
        config(['crm.calendar_sync_hold' => true]);

        $event = $this->scheduled(now()->addDay()->setTime(10, 0)->utc());

        $this->assertSame([['createEvent', 'g_'.$event->id]], $this->calls);
        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $event->id, 'google_event_id' => 'g_'.$event->id, 'push_pending' => false]);
    }

    public function test_the_scheduler_skips_calendar_sync_while_held(): void
    {
        Artisan::call('schedule:list'); // starts Artisan, which registers the bootstrap/app.php schedule
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'crm:calendar-sync'));
        $this->assertCount(1, $events);
        $event = $events->first();

        $this->assertTrue($event->filtersPass($this->app));
        config(['crm.calendar_sync_hold' => true]);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
