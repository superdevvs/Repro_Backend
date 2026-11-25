<?php

namespace App\Console\Commands;

use App\Support\SchedulerHold;
use Illuminate\Console\Command;
use RuntimeException;

class SchedulerHoldCommand extends Command
{
    protected $signature = 'scheduler:hold
        {action : set, release or status}
        {owner? : Who holds the scheduler, for example cutover}
        {--only=* : Hold only these scheduled task names (default: all)}
        {--minutes=30 : The hold ends by itself after this many minutes}
        {--reason= : A short note shown by status}';

    protected $description = 'Hold, release or list scheduler holds (config schedule.hold).';

    public function handle(): int
    {
        $hold = SchedulerHold::fromConfig();
        $action = (string) $this->argument('action');
        $owner = (string) $this->argument('owner');

        try {
            if ($action === 'set') {
                $hold->set($owner, (array) $this->option('only'), (int) $this->option('minutes'), (string) $this->option('reason'));
                $this->info("Scheduler hold '{$owner}' set.");
            } elseif ($action === 'release') {
                if (! $hold->release($owner)) {
                    throw new RuntimeException('Cannot remove the scheduler hold file.');
                }
                $this->info("Scheduler hold '{$owner}' released.");
            } elseif ($action !== 'status') {
                throw new RuntimeException('Use set, release or status.');
            }
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if (! config('schedule.hold.enabled')) {
            $this->warn('SCHEDULER_HOLD_ENABLED is off: the scheduler ignores holds.');
        }
        $active = $hold->active();
        if ($active === []) {
            $this->line('No active scheduler holds.');
        }
        foreach ($active as $name => $entry) {
            $this->line(sprintf('%s: %s (until %s UTC%s)', $name, implode(', ', $entry['hold']),
                gmdate('Y-m-d H:i', $entry['expires_at']), $entry['reason'] === '' ? '' : ', '.$entry['reason']));
        }

        return self::SUCCESS;
    }
}
