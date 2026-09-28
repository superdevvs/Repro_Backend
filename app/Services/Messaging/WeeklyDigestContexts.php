<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\PayoutReportService;
use App\Services\SystemEmails\DirectEmailTemplates;
use App\Services\SystemEmails\EditableEmailContent;
use App\Support\ReportingWeek;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Recipient-scoped data for the weekly summary and payout workflows. */
class WeeklyDigestContexts
{
    public function forRule(AutomationRule $rule): iterable
    {
        [$start, $end] = ReportingWeek::lastCompleted();

        return in_array($rule->trigger_type, ['INVOICE_SUMMARY', 'WEEKLY_REP_INVOICE'], true)
            ? $this->invoiceSummaries($rule, $start, $end)
            : $this->payouts($rule, $start, $end);
    }

    private function invoiceSummaries(AutomationRule $rule, Carbon $start, Carbon $end): iterable
    {
        // A staff payout invoice can cover many clients. It must never be sent
        // to the first shoot's client or included in a client's billing summary.
        $invoices = Invoice::query()->where('role', Invoice::ROLE_CLIENT)
            ->whereNotIn('status', ['draft', 'cancelled', 'canceled', 'void'])
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('issue_date', [$start, $end])
                    ->orWhere(fn ($query) => $query->whereNull('issue_date')->whereBetween('created_at', [$start, $end]));
            })
            ->whereDoesntHave('shoot', fn ($query) => $query->where('shoot_type', \App\Models\Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->whereDoesntHave('shoots', fn ($query) => $query->where('shoot_type', \App\Models\Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->with(['client', 'user', 'salesRep', 'shoot.client', 'shoot.rep', 'shoots.client', 'shoots.rep'])
            ->get()->filter(fn (Invoice $invoice) => $invoice->requiresPayment()
                && ! $invoice->suppressesExternalNotifications()
                && (float) ($invoice->total ?? $invoice->total_amount) > 0);

        $buckets = [];
        foreach ($invoices as $invoice) {
            $client = $invoice->client ?? $invoice->shoot?->client ?? $invoice->shoots->first()?->client ?? $invoice->user;
            if (! $client || ($client->account_status ?? 'active') !== 'active') {
                continue;
            }
            $rep = $invoice->salesRep ?? $invoice->shoot?->rep ?? $invoice->shoots->first()?->rep;
            if (! $rep) {
                $metadata = (array) $client->metadata;
                $repId = $metadata['accountRepId'] ?? $metadata['account_rep_id'] ?? $metadata['repId'] ?? $metadata['rep_id'] ?? null;
                $rep = $repId ? User::find($repId) : null;
            }
            $role = $rule->trigger_type === 'INVOICE_SUMMARY' ? 'client' : 'rep';
            $recipient = $role === 'client' ? $client : $rep;
            if (! $recipient || ($recipient->account_status ?? 'active') !== 'active') {
                continue;
            }
            $buckets[$recipient->id]['recipient'] = $recipient;
            $buckets[$recipient->id]['invoices'][] = $invoice;
        }

        foreach ($buckets as $bucket) {
            $recipient = $bucket['recipient'];
            $group = collect($bucket['invoices']);
            $role = $rule->trigger_type === 'INVOICE_SUMMARY' ? 'client' : 'rep';
            $tag = $rule->trigger_type.':'.$role.':'.$recipient->id.':'.$end->toDateString();
            $rows = $group->map(fn (Invoice $invoice) => [
                'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
                'issue_date' => $invoice->issue_date?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
                'total' => (float) ($invoice->total ?? $invoice->total_amount),
                'amount_paid' => (float) $invoice->amount_paid, 'balance_due' => $invoice->balanceDue(), 'status' => $invoice->status,
            ]);
            $lines = $rows->map(fn ($row) => $row['invoice_number'].' — $'.number_format($row['total'], 2).' — balance $'.number_format($row['balance_due'], 2));
            yield $tag => [
                $role => $recipient, 'recipient' => $recipient, 'account_id' => $recipient->id,
                'summary_start' => $start->toDateString(), 'summary_end' => $end->toDateString(),
                'summary_invoice_count' => $group->count(),
                'summary_total_invoiced' => number_format($rows->sum('total'), 2, '.', ''),
                'summary_total_paid' => number_format($rows->sum('amount_paid'), 2, '.', ''),
                'summary_total_outstanding' => number_format($rows->sum('balance_due'), 2, '.', ''),
                'summary_invoices' => $rows->all(), 'summary_invoices_text' => $lines->implode("\n"),
                'summary_invoices_html' => '<ul>'.$lines->map(fn ($line) => '<li>'.e($line).'</li>')->implode('').'</ul>',
                'tags_json' => [$tag],
            ];
        }
    }

    private function payouts(AutomationRule $rule, Carbon $start, Carbon $end): iterable
    {
        $service = app(PayoutReportService::class);
        $groups = [
            'photographer' => $service->buildPhotographerSummaries($start, $end),
            'editor' => $service->buildEditorSummaries($start, $end),
            'rep' => $service->buildSalesRepSummaries($start, $end),
        ];
        $groups = array_map(fn (Collection $rows) => $rows->filter(fn ($row) => (int) ($row['shoot_count'] ?? 0) > 0
            || abs((float) ($row['payout_total'] ?? $row['gross_total'] ?? 0)) >= 0.005)->values(), $groups);
        if (collect($groups)->sum(fn ($rows) => $rows->count()) === 0) {
            return;
        }

        if ($rule->trigger_type === 'WEEKLY_PAYOUT_DIGEST') {
            $address = trim((string) ($rule->schedule_json['accounting_email'] ?? ''));
            $address = $address !== '' ? $address : (string) config('mail.accounting_address', 'accounting@reprophotos.com');
            if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('The payout digest accounting address is invalid.');
            }
            $data = [
                'rangeStart' => $start, 'rangeEnd' => $end,
                'photographers' => $groups['photographer'], 'editors' => $groups['editor'], 'reps' => $groups['rep'],
                'totalPhotographerPayout' => $groups['photographer']->sum('gross_total'),
                'totalEditorPayout' => $groups['editor']->sum('gross_total'),
                'totalRepPayout' => $groups['rep']->sum('payout_total'),
                'totalRepCommission' => $groups['rep']->sum('commission_total'),
                'totalRepCompensation' => $groups['rep']->sum('compensation_total'),
            ];
            yield 'weekly-payout-digest:'.$start->toDateString() => array_merge(
                $this->directVariables('emails.payout-digest', $data, 'Payout approvals summary ('.$start->format('M d').' - '.$end->format('M d').')'),
                ['accounting' => ['name' => 'Accounting', 'email' => $address]],
            );

            return;
        }

        foreach ($groups as $role => $summaries) {
            foreach ($summaries as $summary) {
                $recipient = User::find($summary['id'] ?? null);
                if (! $recipient || ($recipient->account_status ?? 'active') !== 'active') {
                    continue;
                }
                $data = ['recipientName' => $recipient->name, 'summary' => $summary, 'rangeStart' => $start, 'rangeEnd' => $end,
                    'audience' => $role === 'rep' ? 'sales rep' : $role];
                yield 'weekly-payout:'.$role.':'.$recipient->id.':'.$start->toDateString() => array_merge(
                    $this->directVariables('emails.payout-report', $data, 'Weekly payout recap ('.$start->format('M d').' - '.$end->format('M d').')'),
                    [$role => $recipient, 'recipient' => $recipient, 'account_id' => $recipient->id,
                        'payout_role' => $role, 'payout_total' => number_format((float) ($summary['payout_total'] ?? $summary['gross_total'] ?? 0), 2),
                        'payout_period_start' => $start->toDateString(), 'payout_period_end' => $end->toDateString()],
                );
            }
        }
    }

    public function directVariables(string $view, array $data, string $subject): array
    {
        $definition = DirectEmailTemplates::definitions()[$view];
        $template = MessageTemplate::where('slug', $definition['slug'])->where('channel', 'EMAIL')->first() ?? new MessageTemplate($definition);
        $content = app(EditableEmailContent::class);
        $renderer = app(TemplateRenderer::class);
        $htmlVariable = trim($definition['body_html'], '{}');
        $textVariable = str_replace('_html', '_text', $htmlVariable);
        $footer = $content->footerNote($view, $data);

        return [
            'email_subject' => $subject, 'recipient_name' => $data['recipientName'] ?? 'Accounting',
            $htmlVariable => $renderer->editableBodyHtml($content->render($view, $data, $template)),
            $textVariable => EditableEmailContent::plainText($renderer->editableBodyHtml($content->render($view, $data, $template, 'text'))."\n\n".$footer),
            'email_footer_note' => $footer,
        ];
    }
}
