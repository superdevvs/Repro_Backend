<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SendWeeklyInvoiceSummaries extends Command
{
    protected $signature = 'messaging:invoice-summaries';

    protected $description = 'Run the saved weekly client and rep invoice-summary workflows';

    public function handle(): int
    {
        $client = $this->call('automations:run-system', ['--trigger' => 'INVOICE_SUMMARY', '--force' => true]);
        $rep = $this->call('automations:run-system', ['--trigger' => 'WEEKLY_REP_INVOICE', '--force' => true]);

        return $client === self::SUCCESS && $rep === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
