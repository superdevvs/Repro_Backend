<?php

namespace App\Console\Commands;

use App\Services\Invoices\WeeklyBillingGapAudit;
use App\Support\ReadOnlyDatabase;
use Carbon\Carbon;
use Illuminate\Console\Command;

class WeeklyBillingGapDryRun extends Command
{
    protected $signature = 'billing:weekly-gap-dry-run
        {--periods=8 : Number of completed Sunday-to-Saturday weeks to check}
        {--through= : Check the weeks completed before this date (default: today)}
        {--json : Print the full result as JSON}';

    protected $description = 'Read-only: list shoots and pay lines the weekly invoice and payout runs skipped because they compare dates as text';

    public function handle(WeeklyBillingGapAudit $audit): int
    {
        $count = (int) $this->option('periods');
        if ($count < 1 || $count > 104) {
            $this->error('--periods must be between 1 and 104.');

            return self::FAILURE;
        }
        $through = $this->option('through') ? Carbon::parse((string) $this->option('through')) : null;

        $report = ReadOnlyDatabase::run(function () use ($audit, $count, $through) {
            $periods = [];
            $skipped = [];
            $onlyText = [];
            foreach ($audit->periods($count, $through) as [$start, $end]) {
                $selections = [];
                foreach ($audit->audit($start, $end) as $selection => $result) {
                    $selections[$selection] = [
                        'selected_now' => $result['selected_now'],
                        'selected_date_correct' => $result['selected_date_correct'],
                        'skipped' => count($result['skipped']),
                        'selected_only_by_text' => count($result['selected_only_by_text']),
                    ];
                    $context = ['period_start' => $start->toDateString(), 'selection' => $selection];
                    foreach ($result['skipped'] as $record) {
                        $skipped[] = $context + $record;
                    }
                    foreach ($result['selected_only_by_text'] as $record) {
                        $onlyText[] = $context + $record;
                    }
                }
                $periods[] = ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'selections' => $selections];
            }

            return [
                'read_only' => true,
                'periods' => $periods,
                'skipped' => $skipped,
                'selected_only_by_text' => $onlyText,
                'totals' => [
                    'skipped' => count($skipped),
                    'unbilled' => count(array_filter($skipped, fn (array $record) => $record['billed_invoice_ids'] === [])),
                ],
            ];
        });

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info(sprintf('Read-only dry run of the weekly invoice and payout selections: %d week(s), %s to %s.',
            count($report['periods']), $report['periods'][0]['start'], $report['periods'][array_key_last($report['periods'])]['end']));
        $rows = [];
        foreach ($report['periods'] as $period) {
            foreach ($period['selections'] as $selection => $counts) {
                $rows[] = [$period['start'].' to '.$period['end'], $selection, $counts['selected_now'],
                    $counts['selected_date_correct'], $counts['skipped'], $counts['selected_only_by_text']];
            }
        }
        $this->table(['Week', 'Selection', 'Picked now', 'Date-correct', 'Skipped', 'Picked only by text'], $rows);

        $this->newLine();
        $this->info('Skipped by the current text comparison:');
        $describe = fn (array $record) => [
            $record['period_start'], $record['selection'], $record['record_type'].' '.$record['record_id'],
            $record['shoot_id'] ?? '-', $record['stored_value'], $record['recipient_id'] ?? '-',
            $record['billed_invoice_ids'] === null ? 'n/a (report)' : ($record['billed_invoice_ids'] === [] ? 'none' : implode(', ', $record['billed_invoice_ids'])),
        ];
        $headers = ['Week starting', 'Selection', 'Record', 'Shoot', 'Stored value', 'Recipient', 'Invoice lines'];
        $report['skipped'] === [] ? $this->line('None.') : $this->table($headers, array_map($describe, $report['skipped']));
        if ($report['selected_only_by_text'] !== []) {
            $this->newLine();
            $this->warn('Picked by the current text comparison but outside the week by date:');
            $this->table($headers, array_map($describe, $report['selected_only_by_text']));
        }
        $this->newLine();
        $this->line(sprintf('%d skipped record(s); %d with no invoice line. Nothing was written.',
            $report['totals']['skipped'], $report['totals']['unbilled']));

        return self::SUCCESS;
    }
}
