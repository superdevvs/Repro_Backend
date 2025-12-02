<?php

namespace App\Console\Commands;

use App\Mail\AccountingPayoutDigestMail;
use App\Mail\PayoutReportMail;
use App\Services\PayoutReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPayoutReports extends Command
{
    protected $signature = 'payouts:send';

    protected $description = 'Compile and email weekly payout approvals for reps and photographers.';

    public function handle(PayoutReportService $service): int
    {
        [$start, $end] = $service->lastCompletedWeekRange();

        $photographerSummaries = $service->buildPhotographerSummaries($start, $end);
        $repSummaries = $service->buildSalesRepSummaries($start, $end);

        $queued = 0;

        foreach ($photographerSummaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }
            Mail::to($summary['email'])->queue(
                new PayoutReportMail($summary['name'], $summary, $start, $end, 'photographer')
            );
            $queued++;
        }

        foreach ($repSummaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }
            Mail::to($summary['email'])->queue(
                new PayoutReportMail($summary['name'], $summary, $start, $end, 'sales rep')
            );
            $queued++;
        }

        $accountingAddress = config('mail.accounting_address', 'accounting@reprophotos.com');
        Mail::to($accountingAddress)->queue(
            new AccountingPayoutDigestMail($start, $end, $photographerSummaries, $repSummaries)
        );

        $this->info(sprintf(
            'Queued %d payout emails plus accounting digest for %d photographers and %d reps.',
            $queued,
            $photographerSummaries->count(),
            $repSummaries->count()
        ));

        return self::SUCCESS;
    }
}

