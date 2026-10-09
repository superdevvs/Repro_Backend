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

/** Read-only overview. Cash dates and current obligations intentionally have separate bases. */
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
        $clients = $invoices->filter(fn ($i) => ! $i->isPayoutInvoice() && $i->requiresPayment());
        $open = 0;
        $collected = 0;
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
                $remaining = max(0, $total - $paid);
                if ($i->isAccountsApproved()) {
                    $recipients[$category]['unpaid'] += $remaining;
                } elseif (! in_array($i->status, ['paid', 'void', 'cancelled'], true)) {
                    $recipients[$category]['review']++;
                }
                $events = $i->auditEvents->whereIn('event', ['paid', 'payment_recorded']);
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
            $paid = $i->hasRelatedPaymentRecords() ? $i->totalPaid() : max(0, (float) $i->amount_paid);
            $balance = max(0, $total - $paid);
            $collected += $paid;
            $open += $balance;
            $accounts[$i->client_id ?? $i->user_id] = true;
            if ($balance > .005) {
                $openCount++;
                $days = $i->due_date ? (int) CarbonImmutable::parse($i->due_date)->diffInDays(CarbonImmutable::now(), false) : 0;
                $bucket = $days > 60 ? '61+ days' : ($days > 30 ? '31–60 days' : ($days > 0 ? '1–30 days' : 'Current'));
                $aging[$bucket] += $balance;
                if ($days > 0) {
                    $attention[] = ['id' => $i->id, 'name' => $i->client?->name ?? 'Client', 'label' => 'Overdue · '.$days.' days', 'amount' => round($balance, 2)];
                }
            }
            if (! $i->hasRelatedPaymentRecords() && $i->paid_at && $paid > 0) {
                $ledger->push(['id' => 'legacy-'.$i->id, 'invoice_id' => $i->id, 'name' => $i->client?->name ?? 'Client', 'category' => 'client', 'amount' => $paid, 'date' => $i->paid_at->toDateString(), 'method' => $i->payment_method ?: 'Historical', 'reference' => $i->invoice_number.' · historical payment']);
            }
            $issued = ($i->issue_date ?? $i->created_at)->toDateString();
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
            if (! $p->is_paid) {
                $recipients['editor']['unpaid'] += (float) $p->payout_amount;
            } elseif ($p->paid_at) {
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
        $subs = ListingStudioSubscription::with('client:id,name')->get();

        return response()->json(['data' => [
            'start' => $input['start'], 'end' => $input['end'], 'snapshot_at' => now()->toISOString(),
            'received' => round($current->where('category', 'client')->sum('amount'), 2), 'prior_received' => round($prior->where('category', 'client')->sum('amount'), 2),
            'paid' => round($current->where('category', '!=', 'client')->sum('amount'), 2), 'open' => round($open, 2), 'collected' => round($collected, 2), 'accounts' => count($accounts), 'open_count' => $openCount,
            'aging' => $aging, 'recipients' => $recipients, 'daily' => $daily, 'ledger' => $current->sortByDesc('date')->values(), 'attention' => collect($attention)->sortByDesc('amount')->values(),
            'methods' => $current->where('category', 'client')->groupBy('method')->map(fn ($rows) => round($rows->sum('amount'), 2)),
            'services' => collect($services)->map(fn ($r) => [...$r, 'orders' => count($r['invoices']), 'invoice_ids' => array_keys($r['invoices']), 'invoices' => null])->sortByDesc('sales')->values(),
            'expenses' => $expenses->map(fn ($r) => ['id' => $r->id, 'name' => $r->vendor ?: $r->description, 'category' => $r->category, 'amount' => (float) $r->amount, 'date' => $r->expense_date->toDateString(), 'status' => $r->status, 'receipt' => (bool) $r->receipt_path, 'receipt_url' => $r->receipt_path ? url('/api/admin/accounting-expenses/'.$r->id.'/receipt') : null, 'linked' => $r->related_type, 'reimbursable' => $r->reimbursable]),
            'equipment' => ['pending' => PhotographerEquipment::whereIn('status', ['pending_verification', 'submitted'])->count(), 'linked' => PhotographerEquipment::whereNotNull('expense_id')->count()],
            'subscriptions' => $subs->map(fn ($r) => ['id' => $r->id, 'name' => $r->client?->name ?: $r->customer_name ?: 'Client', 'plan' => $r->plan_name ?: $r->plan_code, 'status' => $r->status, 'amount' => $r->amount_cents / 100, 'currency' => $r->currency, 'interval' => $r->billing_interval, 'end' => $r->current_period_end?->toDateString(), 'attention' => $r->sync_status === 'needs_attention' || in_array($r->status, ['past_due', 'unpaid', 'incomplete'], true)]),
            'cash_basis_note' => 'Recorded client receipts less refunds and dated recipient payouts. Operating expenses lack payment evidence and are excluded from cash paid. Balances are current; undated historical payments are excluded from period cash.',
        ]]);
    }
}
