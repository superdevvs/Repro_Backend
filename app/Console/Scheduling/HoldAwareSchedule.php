<?php

namespace App\Console\Scheduling;

use App\Support\SchedulerHold;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The framework schedule, except that `schedule:run` leaves out tasks named
 * by an active scheduler hold (config schedule.hold). Nothing else changes.
 */
class HoldAwareSchedule extends Schedule
{
    public function dueEvents($app)
    {
        $due = parent::dueEvents($app);
        $held = config('schedule.hold.enabled') ? SchedulerHold::fromConfig()->heldNames() : [];
        if ($held === []) {
            return $due;
        }

        return $due->reject(fn (Event $event) => SchedulerHold::isHeld($event, $held))->values();
    }
}
