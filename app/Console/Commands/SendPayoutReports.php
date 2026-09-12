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
            $sent += $this->sendPayoutReport($messagingService, $summary, $start, $end, 'photographer') ? 1 : 0;
        }

        foreach ($editorSummaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }
            $sent += $this->sendPayoutReport($messagingService, $summary, $start, $end, 'editor') ? 1 : 0;
        }

        foreach ($repSummaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }
            $sent += $this->sendPayoutReport($messagingService, $summary, $start, $end, 'sales rep') ? 1 : 0;
        }

        // Send accounting digest
        $accountingAddress = config('mail.accounting_address', 'accounting@reprophotos.com');
        $subject = sprintf('Payout approvals summary (%s - %s)', $start->format('M d'), $end->format('M d'));
        $rendered = app(\App\Services\SystemEmails\DirectEmailTemplates::class)->render('emails.payout-digest', [
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'photographers' => $photographerSummaries,
            'editors' => $editorSummaries,
            'reps' => $repSummaries,
            'totalPhotographerPayout' => $photographerSummaries->sum('gross_total'),
            'totalEditorPayout' => $editorSummaries->sum('gross_total'),
            'totalRepPayout' => $repSummaries->sum('payout_total'),
            'totalRepCommission' => $repSummaries->sum('commission_total'),
            'totalRepCompensation' => $repSummaries->sum('compensation_total'),
        ], $subject);

        $digestSent = false;
        try {
            if ($rendered !== null) {
                $messagingService->sendEmail([
                    'to' => $accountingAddress,
                    'subject' => $rendered['subject'],
                    'body_html' => $rendered['html'],
                    'body_text' => $rendered['text'],
                    'send_source' => 'PAYOUT_DIGEST',
                    'sender_name' => 'R/E Pro Photos',
                ]);
                $digestSent = true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send payout digest email', ['error' => $e->getMessage()]);
        }

        $this->info(sprintf(
            'Sent %d payout emails%s for %d photographers, %d editors, and %d reps.',
            $sent,
            $digestSent ? ' plus accounting digest' : '',
            $photographerSummaries->count(),
            $editorSummaries->count(),
            $repSummaries->count()
        ));

        return self::SUCCESS;
    }

    private function sendPayoutReport(MessagingService $messagingService, array $summary, $start, $end, string $audience): bool
    {
        $subject = sprintf('Weekly payout recap (%s - %s)', $start->format('M d'), $end->format('M d'));
        $rendered = app(\App\Services\SystemEmails\DirectEmailTemplates::class)->render('emails.payout-report', [
            'recipientName' => $summary['name'],
            'summary' => $summary,
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'audience' => $audience,
        ], $subject);
        if ($rendered === null) {
            return false;
        }

        try {
            $messagingService->sendEmail([
                'to' => $summary['email'],
                'subject' => $rendered['subject'],
                'body_html' => $rendered['html'],
                'body_text' => $rendered['text'],
                'send_source' => 'PAYOUT_REPORT',
                'sender_name' => 'R/E Pro Photos',
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payout report email', [
                'email' => $summary['email'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
