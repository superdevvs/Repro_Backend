<?php

namespace App\Services\Messaging;

use App\Models\Invoice;
use App\Models\User;

class WeeklyAutomationContext
{
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
