<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use Illuminate\Support\Collection;

class InvoicePricingBreakdown
{
    /** Store only booked pricing; later reads must not consult client settings. */
    public function snapshotForShoot(Shoot $shoot, ?float $netChargeSubtotal = null): array
    {
        $gross = round((float) $shoot->services->sum(fn ($service) => (float) ($service->pivot->nominal_value_snapshot ?? $service->pivot->price ?? $service->price ?? 0)
            * (int) ($service->pivot->quantity ?? 1)
        ), 2);
        $discount = max((float) ($shoot->discount_amount ?? 0), 0);
        $eligible = ! $shoot->isComplimentaryReshoot() && $shoot->status !== Shoot::STATUS_CANCELLED;
        $confirmed = $eligible && $this->sameMoney($gross - $discount, (float) $shoot->base_quote);

        return [
            'version' => 1,
            'shoot_id' => $shoot->id,
            'amount_basis' => $netChargeSubtotal === null ? 'gross' : 'net',
            'charge_subtotal' => $netChargeSubtotal ?? $gross,
            'service_subtotal' => $eligible ? $gross : ($netChargeSubtotal ?? 0),
            'discount_amount' => $confirmed ? round($discount, 2) : 0.0,
            'discount_type' => $confirmed ? $shoot->discount_type : null,
            'discount_value' => $confirmed ? $shoot->discount_value : null,
        ];
    }

    /** A presentation contract. Never refresh totals or write during invoice reads. */
    public function forInvoice(Invoice $invoice): array
    {
        $subtotal = round((float) ($invoice->subtotal ?? $invoice->charges_total ?? ((float) ($invoice->total ?? $invoice->total_amount ?? 0) - (float) ($invoice->tax ?? 0))), 2);
        $result = [
            'subtotal_before_discount' => $subtotal,
            'discount_amount' => 0.0,
            'subtotal' => $subtotal,
            'tax' => round((float) ($invoice->tax ?? 0), 2),
            'total' => round((float) ($invoice->total ?? $invoice->total_amount ?? $subtotal), 2),
            'invoice_adjustments_total' => 0.0,
            'pricing_adjustment_amount' => 0.0,
            'discount_source' => 'unavailable',
            'line_amount_basis' => 'unknown',
        ];
        if ($invoice->role !== Invoice::ROLE_CLIENT || ! $invoice->requiresPayment()) {
            return $result;
        }

        $items = $invoice->relationLoaded('items') ? $invoice->items : ($invoice->exists ? $invoice->items()->get() : collect());
        $expenses = $items->where('type', InvoiceItem::TYPE_EXPENSE)->reject(fn ($item) => ($item->meta['source'] ?? null) === 'admin_misc' && ! ($item->meta['bills_client'] ?? false)
        );
        $result['invoice_adjustments_total'] = round((float) $expenses->filter(fn ($item) => ($item->meta['source'] ?? null) === 'admin_misc'
        )->sum('total_amount'), 2);
        $charges = $items->where('type', InvoiceItem::TYPE_CHARGE);
        if ($charges->isEmpty()) {
            return $result;
        }

        $serviceSubtotal = 0.0;
        $discount = 0.0;
        $allServiceBasesKnown = true;
        $hasSnapshot = false;
        $amountBases = [];
        foreach ($charges->groupBy(fn ($item) => $item->shoot_id ?? $item->meta['shoot_id'] ?? $invoice->shoot_id ?? 'unattributed') as $group) {
            $chargeSubtotal = round((float) $group->sum('total_amount'), 2);
            $snapshot = $this->validatedSnapshot($group, $chargeSubtotal);
            if ($snapshot !== null) {
                $serviceSubtotal += (float) $snapshot['service_subtotal'];
                $discount += (float) $snapshot['discount_amount'];
                $hasSnapshot = true;
                $amountBases[] = $snapshot['amount_basis'];
            } else {
                $serviceSubtotal += $chargeSubtotal;
                // Historical period rows already contain net prices, so their
                // original service subtotal cannot be recovered from the row.
                $allServiceBasesKnown = $allServiceBasesKnown && $invoice->shoot_id !== null;
                $amountBases[] = $invoice->shoot_id !== null ? 'gross' : 'unknown';
            }
        }

        if (! $hasSnapshot) {
            $discount = $this->reconciledLegacyDiscount($invoice, $charges, (float) $expenses->sum('total_amount'), $subtotal);
        }
        $result['discount_amount'] = round($discount, 2);
        $result['discount_source'] = $hasSnapshot ? 'snapshot' : ($discount > 0 ? 'legacy_reconciled' : 'unavailable');
        $result['line_amount_basis'] = count(array_unique($amountBases)) === 1 ? $amountBases[0] : 'mixed';
        $basis = round($serviceSubtotal + (float) $expenses->sum('total_amount'), 2);
        $result['pricing_adjustment_amount'] = round($subtotal - ($basis - $discount), 2);
        $result['subtotal_before_discount'] = $basis;
        if ($allServiceBasesKnown) {
            $result['service_subtotal'] = round($serviceSubtotal, 2);
        }

        return $result;
    }

    private function validatedSnapshot(Collection $items, float $chargeSubtotal): ?array
    {
        $snapshot = $items->first()->meta['pricing_snapshot'] ?? null;
        if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
            || ! in_array($snapshot['amount_basis'] ?? null, ['gross', 'net'], true)
            || ! isset($snapshot['charge_subtotal'], $snapshot['service_subtotal'], $snapshot['discount_amount'])
            || ! $items->every(fn ($item) => ($item->meta['pricing_snapshot'] ?? null) === $snapshot)
            || ! $this->sameMoney((float) $snapshot['charge_subtotal'], $chargeSubtotal)) {
            return null;
        }

        return $snapshot;
    }

    private function reconciledLegacyDiscount(Invoice $invoice, Collection $charges, float $expenses, float $subtotal): float
    {
        if (! $invoice->shoot_id || $charges->contains(fn ($item) => ($item->shoot_id !== null && (int) $item->shoot_id !== (int) $invoice->shoot_id)
            || ($item->meta['waived_due_to_cancellation'] ?? false)
            || ($item->meta['cancellation_fee'] ?? false)
            || ($item->meta['complimentary_reshoot'] ?? false)
        )) {
            return 0.0;
        }
        $shoot = $invoice->relationLoaded('shoot') ? $invoice->shoot : $invoice->shoot()->first();
        if (! $shoot || $shoot->isComplimentaryReshoot() || $shoot->status === Shoot::STATUS_CANCELLED) {
            return 0.0;
        }
        $discount = max((float) ($shoot->discount_amount ?? 0), 0);
        $base = (float) ($shoot->base_quote ?? 0);

        return $discount > 0
            && $this->sameMoney((float) $charges->sum('total_amount'), $base + $discount)
            && $this->sameMoney($subtotal, $base + $expenses)
                ? round($discount, 2) : 0.0;
    }

    private function sameMoney(float $left, float $right): bool
    {
        return round($left * 100) === round($right * 100);
    }
}
