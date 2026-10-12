<?php

namespace App\Services\Messaging;

use App\Models\Invoice;
use App\Models\User;
use App\Support\ReportingWeek;

class WeeklyAutomationContext
{
    /** One invoice email per photographer, including any supplemental invoices. */
    public function photographerInvoiceBatches(): iterable
    {
        [$start, $end] = ReportingWeek::lastCompleted();
        $invoices = Invoice::with(['photographer', 'items'])->where('role', Invoice::ROLE_PHOTOGRAPHER)
            ->whereDate('billing_period_start', $start->toDateString())->whereDate('billing_period_end', $end->toDateString())
            ->whereNotIn('status', ['cancelled', 'canceled', 'void'])->where('total_amount', '>', 0)->orderBy('id')->get();
        foreach ($invoices->groupBy('photographer_id') as $recipientId => $batch) {
            $invoice = $batch->first();
            if (! $invoice->photographer || ($invoice->photographer->account_status ?? 'active') !== 'active') continue;
            $context = $this->invoice($invoice);
            $items = $batch->flatMap->items;
            $lines = $items->map(fn ($item) => trim((string) $item->description).' — $'.number_format((float) $item->total_amount, 2));
            $reconciliation = app(\App\Services\Invoices\PhotographerPayoutReconciliation::class)->rows($start, $end, true, (int)$recipientId);
            $pending = $reconciliation->where('eligible', false)->whereNull('owner_invoice_id');
            $pendingCount = $pending->pluck('shoot_id')->unique()->count();
            $note = 'Submitted / uploaded photographer work and completed floorplans are included without waiting for editing or client delivery. Approval and payment remain separate.';
            if ($pendingCount) $note .= ' '.$pendingCount.' '.($pendingCount === 1 ? 'shoot' : 'shoots').' awaiting photographer completion — $'.number_format($pending->sum('photographer_pay'), 2).' excluded from this invoice.';
            $pendingLines = $pending->map(fn ($row) => $row['address'].' · '.$row['service_name'].' — $'.number_format($row['photographer_pay'],2).' · '.$row['pending_reason']);
            $context = array_merge($context, [
                'invoice_ids'=>$batch->pluck('id')->all(),
                'invoice_number'=>$batch->map(fn ($i) => 'W-'.$i->id)->implode(', '),
                'invoice_total'=>'$'.number_format($batch->sum('total_amount'), 2),
                'invoice_status'=>$batch->count() === 1 ? $context['invoice_status'] : 'Review invoices in dashboard',
                'invoice_items_text'=>$lines->implode("\n"),
                'invoice_items_html'=>'<ul>'.$lines->map(fn ($line) => '<li>'.e($line).'</li>')->implode('').'</ul>',
                'invoice_reconciliation_text'=>$note."\n".$pendingLines->implode("\n"),
                'invoice_reconciliation_html'=>'<p>'.e($note).'</p>'.($pendingLines->isNotEmpty() ? '<ul>'.$pendingLines->map(fn ($line) => '<li>'.e($line).'</li>')->implode('').'</ul>' : ''),
            ]);
            yield ['invoice'=>$invoice, 'context'=>$context, 'key'=>'weekly-photographer-invoice:'.$recipientId.':'.$start->toDateString()];
        }
    }

    public function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['photographer', 'salesRep', 'items']);
        $recipient = $invoice->photographer ?? $invoice->salesRep;
        $role = $invoice->photographer ? 'photographer' : 'rep';
        $items = $invoice->items->where('type', 'charge')->map(fn ($item) => trim((string) $item->description).' — $'.number_format((float) $item->total_amount, 2));

        return [
            'invoice' => $invoice,
            'invoice_id' => $invoice->id,
            $role => $recipient,
            'recipient' => $recipient,
            'account_id' => $recipient?->id,
            'recipient_role' => $role === 'rep' ? 'sales rep' : 'photographer',
            'billing_period' => ($invoice->billing_period_start ?? $invoice->period_start)?->format('M j')
                .' - '.($invoice->billing_period_end ?? $invoice->period_end)?->format('M j, Y'),
            'invoice_status' => ucfirst((string) $invoice->status),
            'invoice_total' => '$'.number_format((float) ($invoice->total_amount ?? $invoice->total), 2),
            'invoice_items_text' => $items->implode("\n"),
            'invoice_items_html' => '<ul>'.$items->map(fn ($line) => '<li>'.e($line).'</li>')->implode('').'</ul>',
            'dashboard_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/accounting',
            'invoice_next_step' => 'Review your invoice in the dashboard and submit any corrections before approval.',
            'approval_note' => 'Payment follows the invoice approval process.',
            'invoice_reconciliation_html' => '', 'invoice_reconciliation_text' => '',
        ];
    }

    public function report(User $rep, array $report): array
    {
        $report['summary'] = array_merge([
            'total_shoots' => 0, 'completion_rate' => 0, 'completed_shoots' => 0,
            'total_revenue' => 0, 'total_paid' => 0, 'outstanding_balance' => 0,
        ], (array) ($report['summary'] ?? []));
        $report += ['clients' => [], 'top_shoots' => []];
        $context = [
            'rep' => $rep,
            'account_id' => $rep->id,
            'report' => $report,
            'report_period_start' => data_get($report, 'period.start'),
            'report_period_end' => data_get($report, 'period.end'),
        ];
        foreach ((array) ($report['summary'] ?? []) as $key => $value) {
            $context['report_'.$key] = in_array($key, ['total_revenue', 'total_paid', 'outstanding_balance', 'average_shoot_value'], true)
                ? number_format((float) $value, 2, '.', ',') : $value;
        }

        $weekLabel = isset($report['period']['week_number'], $report['period']['year'])
            ? 'Week '.$report['period']['week_number'].', '.$report['period']['year']
            : ($context['report_period_start'].' - '.$context['report_period_end']);

        return array_merge($context, app(WeeklyDigestContexts::class)->directVariables('emails.weekly_sales_report', [
            'salesRep' => $rep, 'recipientName' => $rep->name, 'report' => $report, 'weekLabel' => $weekLabel,
        ], 'Weekly Sales Report - '.$weekLabel));
    }

    public function hasReportActivity(array $report): bool
    {
        foreach (['total_shoots', 'total_revenue', 'total_paid', 'outstanding_balance'] as $field) {
            if ((float) data_get($report, 'summary.'.$field, 0) !== 0.0) {
                return true;
            }
        }

        return false;
    }
}
