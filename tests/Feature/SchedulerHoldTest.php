<?php

namespace Tests\Feature;

use App\Console\Scheduling\HoldAwareSchedule;
use App\Jobs\DispatchScheduledMessages;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SchedulerHoldTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/scheduler-hold-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
        config([
            'schedule.hold.enabled' => true,
            'schedule.hold.directory' => $this->directory,
            'schedule.hold.max_minutes' => 720,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_the_application_scheduler_skips_everything_while_a_deploy_hold_is_in_place(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $this->assertInstanceOf(HoldAwareSchedule::class, $schedule);
        $schedule->call(fn () => null)->name('hold-test:callback')->everyMinute();
        $this->assertContains('hold-test:callback', $this->dueNames($schedule));

        // Exactly the bytes Deploy-App.ps1 writes before `php artisan down`.
        file_put_contents(
            $this->directory.'/scheduler-hold.deploy.json',
            sprintf('{"hold":["*"],"reason":"deploy abc123","expires_at":%d}'."\n", time() + 1800),
        );

        $this->assertSame([], $this->dueNames($schedule));
    }

    public function test_a_hold_that_names_commands_holds_only_those(): void
    {
        $schedule = $this->freshSchedule();
        $this->writeHold('cutover', ['crm:send-due', DispatchScheduledMessages::class]);

        $this->assertSame(
            ['crm:nightly', 'floorplans:recover-provider-assets', 'crm:website-intake'],
            $this->dueNames($schedule),
        );
    }

    public function test_holds_from_several_owners_add_up(): void
    {
        $schedule = $this->freshSchedule();
        $this->writeHold('cutover', ['crm:send-due']);
        $this->writeHold('nightly', ['crm:nightly', 'floorplans:recover-provider-assets']);

        $this->assertSame(['crm:website-intake', DispatchScheduledMessages::class], $this->dueNames($schedule));
    }

    public function test_expired_malformed_or_overlong_holds_are_ignored(): void
    {
        $schedule = $this->freshSchedule();
        $everything = $this->dueNames($schedule);

        $this->writeHold('expired', ['*'], time() - 1);
        file_put_contents($this->directory.'/scheduler-hold.broken.json', '{"hold": ["*"');
        file_put_contents($this->directory.'/scheduler-hold.noexpiry.json', '{"hold":["*"]}');
        file_put_contents($this->directory.'/scheduler-hold.Bad Owner.json', '{"hold":["*"],"expires_at":'.(time() + 600).'}');
        // A hold may not outlive its file by more than the configured maximum.
        $stale = $this->writeHold('stale', ['*'], time() + 86400);
        touch($stale, time() - 721 * 60);

        $this->assertSame($everything, $this->dueNames($schedule));
    }

    public function test_holds_are_ignored_while_the_switch_is_off(): void
    {
        config(['schedule.hold.enabled' => false]);
        $schedule = $this->freshSchedule();
        $everything = $this->dueNames($schedule);
        $this->writeHold('deploy', ['*']);

        $this->assertCount(5, $everything);
        $this->assertSame($everything, $this->dueNames($schedule));
    }

    public function test_hold_command_sets_shows_and_releases_one_owner(): void
    {
        $this->writeHold('deploy', ['*']);

        $this->artisan('scheduler:hold', [
            'action' => 'set', 'owner' => 'cutover', '--only' => ['crm:send-due', 'crm:nightly'],
            '--minutes' => 90, '--reason' => 'drill',
        ])->assertSuccessful();

        $path = $this->directory.'/scheduler-hold.cutover.json';
        $this->assertFileExists($path);
        $hold = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['crm:send-due', 'crm:nightly'], $hold['hold']);
        $this->assertSame('drill', $hold['reason']);
        $this->assertEqualsWithDelta(time() + 90 * 60, $hold['expires_at'], 5);

        $this->artisan('scheduler:hold', ['action' => 'status'])
            ->expectsOutputToContain('cutover: crm:send-due, crm:nightly')
            ->expectsOutputToContain('deploy: *')
            ->assertSuccessful();

        $this->artisan('scheduler:hold', ['action' => 'release', 'owner' => 'cutover'])->assertSuccessful();
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($this->directory.'/scheduler-hold.deploy.json');
    }

    public function test_hold_command_refuses_unsafe_owner_names_and_lifetimes(): void
    {
        $this->artisan('scheduler:hold', ['action' => 'set', 'owner' => '../escape'])->assertFailed();
        $this->artisan('scheduler:hold', ['action' => 'set', 'owner' => 'cutover', '--minutes' => 721])->assertFailed();
        $this->artisan('scheduler:hold', ['action' => 'set', 'owner' => 'cutover', '--minutes' => 0])->assertFailed();
        $this->assertSame([], glob($this->directory.'/*'));
    }

    private function freshSchedule(): Schedule
    {
        $schedule = new HoldAwareSchedule('UTC');
        $schedule->command('crm:send-due')->everyMinute();
        $schedule->command('crm:nightly')->everyMinute();
        $schedule->command('floorplans:recover-provider-assets --limit=100')->everyMinute();
        $schedule->call(fn () => null)->name('crm:website-intake')->everyMinute();
        $schedule->job(new DispatchScheduledMessages)->everyMinute();

        return $schedule;
    }

    /** @return list<string> */
    private function dueNames(Schedule $schedule): array
    {
        return $schedule->dueEvents($this->app)
            ->map(fn (Event $event) => $event instanceof CallbackEvent
                ? $event->description
                : preg_replace('/\A.*\'?artisan\'?\s+(\S+).*\z/s', '$1', $event->command))
            ->values()
            ->all();
    }

    private function writeHold(string $owner, array $names, ?int $expiresAt = null): string
    {
        $path = $this->directory.'/scheduler-hold.'.$owner.'.json';
        file_put_contents($path, json_encode([
            'hold' => $names, 'reason' => 'test', 'expires_at' => $expiresAt ?? time() + 600,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
