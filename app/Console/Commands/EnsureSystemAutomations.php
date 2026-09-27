<?php

namespace App\Console\Commands;

use App\Services\Messaging\SystemAutomationDefaults;
use Illuminate\Console\Command;

class EnsureSystemAutomations extends Command
{
    protected $signature = 'automations:ensure-system';

    protected $description = 'Create missing system automations without changing saved settings or reactivating disabled rules';

    public function handle(SystemAutomationDefaults $defaults): int
    {
        $defaults->ensure();
        $this->info('Missing system automations created. Existing settings preserved.');

        return self::SUCCESS;
    }
}
