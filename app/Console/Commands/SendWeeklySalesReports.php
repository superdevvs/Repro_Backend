<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SendWeeklySalesReports extends Command
{
    protected $signature = 'reports:sales:weekly';

    protected $description = 'Run the enabled weekly sales-report workflows for the last completed week';

    public function handle(): int
    {
        return $this->call('automations:run-system', ['--trigger' => 'WEEKLY_SALES_REPORT', '--force' => true]);
    }
}
