<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioSubscription;
use App\Models\ListingStudioSubscriptionAction as Action;
use Illuminate\Support\Facades\DB;

/** Called only inside the existing webhook's short transaction; never performs Stripe I/O. */
class StudioSubscriptionProjection
{
    public function protect(ListingStudioSubscription $record, array $snapshot, array $canonical, array $invoices): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Subscription projection requires the financial transaction.');
        }
        if ($record->exists) {
            foreach (Action::where('subscription_id', $record->id)->where('status', 'scheduled')->get() as $scheduled) {
                $effective = $scheduled->effective_at?->timestamp ?? PHP_INT_MAX;
                $applied = $scheduled->kind === 'cancel_renewal'
                    ? ($canonical['status'] ?? '') === 'canceled' && now()->timestamp >= $effective
                    : $scheduled->kind === 'schedule_downgrade' && $snapshot['stripe_price_id'] === $scheduled->target_price_id
                        && (int) data_get($canonical, 'items.data.0.current_period_start', $canonical['current_period_start'] ?? 0) >= $effective;
                if ($applied) {
                    $scheduled->update(['status' => 'completed', 'completed_at' => now(), 'error_code' => null]);
                }
            }
        }
        $action = $record->exists ? Action::where('subscription_id', $record->id)->where('kind', 'upgrade')
            ->whereNotNull('submitted_at')->whereNotIn('status', ['completed', 'expired', 'cancelled'])->first() : null;
        if (! $action) {
            return [$snapshot, $this->onlyAppliedInvoices($canonical, $invoices)];
        }
        $invoice = collect($invoices)->first(fn ($candidate) => StudioSubscriptionInvoice::id($candidate) === $action->invoice_id);
        if (! $invoice && ! $action->invoice_id) {
            $candidate = $canonical['latest_invoice'] ?? null;
            if (is_array($candidate) && StudioSubscriptionInvoice::id($candidate) !== data_get($action->snapshot, 'latestInvoiceId')
                && (int) ($candidate['created'] ?? 0) >= $action->submitted_at->timestamp - 5
                && StudioSubscriptionInvoice::matches($action, $candidate)) {
                $invoice = $candidate;
                $action->invoice_id = StudioSubscriptionInvoice::id($candidate);
            }
        }
        $bound = is_array($invoice) && StudioSubscriptionInvoice::matches($action, $invoice);
        $paid = $bound && ($invoice['status'] ?? '') === 'paid' && (int) ($invoice['amount_remaining'] ?? -1) === 0;
        $applied = $snapshot['stripe_price_id'] === $action->target_price_id && empty($canonical['pending_update'])
            && (int) data_get($canonical, 'items.data.0.current_period_end', $canonical['current_period_end'] ?? 0) === (int) data_get($action->snapshot, 'periodEnd');
        if ($paid && $applied) {
            $action->fill(['status' => 'completed', 'completed_at' => now(), 'error_code' => null])->save();

            return [$snapshot, $invoices];
        }
        $oldPrice = $snapshot['stripe_price_id'] === $action->source_price_id;
        if ($bound && in_array($invoice['status'] ?? '', ['void', 'uncollectible'], true) && $oldPrice && empty($canonical['pending_update'])) {
            $action->fill(['status' => 'expired', 'error_code' => 'subscription_update_expired'])->save();

            return [$snapshot, $this->onlyAppliedInvoices($canonical, $invoices)];
        }
        $action->fill(['status' => $paid ? 'awaiting_application' : ($bound ? 'awaiting_payment' : 'needs_attention'),
            'error_code' => $bound ? null : 'subscription_reconciliation_required'])->save();
        // Retain the paid plan and billing period while either half of the update is unresolved.
        foreach (['stripe_price_id', 'plan_code', 'plan_name', 'amount_cents', 'currency', 'billing_interval',
            'billing_interval_count', 'current_period_start', 'current_period_end', 'status'] as $field) {
            $snapshot[$field] = $record->{$field};
        }
        $invoices = array_values(array_filter($invoices, fn ($candidate) => StudioSubscriptionInvoice::id($candidate) !== $action->invoice_id
            && ! StudioSubscriptionInvoice::matches($action, $candidate)));

        return [$snapshot, $invoices];
    }

    private function onlyAppliedInvoices(array $canonical, array $invoices): array
    {
        if (empty($canonical['pending_update'])) {
            return $invoices;
        }
        $current = StudioSubscriptionInvoice::id(data_get($canonical, 'items.data.0.price'));

        return array_values(array_filter($invoices, function ($invoice) use ($current) {
            if (($invoice['billing_reason'] ?? '') !== 'subscription_update') {
                return true;
            }
            foreach (data_get($invoice, 'lines.data', []) as $line) {
                $price = StudioSubscriptionInvoice::id(data_get($line, 'pricing.price_details.price') ?? $line['price'] ?? null);
                if ((int) ($line['amount'] ?? 0) > 0 && $price !== $current) {
                    return false;
                }
            }

            return true;
        }));
    }
}
