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
use Illuminate\Validation\ValidationException;
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

    /** How many of the next Google calls of each method fail. */
    private array $failNext = ['deleteEvent' => 0, 'updateLinkedEvent' => 0];

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
            $this->failIfAsked('updateLinkedEvent', $id);
            $this->calls[] = ['updateLinkedEvent', $id];
            $this->remote[$id] = ['id' => $id, 'status' => 'confirmed', 'start' => $payload['start'], 'end' => $payload['end']];

            return ['id' => $id];
        });
        $google->shouldReceive('deleteEvent')->andReturnUsing(function ($connection, string $calendarId, string $id) {
            $this->failIfAsked('deleteEvent', $id);
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

    private function failIfAsked(string $method, string $id): void
    {
        if ($this->failNext[$method] > 0) {
            $this->failNext[$method]--;
            $this->calls[] = [$method.' failed', $id];

            throw new \RuntimeException('Google '.$method.' failed');
        }
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

    /** The operator reads this on the night, so it names the one row the hold itself keeps blocking. */
    public function test_held_sync_says_how_a_push_pending_row_clears(): void
    {
        config(['crm.calendar_sync_hold' => true]);

        $this->assertSame(0, Artisan::call('crm:calendar-sync'));

        $output = Artisan::output();
        $this->assertStringContainsString('Calendar sync is held (CRM_CALENDAR_SYNC_HOLD). Release it once crm:calendar-reconcile --dry-run '
            .'exits 0, or once only cancelled push_pending rows are left (see below).', $output);
        $this->assertStringNotContainsString('Release it after crm:calendar-reconcile --dry-run exits 0.', $output,
            'A cancelled push_pending row needs the release before the reconcile can exit 0.');
        $this->assertStringContainsString('A planned follow-up that shows push_pending clears when it is saved again in the CRM.', $output);
        $this->assertStringContainsString('A cancelled one clears only when the sync runs: settle every other row, '
            .'release CRM_CALENDAR_SYNC_HOLD, let one sync run, then rerun crm:calendar-reconcile --dry-run, which must exit 0.', $output);
    }

    /** The first half of the held-command guidance: saving the follow-up again pushes it and clears the row. */
    public function test_a_planned_follow_up_left_push_pending_clears_when_saved_again(): void
    {
        $event = $this->scheduled(now()->addDays(2)->setTime(11, 0)->utc());
        config(['crm.calendar_sync_hold' => true]);
        $this->failNext['updateLinkedEvent'] = 1;
        $moved = now()->addDays(3)->setTime(9, 0)->utc();
        app(CrmEventService::class)->update($event, ['starts_at' => $moved->toIso8601String()], $this->rep);
        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $event->id, 'push_pending' => true]);
        [$exit, $report] = $this->reconcile();
        $this->assertSame([1, ['push_pending' => 1]], [$exit, $report['counts']]);
        $this->assertArrayNotHasKey('hint', $report, 'A planned follow-up clears when it is saved again, not by releasing the sync.');

        app(CrmEventService::class)->update($event->fresh(), ['starts_at' => $moved->toIso8601String()], $this->rep);

        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $event->id, 'push_pending' => false]);
        $this->assertTrue(Carbon::parse($this->remote[$this->googleId($event)]['start']['dateTime'])->equalTo($moved));
        [$exit, $report] = $this->reconcile();
        $this->assertSame([0, ['in_sync' => 1]], [$exit, $report['counts']]);
    }

    /**
     * The second half: a follow-up cancelled while held, whose Google delete failed once,
     * cannot be saved again (only planned follow-ups can), so only one sync run clears it.
     */
    public function test_a_cancelled_follow_up_left_push_pending_clears_only_when_one_sync_runs(): void
    {
        $event = $this->scheduled(now()->addDays(2)->setTime(11, 0)->utc());
        $googleId = $this->googleId($event);
        $this->calls = [];
        config(['crm.calendar_sync_hold' => true]);
        $this->failNext['deleteEvent'] = 1;
        app(CrmEventService::class)->cancelPlanned($event->contact, $this->rep, 'Lost to a competitor');
        $this->assertSame([['deleteEvent failed', $googleId]], $this->calls);
        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $event->id, 'push_pending' => true]);
        [$exit, $report] = $this->reconcile();
        $this->assertSame([1, ['push_pending' => 1]], [$exit, $report['counts']]);
        $this->assertStringContainsString('release CRM_CALENDAR_SYNC_HOLD, let one sync run', $report['hint'] ?? '',
            'The reconcile the operator runs at CRM step 10 says how this row clears.');

        try {
            app(CrmEventService::class)->update($event->fresh(), ['note' => 'Saved again'], $this->rep);
            $this->fail('A cancelled follow-up was saved again.');
        } catch (ValidationException) {
        }
        Artisan::call('crm:calendar-sync');
        [$exit, $report] = $this->reconcile();
        $this->assertSame([1, ['push_pending' => 1]], [$exit, $report['counts']], 'The held sync cleared the row.');

        config(['crm.calendar_sync_hold' => false]);
        $this->artisan('crm:calendar-sync')->assertSuccessful();

        $this->assertContains(['deleteEvent', $googleId], $this->calls);
        $this->assertDatabaseHas('crm_event_calendar_links', ['event_id' => $event->id, 'google_event_id' => null, 'push_pending' => false]);
        [$exit, $report] = $this->reconcile();
        $this->assertSame(0, $exit);
        $this->assertSame([], $report['counts']);
        $this->assertArrayNotHasKey('hint', $report);
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
