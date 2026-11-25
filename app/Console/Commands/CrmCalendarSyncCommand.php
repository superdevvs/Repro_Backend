<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Every five minutes: two-way time sync between CRM follow-ups and the
 * owner's Google Calendar. Does nothing until crm.google_sync.enabled is on,
 * or while crm.calendar_sync_hold is on (producer replay, CRM step 10).
 */
class CrmCalendarSyncCommand extends Command
{
    protected $signature = 'crm:calendar-sync {--limit=200}';

    protected $description = 'Sync Sales CRM follow-ups with connected Google Calendars.';

    public function handle(): int
    {
        if (! config('crm.enabled') || ! config('crm.google_sync.enabled')) {
            $this->line('Calendar sync is switched off.');

            return self::SUCCESS;
        }
        if (config('crm.calendar_sync_hold')) {
            // Only this job clears a cancelled follow-up's failed Google delete: the CRM refuses to save
            // a cancelled follow-up again, so that row blocks the reconcile until one sync runs.
            $this->line('Calendar sync is held (CRM_CALENDAR_SYNC_HOLD). Release it after crm:calendar-reconcile --dry-run exits 0.');
            $this->line('A planned follow-up that shows push_pending clears when it is saved again in the CRM.');
            $this->line('A cancelled one clears only when the sync runs: settle every other row, release CRM_CALENDAR_SYNC_HOLD, '
                .'let one sync run, then rerun crm:calendar-reconcile --dry-run, which must exit 0.');

            return self::SUCCESS;
        }
        $class = 'App\\Services\\Crm\\Calendar\\CrmCalendarSync';
        if (! class_exists($class)) {
            $this->warn('Calendar sync is not installed.');

            return self::SUCCESS;
        }
        $this->line('Calendar: '.json_encode(app($class)->pollAll((int) $this->option('limit'))));

        return self::SUCCESS;
    }
}
