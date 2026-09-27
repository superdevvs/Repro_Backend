<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SendPayoutReports extends Command
{
    protected $signature = 'payouts:send';

    protected $description = 'Run the saved weekly payout report and accounting digest workflows';

    public function handle(): int
    {
        $reports = $this->call('automations:run-system', ['--trigger' => 'WEEKLY_PAYOUT_REPORT', '--force' => true]);
        $digest = $this->call('automations:run-system', ['--trigger' => 'WEEKLY_PAYOUT_DIGEST', '--force' => true]);

        return $reports === self::SUCCESS && $digest === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
