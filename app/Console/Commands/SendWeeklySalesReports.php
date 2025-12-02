<?php

namespace App\Console\Commands;

use App\Services\SalesReportService;
use App\Services\MailService;
use Illuminate\Console\Command;

class SendWeeklySalesReports extends Command
{
    protected $signature = 'reports:sales:weekly';

    protected $description = 'Send weekly sales reports to all sales reps';

    public function handle(SalesReportService $salesReportService, MailService $mailService): int
    {
        $this->info('Generating weekly sales reports...');

        [$startDate, $endDate] = $salesReportService->getLastCompletedWeek();
        
        $this->info(sprintf('Period: %s to %s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d')));

        $reports = $salesReportService->generateWeeklyReportsForAllSalesReps($startDate, $endDate);

        if ($reports->isEmpty()) {
            $this->warn('No sales reps found.');
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($reports as $reportData) {
            $salesRepId = $reportData['sales_rep']['id'] ?? null;
            if (!$salesRepId) {
                continue;
            }

            $salesRep = \App\Models\User::find($salesRepId);
            if (!$salesRep) {
                $this->warn("Sales rep with ID {$salesRepId} not found.");
                $failed++;
                continue;
            }

            $this->info(sprintf('Sending report to %s (%s)...', $salesRep->name, $salesRep->email));

            if ($mailService->sendWeeklySalesReportEmail($salesRep, $reportData)) {
                $sent++;
                $this->info('✓ Sent successfully');
            } else {
                $failed++;
                $this->error('✗ Failed to send');
            }
        }

        $this->info(sprintf("\nCompleted: %d sent, %d failed", $sent, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}


