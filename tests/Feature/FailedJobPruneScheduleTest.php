<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

class FailedJobPruneScheduleTest extends TestCase
{
    public function test_failed_jobs_older_than_fourteen_days_are_pruned_daily(): void
    {
        $event = $this->pruneEvent();

        $this->assertStringEndsWith('queue:prune-failed --hours=336', $event->command);
        $this->assertSame('55 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_prune_stays_off_until_the_flag_is_enabled(): void
    {
        $event = $this->pruneEvent();

        config(['queue.failed.prune_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['queue.failed.prune_enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_prune_flag_is_off_by_default(): void
    {
        $this->assertFalse(config('queue.failed.prune_enabled'));
    }

    public function test_schedule_list_shows_the_prune_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('queue:prune-failed --hours=336')
            ->assertSuccessful();
    }

    private function pruneEvent(): Event
    {
        // bootstrap/app.php registers its schedule when the console starts.
        $this->app->make(ConsoleKernel::class)->all();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'queue:prune-failed'));
        $this->assertNotNull($event, 'queue:prune-failed is not scheduled.');

        return $event;
    }
}
