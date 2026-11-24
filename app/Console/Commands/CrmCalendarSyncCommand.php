<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Every five minutes: two-way time sync between CRM follow-ups and the
 * owner's Google Calendar. Does nothing until crm.google_sync.enabled is on.
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
        $class = 'App\\Services\\Crm\\Calendar\\CrmCalendarSync';
        if (! class_exists($class)) {
            $this->warn('Calendar sync is not installed.');

            return self::SUCCESS;
        }
        $this->line('Calendar: '.json_encode(app($class)->pollAll((int) $this->option('limit'))));

        return self::SUCCESS;
    }
}
