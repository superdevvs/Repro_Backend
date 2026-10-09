<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountingExpense;
use App\Models\EditorPayout;
use App\Models\Invoice;
use App\Models\ListingStudioSubscription;
use App\Models\Payment;
use App\Models\PhotographerEquipment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Read-only overview. Cash uses payment dates; obligations use the selected document/earning period. */
class AccountingHomeController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->validate(['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start']);
        $start = CarbonImmutable::parse($input['start']);
        $end = CarbonImmutable::parse($input['end'])->endOfDay();
        abort_if($start->diffInDays($end) > 366, 422, 'Select at most one year.');
        $priorStart = $start->subDays((int) $start->diffInDays($end) + 1);
        $invoices = Invoice::with(['items', 'client:id,name,email', 'photographer:id,name', 'salesRep:id,name', 'auditEvents'])->get();
        Invoice::primePaymentRecords($invoices);
        $ledger = collect();
        $payments = Payment::with(['invoice', 'shoot.client:id,name', 'refunds'])
            ->whereIn('status', ['completed', 'refunded'])->where(function ($q) use ($priorStart, $end) {
                $q->whereBetween('processed_at', [$priorStart, $end])->orWhereHas('refunds', fn ($r) => $r->whereBetween('created_at', [$priorStart, $end]));
            })->get()->unique(fn ($p) => $p->stripe_session_id ? 'session:'.$p->stripe_session_id : ($p->stripe_payment_id ? 'stripe:'.$p->stripe_payment_id : ($p->square_payment_id ? 'square:'.$p->square_payment_id : 'payment:'.$p->id)));
        foreach ($payments as $p) {
            if (($p->invoice && $p->invoice->isPayoutInvoice()) || strtoupper((string) $p->currency) !== 'USD') {
                continue;
            }
            $ledger->push(['id' => 'payment-'.$p->id, 'invoice_id' => $p->invoice_id, 'name' => $p->shoot?->client?->name ?? $p->invoice?->client?->name ?? 'Client payment', 'category' => 'client', 'amount' => (float) $p->amount, 'date' => $p->processed_at?->toDateString(), 'method' => $p->payment_method ?: 'Other', 'reference' => $p->stripe_payment_id ?: $p->square_payment_id ?: 'Payment '.$p->id]);
            foreach ($p->refunds as $refund) {
                if (! in_array($refund->status, ['succeeded', 'completed'], true)) {
                    continue;
                }
                $ledger->push(['id' => 'refund-'.$refund->id, 'invoice_id' => $p->invoice_id, 'name' => $p->shoot?->client?->name ?? 'Client refund', 'category' => 'client', 'amount' => -(float) $refund->amount, 'date' => $refund->created_at->toDateString(), 'method' => $p->payment_method ?: 'Other', 'reference' => 'Refund '.$refund->id]);
            }
        }
        $recipients = ['photographer' => ['paid' => 0, 'unpaid' => 0, 'review' => 0], 'rep' => ['paid' => 0, 'unpaid' => 0, 'review' => 0], 'editor' => ['paid' => 0, 'unpaid' => 0, 'review' => 0]];
        $open = 0;
        $aging = ['Current' => 0, '1–30 days' => 0, '31–60 days' => 0, '61+ days' => 0];
        $services = [];
        $attention = [];
        $accounts = [];
        $openCount = 0;
        foreach ($invoices as $i) {
            $total = (float) ($i->total ?? $i->total_amount);
            if ($i->isPayoutInvoice()) {
                $category = $i->role === 'photographer' ? 'photographer' : 'rep';
                $paid = max(0, (float) $i->amount_paid);
                $events = $i->auditEvents->whereIn('event', ['paid', 'payment_recorded'])->filter(fn ($event) => (float) ($event->metadata['payment_amount'] ?? 0) > 0);
                $paidAsOf = $events->isNotEmpty()
                    ? $events->sum(fn ($event) => CarbonImmutable::parse($event->metadata['paid_at'] ?? $event->created_at)->lte($end) ? max(0, (float) ($event->metadata['payment_amount'] ?? 0)) : 0)
                    : ($i->paid_at && $i->paid_at->lte($end) ? $paid : 0);
                $periodStart = $i->billing_period_start ?? $i->period_start ?? $i->issue_date ?? $i->created_at;
                $periodEnd = $i->billing_period_end ?? $i->period_end ?? $i->due_date ?? $periodStart;
                if ($periodStart->toDateString() <= $input['end'] && $periodEnd->toDateString() >= $input['start']) {
                    if ($i->isAccountsApproved() && (! $i->approved_at || $i->approved_at->lte($end))) {
                        $recipients[$category]['unpaid'] += max(0, $total - $paidAsOf);
                    } elseif (! in_array($i->status, ['paid', 'void', 'cancelled'], true)) {
                        $recipients[$category]['review']++;
                    }
                }
                if ($events->isNotEmpty()) {
                    foreach ($events as $event) {
                        $amount = (float) ($event->metadata['payment_amount'] ?? 0);
                        if ($amount <= 0) {
                            continue;
                        }
                        $ledger->push(['id' => 'payout-event-'.$event->id, 'invoice_id' => $i->id, 'name' => $i->photographer?->name ?? $i->salesRep?->name ?? 'Recipient', 'category' => $category, 'amount' => $amount, 'date' => CarbonImmutable::parse($event->metadata['paid_at'] ?? $event->created_at)->toDateString(), 'method' => $event->metadata['payment_method'] ?? 'Other', 'reference' => $i->invoice_number]);
                    }
                } elseif ($i->paid_at && $paid > 0) {
                    $ledger->push(['id' => 'payout-'.$i->id, 'invoice_id' => $i->id, 'name' => $i->photographer?->name ?? $i->salesRep?->name ?? 'Recipient', 'category' => $category, 'amount' => $paid, 'date' => $i->paid_at->toDateString(), 'method' => $i->payment_method ?: 'Other', 'reference' => $i->invoice_number]);
                }

                continue;
            }
            if (! $i->requiresPayment() || in_array($i->status, ['draft', 'void', 'cancelled', 'canceled'], true)) {
                continue;
            }
            $paid = max(0, (float) $i->amount_paid);
            // Keep all dated receipts in cash, including receipts for earlier invoices.
            if (! $i->hasRelatedPaymentRecords() && $i->paid_at && $paid > 0) {
                $ledger->push(['id' => 'legacy-'.$i->id, 'invoice_id' => $i->id, 'name' => $i->client?->name ?? 'Client', 'category' => 'client', 'amount' => $paid, 'date' => $i->paid_at->toDateString(), 'method' => $i->payment_method ?: 'Historical', 'reference' => $i->invoice_number.' · historical payment']);
            }
            $issued = ($i->issue_date ?? $i->period_start ?? $i->billing_period_start ?? $i->due_date ?? $i->period_end ?? $i->billing_period_end ?? $i->created_at)->toDateString();
            $balance = max(0, $total - $this->clientPaidAsOf($i, $end));
            $selectedIssue = $issued >= $input['start'] && $issued <= $input['end'];
            if ($selectedIssue) {
                $open += $balance;
                $accounts[$i->client_id ?? $i->user_id] = true;
            }
            if ($selectedIssue && $balance > .005) {
                $openCount++;
                $days = $i->due_date ? (int) CarbonImmutable::parse($i->due_date)->startOfDay()->diffInDays($end->startOfDay(), false) : 0;
                $bucket = $days > 60 ? '61+ days' : ($days > 30 ? '31–60 days' : ($days > 0 ? '1–30 days' : 'Current'));
                $aging[$bucket] += $balance;
                if ($days > 0) {
                    $attention[] = ['id' => $i->id, 'name' => $i->client?->name ?? 'Client', 'label' => 'Overdue · '.$days.' days', 'amount' => round($balance, 2)];
                }
            }
            if ($issued < $priorStart->toDateString() || $issued > $end->toDateString()) {
                continue;
            }
            foreach ($i->items as $item) {
                if ($item->type !== 'charge' || (float) $item->total_amount <= 0 || data_get($item->meta, 'source') === 'admin_misc') {
                    continue;
                }
                $name = data_get($item->meta, 'service_name') ?: $item->description;
                if (! $name) {
                    continue;
                }
                $services[$name] ??= ['name' => $name, 'sales' => 0, 'prior' => 0, 'units' => 0, 'invoices' => []];
                if ($issued >= $start->toDateString()) {
                    $services[$name]['sales'] += (float) $item->total_amount;
                    $services[$name]['units'] += (int) $item->quantity;
                    $services[$name]['invoices'][$i->id] = true;
                } else {
                    $services[$name]['prior'] += (float) $item->total_amount;
                }
            }
        }
        foreach (EditorPayout::with('editor:id,name')->whereNotNull('completed_at')->get() as $p) {
            $completed = $p->completed_at->toDateString();
            if ($completed >= $input['start'] && $completed <= $input['end'] && (! $p->is_paid || ! $p->paid_at || $p->paid_at->gt($end))) {
                $recipients['editor']['unpaid'] += (float) $p->payout_amount;
            }
            if ($p->is_paid && $p->paid_at) {
                $ledger->push(['id' => 'editor-'.$p->id, 'invoice_id' => null, 'name' => $p->editor?->name ?? 'Editor', 'category' => 'editor', 'amount' => (float) $p->payout_amount, 'date' => $p->paid_at->toDateString(), 'method' => 'Recorded payout', 'reference' => $p->payout_batch_id ?: 'Editor '.$p->id]);
            }
        }
        $inRange = fn ($row) => $row['date'] && $row['date'] >= $start->toDateString() && $row['date'] <= $end->toDateString();
        $current = $ledger->filter($inRange)->values();
        $prior = $ledger->filter(fn ($r) => $r['date'] && $r['date'] >= $priorStart->toDateString() && $r['date'] < $start->toDateString());
        foreach ($recipients as $key => &$row) {
            $row['paid'] = round($current->where('category', $key)->sum('amount'), 2);
        }
        unset($row);
        $daily = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $rows = $current->where('date', $d->toDateString());
            $daily[] = ['date' => $d->toDateString(), 'received' => round($rows->where('category', 'client')->sum('amount'), 2), 'paid' => round($rows->where('category', '!=', 'client')->sum('amount'), 2)];
        }
        $expenses = AccountingExpense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])->latest('expense_date')->get();
        $subs = ListingStudioSubscription::with('client:id,name')->where(function ($query) use ($start, $end) {
            $query->where(function ($period) use ($start, $end) {
                $period->whereNotNull('current_period_start')->whereNotNull('current_period_end')
                    ->whereDate('current_period_start', '<=', $end->toDateString())->whereDate('current_period_end', '>=', $start->toDateString());
            })->orWhere(function ($undated) use ($start, $end) {
                $undated->where(fn ($missing) => $missing->whereNull('current_period_start')->orWhereNull('current_period_end'))
                    ->whereBetween('created_at', [$start, $end]);
            });
        })->get();
        $equipment = PhotographerEquipment::whereDate(\Illuminate\Support\Facades\DB::raw('COALESCE(issue_date, created_at)'), '>=', $input['start'])
            ->whereDate(\Illuminate\Support\Facades\DB::raw('COALESCE(issue_date, created_at)'), '<=', $input['end'])->get();
        $received = round($current->where('category', 'client')->sum('amount'), 2);

        return response()->json(['data' => [
            'start' => $input['start'], 'end' => $input['end'], 'snapshot_at' => $end->toISOString(),
            'received' => $received, 'prior_received' => round($prior->where('category', 'client')->sum('amount'), 2),
            'paid' => round($current->where('category', '!=', 'client')->sum('amount'), 2), 'open' => round($open, 2), 'collected' => $received, 'accounts' => count($accounts), 'open_count' => $openCount,
            'aging' => $aging, 'recipients' => $recipients, 'daily' => $daily, 'ledger' => $current->sortByDesc('date')->values(), 'attention' => collect($attention)->sortByDesc('amount')->values(),
            'methods' => $current->where('category', 'client')->groupBy('method')->map(fn ($rows) => round($rows->sum('amount'), 2)),
            'services' => collect($services)->map(fn ($r) => [...$r, 'orders' => count($r['invoices']), 'invoice_ids' => array_keys($r['invoices']), 'invoices' => null])->sortByDesc('sales')->values(),
            'expenses' => $expenses->map(fn ($r) => ['id' => $r->id, 'name' => $r->vendor ?: $r->description, 'category' => $r->category, 'amount' => (float) $r->amount, 'date' => $r->expense_date->toDateString(), 'status' => $r->status, 'receipt' => (bool) $r->receipt_path, 'receipt_url' => $r->receipt_path ? url('/api/admin/accounting-expenses/'.$r->id.'/receipt') : null, 'linked' => $r->related_type, 'reimbursable' => $r->reimbursable]),
            'equipment' => ['pending' => $equipment->whereIn('status', ['pending_verification', 'submitted'])->count(), 'linked' => $equipment->whereNotNull('expense_id')->count()],
            'subscriptions' => $subs->map(fn ($r) => ['id' => $r->id, 'name' => $r->client?->name ?: $r->customer_name ?: 'Client', 'plan' => $r->plan_name ?: $r->plan_code, 'status' => $r->status, 'amount' => $r->amount_cents / 100, 'currency' => $r->currency, 'interval' => $r->billing_interval, 'end' => $r->current_period_end?->toDateString(), 'attention' => $r->sync_status === 'needs_attention' || in_array($r->status, ['past_due', 'unpaid', 'incomplete'], true)]),
            'cash_basis_note' => 'Cash uses recorded payment/refund dates in the selected period. Client balances use invoices issued in the period and payments through its end; aging is measured at that end date. Recipient obligations use overlapping earning periods. Operating expenses lack payment evidence and are excluded from cash paid. Undated historical payments cannot establish period cash; their current amount is retained for balances. Approval, equipment and subscription statuses reflect their current recorded state.',
        ]]);
    }

    /** Do not apply a later payment or refund to an earlier reporting snapshot. */
    private function clientPaidAsOf(Invoice $invoice, CarbonImmutable $end): float
    {
        if (! $invoice->hasRelatedPaymentRecords()) {
            return $invoice->paid_at && $invoice->paid_at->gt($end) ? 0 : max(0, (float) $invoice->amount_paid);
        }

        return round($invoice->relatedPaymentRecords()->sum(function (Payment $payment) use ($end) {
            if (! $payment->processed_at || $payment->processed_at->gt($end) || strtoupper((string) $payment->currency) !== 'USD') {
                return 0;
            }
            $refunds = $payment->refunds->filter(fn ($refund) => in_array($refund->status, ['succeeded', 'completed'], true) && $refund->created_at->lte($end));

            return max(0, (float) $payment->amount - (float) $refunds->sum('amount'));
        }), 2);
    }
}
