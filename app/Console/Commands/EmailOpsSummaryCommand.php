<?php

namespace App\Console\Commands;

use App\Services\Messaging\EmailOpsSummaryService;
use Illuminate\Console\Command;

class EmailOpsSummaryCommand extends Command
{
    protected $signature = 'messaging:email-ops-summary
        {--sample=5 : Maximum affected rows to print per category}
        {--queued-minutes=5 : Consider queued outbound messages older than this many minutes as stuck}';

    protected $description = 'Print a compact operational summary for transactional email health and blocking issues.';

    public function __construct(
        private readonly EmailOpsSummaryService $emailOpsSummaryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->emailOpsSummaryService->build(
            max(1, (int) $this->option('sample')),
            max(1, (int) $this->option('queued-minutes')),
        );

        $health = $summary['health'];
        $counts = $summary['counts'];

        $this->info('Email operations summary');
        $this->line(sprintf(
            'healthy=%s failure_type=%s queued_retry_threshold_minutes=%d blocking_issues_present=%s',
            ($health['healthy'] ?? false) ? 'true' : 'false',
            $health['failure_type'] ?? 'none',
            $summary['queued_retry_threshold_minutes'] ?? 5,
            ($summary['blocking_issues_present'] ?? false) ? 'true' : 'false',
        ));
        $this->newLine();

        foreach ($counts as $key => $count) {
            $this->line(sprintf('count.%s=%d', $key, (int) $count));
        }

        $this->newLine();
        $this->renderSampleSection('live_shoots_missing_client_email', $summary['samples']['live_shoots_missing_client_email'] ?? []);
        $this->newLine();
        $this->renderSampleSection('failed_messages', $summary['samples']['failed_messages'] ?? []);
        $this->newLine();
        $this->renderSampleSection('queued_messages', $summary['samples']['queued_messages'] ?? []);
        $this->newLine();
        $this->renderSampleSection('failed_client_confirmations', $summary['samples']['failed_client_confirmations'] ?? []);
        $this->newLine();
        $this->renderSampleSection('skipped_client_confirmations', $summary['samples']['skipped_client_confirmations'] ?? []);

        return ($summary['blocking_issues_present'] ?? false) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderSampleSection(string $title, array $rows): void
    {
        $this->info($title);

        if ($rows === []) {
            $this->line('none');
            return;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                'kind=%s shoot_id=%s client_id=%s recipient_type=%s trigger_source=%s latest_reason="%s"',
                $row['kind'] ?? 'unknown',
                $row['shoot_id'] ?? 'null',
                $row['client_id'] ?? 'null',
                $row['recipient_type'] ?? 'unknown',
                $row['trigger_source'] ?? 'unknown',
                str_replace('"', '\"', (string) ($row['latest_reason'] ?? 'unknown'))
            ));
        }
    }
}
