<?php

namespace App\Console\Commands;

use App\Services\Messaging\MessagingService;
use App\Services\PayoutReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendPayoutReports extends Command
{
    protected $signature = 'payouts:send';

    protected $description = 'Compile and email weekly payout approvals for reps and photographers.';

    public function handle(PayoutReportService $service, MessagingService $messagingService): int
    {
        [$start, $end] = $service->lastCompletedWeekRange();

        $photographerSummaries = $service->buildPhotographerSummaries($start, $end);
        $editorSummaries = $service->buildEditorSummaries($start, $end);
        $repSummaries = $service->buildSalesRepSummaries($start, $end);

        $sent = 0;

        foreach ($photographerSummaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }
            $this->sendPayoutReport($messagingService, $summary, $start, $end, 'photographer');
            $sent++;
        }

        foreach ($editorSummaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }
            $this->sendPayoutReport($messagingService, $summary, $start, $end, 'editor');
            $sent++;
        }

        foreach ($repSummaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }
            $this->sendPayoutReport($messagingService, $summary, $start, $end, 'sales rep');
            $sent++;
        }

        // Send accounting digest
        $accountingAddress = config('mail.accounting_address', 'accounting@reprophotos.com');
        $subject = sprintf('Payout approvals summary (%s - %s)', $start->format('M d'), $end->format('M d'));
        $html = view('emails.payout-digest', [
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'photographers' => $photographerSummaries,
            'editors' => $editorSummaries,
            'reps' => $repSummaries,
            'totalPhotographerPayout' => $photographerSummaries->sum('gross_total'),
            'totalEditorPayout' => $editorSummaries->sum('gross_total'),
            'totalRepPayout' => $repSummaries->sum('commission_total'),
        ])->render();

        try {
            $messagingService->sendEmail([
                'to' => $accountingAddress,
                'subject' => $subject,
                'body_html' => $html,
                'body_text' => strip_tags($html),
                'send_source' => 'PAYOUT_DIGEST',
                'sender_name' => 'R/E Pro Photos',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send payout digest email', ['error' => $e->getMessage()]);
        }

        $this->info(sprintf(
            'Sent %d payout emails plus accounting digest for %d photographers, %d editors, and %d reps.',
            $sent,
            $photographerSummaries->count(),
            $editorSummaries->count(),
            $repSummaries->count()
        ));

        return self::SUCCESS;
    }

    private function sendPayoutReport(MessagingService $messagingService, array $summary, $start, $end, string $audience): void
    {
        $subject = sprintf('Weekly payout recap (%s - %s)', $start->format('M d'), $end->format('M d'));
        $html = view('emails.payout-report', [
            'recipientName' => $summary['name'],
            'summary' => $summary,
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'audience' => $audience,
        ])->render();

        try {
            $messagingService->sendEmail([
                'to' => $summary['email'],
                'subject' => $subject,
                'body_html' => $html,
                'body_text' => strip_tags($html),
                'send_source' => 'PAYOUT_REPORT',
                'sender_name' => 'R/E Pro Photos',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send payout report email', [
                'email' => $summary['email'],
                'error' => $e->getMessage(),
            ]);
        }
    }
}

