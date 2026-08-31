<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ReportingWeek;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InvoiceReportService
{
    public function summary(string $range = 'day'): array
    {
        $normalizedRange = $this->normalizeRange($range);
        [$start, $end] = $this->dateBounds($normalizedRange);

        $invoices = Invoice::query()
            ->whereNotNull('issue_date')
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->withSum([
                'payments as total_paid_amount' => function ($query) {
                    $query->where('status', Payment::STATUS_COMPLETED);
                },
            ], 'amount')
            ->get();

        $grouped = $invoices->groupBy(function (Invoice $invoice) use ($normalizedRange) {
            return $this->periodKey($invoice->issue_date, $normalizedRange);
        })->sortKeys();

        $breakdown = $grouped->map(function (Collection $groupInvoices, string $key) use ($normalizedRange) {
            $totals = $this->calculateTotals($groupInvoices);

            return array_merge(
                ['period' => $this->formatPeriodLabel($key, $normalizedRange)],
                $totals
            );
        })->values();

        $summaryTotals = $this->calculateTotals($invoices);

        return [
            'summary' => array_merge(
                [
                    'range' => $normalizedRange,
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ],
                $summaryTotals
            ),
            'breakdown' => $breakdown->map(function (array $row) {
                $row['total_invoiced'] = round($row['total_invoiced'], 2);
                $row['total_paid'] = round($row['total_paid'], 2);
                $row['total_outstanding'] = round($row['total_outstanding'], 2);

                return $row;
            })->all(),
        ];
    }

    public function pastDue(): array
    {
        $today = Carbon::now()->startOfDay();

        return Invoice::query()
            ->with(['client:id,name,company_name'])
            ->withSum([
                'payments as total_paid_amount' => function ($query) {
                    $query->where('status', Payment::STATUS_COMPLETED);
                },
            ], 'amount')
            ->whereNotNull('due_date')
            ->get()
            ->filter(function (Invoice $invoice) use ($today) {
                return $invoice->due_date !== null
                    && $invoice->due_date->lt($today)
                    && $this->outstandingFor($invoice) > 0;
            })
            ->sortBy('due_date')
            ->values()
            ->map(function (Invoice $invoice) {
                $paid = (float) ($invoice->total_paid_amount ?? 0);
                $balance = $this->outstandingFor($invoice);

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client' => $invoice->client ? [
                        'id' => $invoice->client->id,
                        'name' => $invoice->client->name,
                        'company_name' => $invoice->client->company_name,
                    ] : null,
                    'due_date' => $invoice->due_date ? $invoice->due_date->toDateString() : null,
                    'total' => round((float) $invoice->total, 2),
                    'total_paid' => round($paid, 2),
                    'balance_due' => round($balance, 2),
                ];
            })
            ->all();
    }

    protected function calculateTotals(Collection $invoices): array
    {
        $totalInvoiced = $invoices->sum(fn (Invoice $invoice) => (float) $invoice->total);
        $totalPaid = $invoices->sum(fn (Invoice $invoice) => (float) ($invoice->total_paid_amount ?? 0));
        $totalOutstanding = $invoices->sum(fn (Invoice $invoice) => $this->outstandingFor($invoice));

        return [
            'invoice_count' => $invoices->count(),
            'total_invoiced' => round($totalInvoiced, 2),
            'total_paid' => round($totalPaid, 2),
            'total_outstanding' => round($totalOutstanding, 2),
        ];
    }

    protected function outstandingFor(Invoice $invoice): float
    {
        $paid = (float) ($invoice->total_paid_amount ?? 0);

        return max((float) $invoice->total - $paid, 0);
    }

    protected function normalizeRange(string $range): string
    {
        $allowed = ['day', 'week', 'month', 'year'];

        return in_array($range, $allowed, true) ? $range : 'day';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function dateBounds(string $range): array
    {
        $now = Carbon::now();

        return match ($range) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => ReportingWeek::containing($now),
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    protected function periodKey(Carbon $issueDate, string $range): string
    {
        return match ($range) {
            'day' => $issueDate->format('Y-m-d'),
            'week' => $issueDate->copy()->startOfWeek(ReportingWeek::START_DAY)->toDateString(),
            'month' => $issueDate->format('Y-m'),
            'year' => $issueDate->format('Y'),
            default => $issueDate->format('Y-m-d'),
        };
    }

    protected function formatPeriodLabel(string $key, string $range): string
    {
        if ($range !== 'week') {
            return $key;
        }

        try {
            [$startOfWeek, $endOfWeek] = ReportingWeek::containing(Carbon::parse($key));
        } catch (\Throwable) {
            return $key;
        }

        return sprintf('%s - %s', $startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d'));
    }
}
