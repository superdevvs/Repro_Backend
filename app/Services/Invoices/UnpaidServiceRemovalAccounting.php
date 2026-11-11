<?php

namespace App\Services\Invoices;

use App\Models\{Invoice, InvoiceItem, Shoot, User};
use App\Services\InvoiceService;
use App\Services\Shoots\ShootServiceItemSupport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Reconcile customer-unpaid removals inside the shoot's locked write transaction. */
final class UnpaidServiceRemovalAccounting
{
    public function snapshot(Shoot $shoot): array
    {
        $items = $shoot->serviceItems()->with('service')->get();
        $paid = app(ShootServiceItemSupport::class)->paidAmountsByItem($items);
        // Legacy money without a line allocation is not evidence that each line is unpaid.
        $unallocated = $shoot->calculateCanonicalTotalPaid() - array_sum($paid) > 0.01;
        $basis = app(PhotographerPayoutBasis::class);
        $earnings = [];
        foreach ($items as $item) {
            if (! $item->service || ($item->photographer_id === null && $item->service->isDigitalEnhancement())) continue;
            $recipient = $basis->recipientForLine($item, $shoot);
            $completed = $basis->completion($shoot, $item);
            $amount = $basis->amount($item, $shoot, $item->service);
            if (! $recipient || ! $completed || ! $shoot->scheduled_date || abs($amount) < 0.005 || $shoot->isInternalTestShoot()
                || $shoot->isComplimentaryReshoot() || data_get($shoot->external_booking_payload, 'legacy_migration.historical_payments_only')) continue;
            $earnings[] = [
                'shoot_id' => $shoot->id, 'shoot_service_id' => $item->id, 'recipient_id' => $recipient,
                'scheduled_date' => $shoot->scheduled_date->toDateString(), 'earned_at' => $completed,
                'amount' => $amount, 'snapshot' => json_encode([
                    'service_id' => $item->service_id, 'service_name' => $item->service->name,
                    'shoot_unit_id' => $item->shoot_unit_id, 'unit_label' => $item->unit?->label,
                    'payout_basis' => $basis->forLine($item, $shoot, $item->service),
                    'service' => $item->getAttributes(),
                ], JSON_THROW_ON_ERROR),
            ];
        }

        return ['items' => $items, 'paid' => $paid, 'unallocated' => $unallocated, 'earnings' => $earnings];
    }

    public function reconcile(Shoot $shoot, array $before, User $actor): void
    {
        $remaining = $shoot->serviceItems()->pluck('id');
        $removed = $before['items']->whereNotIn('id', $remaining)
            ->filter(fn ($item) => ! $before['unallocated'] && ($before['paid'][$item->id] ?? 0) < 0.005);
        if ($removed->isEmpty()) return;

        // A retained financial-only fee can depend on the removed capture's completion.
        // Preserve all already-earned lines, including that fee, before losing the evidence.
        foreach ($before['earnings'] as $earning) {
            DB::table('shoot_service_earning_snapshots')->insertOrIgnore($earning + [
                'removed_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->reconcileCommission($shoot, $removed, $actor);
    }

    private function reconcileCommission(Shoot $shoot, Collection $removed, User $actor): void
    {
        $items = InvoiceItem::with('invoice')->where('shoot_id', $shoot->id)->where('type', InvoiceItem::TYPE_CHARGE)
            ->whereNull('shoot_compensation_id')->whereHas('invoice', fn ($q) => $q->where('role', Invoice::ROLE_SALES_REP))
            ->lockForUpdate()->get();
        foreach ($items as $item) {
            $rate = data_get($item->meta, 'commission_rate');
            if (! is_numeric($rate)) continue; // A manual/non-commission line has no inferred rate.
            $oldGross = data_get($item->meta, 'commissionable_gross');
            if (! is_numeric($oldGross)) continue;
            $previous = InvoiceItem::where('meta->source', 'unpaid_service_removal')
                ->where('meta->source_invoice_item_id', $item->id)->get();
            $current = app(InvoiceService::class)->buildSalesRepCommissionRow($shoot, (float) $rate);
            // Retain an explicit payee/accounts override relative to the generated basis.
            // A replacement/addition in the same save must not lose its commission.
            $manualOffset = (float) $item->total_amount - round((float) $oldGross * (float) $rate / 100, 2);
            $target = max((float) $current['commission_amount'] + $manualOffset, 0);
            $delta = round($target - ((float) $item->total_amount + $previous->sum('total_amount')), 2);
            if (abs($delta) < 0.005) continue;
            $invoice = Invoice::lockForUpdate()->findOrFail($item->invoice_id);
            $before = $invoice->buildApprovalSnapshot();
            $settled = $invoice->is_paid || $invoice->paid_at || $invoice->status === Invoice::STATUS_PAID
                || (float) $invoice->amount_paid > 0 || $invoice->items()->where('type', InvoiceItem::TYPE_PAYMENT)->exists();
            if ($settled) {
                // Never rewrite a payout that has moved money. Its credit is a new ledger document.
                $invoice = Invoice::create([
                    'role' => Invoice::ROLE_SALES_REP, 'user_id' => $invoice->user_id,
                    'sales_rep_id' => $invoice->sales_rep_id, 'period_start' => $invoice->period_start,
                    'period_end' => $invoice->period_end, 'billing_period_start' => $invoice->billing_period_start,
                    'billing_period_end' => $invoice->billing_period_end,
                    'status' => Invoice::STATUS_DRAFT, 'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
                ]);
            }
            $invoice->items()->create([
                'shoot_id' => $shoot->id, 'type' => InvoiceItem::TYPE_EXPENSE, 'quantity' => 1,
                'unit_amount' => $delta, 'total_amount' => $delta, 'recorded_at' => now(),
                'description' => 'Commission adjustment: unpaid service removed from shoot #'.$shoot->id,
                'meta' => ['source' => 'unpaid_service_removal', 'source_invoice_item_id' => $item->id,
                    'removed_shoot_service_ids' => $removed->pluck('id')->all(), 'commission_rate' => (float) $rate,
                    'current_commissionable_gross' => $current['commissionable_gross'],
                    'current_service_lines' => $current['service_lines'], 'current_excluded_lines' => $current['excluded_lines']],
            ]);
            $invoice->shoots()->syncWithoutDetaching([$shoot->id]);
            $invoice->forceFill([
                'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL, 'approved_by' => null,
                'approved_at' => null, 'approval_snapshot' => null, 'payout_submission_snapshot' => null,
                'modified_by' => $actor->id, 'modified_at' => now(),
                'payout_edit_baseline' => $invoice->payout_edit_baseline ?: $before,
            ])->save();
            $invoice->refreshTotals();
            $invoice->recordAuditEvent('admin_edit', $actor, 'Commission adjusted after unpaid service removal; accounts review required.', [
                'source' => 'unpaid_service_removal', 'source_invoice_item_id' => $item->id,
                'removed_shoot_service_ids' => $removed->pluck('id')->all(), 'amount' => $delta,
                'before' => $before, 'after' => $invoice->buildApprovalSnapshot(),
            ]);
        }
    }
}
