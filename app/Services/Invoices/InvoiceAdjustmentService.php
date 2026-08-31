<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Shoot;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\Shoots\ShootListingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InvoiceAdjustmentService
{
    /**
     * Every client invoice that can legitimately contain rows for a shoot.
     * Legacy data uses three association shapes, so no caller should query only
     * invoices.shoot_id when resolving a shoot invoice.
     *
     * Direct invoices are ordered first because they remain the canonical
     * single-shoot document when both a direct and a period invoice exist.
     *
     * @return Collection<int, Invoice>
     */
    public function clientInvoicesForShoot(Shoot $shoot): Collection
    {
        return Invoice::query()
            ->where('role', Invoice::ROLE_CLIENT)
            ->where(function ($query) use ($shoot) {
                $query->where('shoot_id', $shoot->id)
                    ->orWhereHas('shoots', fn ($shootQuery) => $shootQuery->whereKey($shoot->id))
                    ->orWhereHas('items', fn ($itemQuery) => $itemQuery->where('shoot_id', $shoot->id));
            })
            ->orderByRaw('CASE WHEN shoot_id = ? THEN 0 ELSE 1 END', [$shoot->id])
            ->orderByDesc('id')
            ->get();
    }

    public function preferredClientInvoiceForShoot(Shoot $shoot): ?Invoice
    {
        return $this->clientInvoicesForShoot($shoot)->first();
    }

    /**
     * Resolve every shoot association used by the legacy invoice models: the
     * direct foreign key, the invoice_shoot pivot, and attributed invoice rows.
     *
     * @return Collection<int, Shoot>
     */
    public function relatedShoots(Invoice $invoice): Collection
    {
        $shoots = collect();

        if ($invoice->shoot_id) {
            $directShoot = Shoot::find($invoice->shoot_id);
            if ($directShoot) {
                $shoots->push($directShoot);
            }
        }

        $shoots = $shoots->merge($invoice->shoots()->get());

        $itemShootIds = $invoice->items()
            ->whereNotNull('shoot_id')
            ->pluck('shoot_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($itemShootIds->isNotEmpty()) {
            $shoots = $shoots->merge(Shoot::whereKey($itemShootIds)->get());
        }

        return $shoots
            ->filter(fn ($shoot) => $shoot instanceof Shoot)
            ->unique(fn (Shoot $shoot) => (int) $shoot->id)
            ->values();
    }

    public function resolveTargetShoot(
        Invoice $invoice,
        int|string|null $requestedShootId,
        bool $billable
    ): ?Shoot {
        if ($billable && $invoice->role !== Invoice::ROLE_CLIENT) {
            throw ValidationException::withMessages([
                'bills_client' => ['Only client invoices can add an amount to a shoot payable.'],
            ]);
        }

        $relatedShoots = $this->relatedShoots($invoice);

        if ($requestedShootId !== null && $requestedShootId !== '') {
            $target = $relatedShoots->first(
                fn (Shoot $shoot) => (string) $shoot->id === (string) $requestedShootId
            );

            if (! $target) {
                throw ValidationException::withMessages([
                    'shoot_id' => ['The selected shoot is not linked to this invoice.'],
                ]);
            }

            return $target;
        }

        if ($invoice->shoot_id) {
            return $relatedShoots->first(
                fn (Shoot $shoot) => (string) $shoot->id === (string) $invoice->shoot_id
            );
        }

        if ($relatedShoots->count() === 1) {
            return $relatedShoots->first();
        }

        if ($billable) {
            throw ValidationException::withMessages([
                'shoot_id' => ['Choose which shoot this billable adjustment belongs to.'],
            ]);
        }

        return null;
    }

    /**
     * Billable invoice-only lines belonging to a shoot, aggregated across every
     * direct invoice instead of selecting an arbitrary first invoice.
     *
     * @return Collection<int, InvoiceItem>
     */
    public function billableItemsForShoot(Shoot $shoot): Collection
    {
        if (! $shoot->exists || ! $shoot->getKey()) {
            return collect();
        }

        return InvoiceItem::query()
            ->where('type', InvoiceItem::TYPE_EXPENSE)
            ->whereHas('invoice', fn ($query) => $query->where('role', Invoice::ROLE_CLIENT))
            ->where(function ($query) use ($shoot) {
                $query->where('shoot_id', $shoot->id)
                    ->orWhere(function ($legacyQuery) use ($shoot) {
                        $legacyQuery->whereNull('shoot_id')
                            ->whereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('shoot_id', $shoot->id));
                    });
            })
            ->orderBy('id')
            ->get()
            ->filter(function (InvoiceItem $item) {
                $meta = is_array($item->meta) ? $item->meta : [];

                return ($meta['source'] ?? null) === 'admin_misc'
                    && (bool) ($meta['bills_client'] ?? false);
            })
            ->values();
    }

    /**
     * Structured, non-deliverable order rows. They explain payable changes but
     * deliberately have no shoot_service_id, so payment allocation and media
     * delivery can never mistake them for an operational service.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summaries(Shoot $shoot): array
    {
        $shootIsPaid = in_array(strtolower((string) $shoot->payment_status), ['paid', 'full'], true);

        return $this->billableItemsForShoot($shoot)
            ->map(function (InvoiceItem $item) use ($shootIsPaid) {
                $meta = is_array($item->meta) ? $item->meta : [];
                $quantity = max((int) ($item->quantity ?? 1), 1);
                $unitAmount = round((float) ($item->unit_amount ?? 0), 2);
                $totalAmount = round((float) ($item->total_amount ?? ($unitAmount * $quantity)), 2);
                $paidAmount = $shootIsPaid ? $totalAmount : 0.0;
                $balanceDue = $shootIsPaid ? 0.0 : $totalAmount;

                return [
                    'id' => 'invoice-adjustment-'.$item->id,
                    'shoot_service_id' => null,
                    'shootServiceId' => null,
                    'service_id' => null,
                    'serviceId' => null,
                    'invoice_id' => (int) $item->invoice_id,
                    'invoiceId' => (int) $item->invoice_id,
                    'invoice_item_id' => (int) $item->id,
                    'invoiceItemId' => (int) $item->id,
                    'source' => 'invoice_adjustment',
                    'is_invoice_adjustment' => true,
                    'isInvoiceAdjustment' => true,
                    'name' => $item->description,
                    'serviceName' => $item->description,
                    'description' => $item->description,
                    'category' => 'Invoice adjustment',
                    'price' => $unitAmount,
                    'unit_amount' => $unitAmount,
                    'unitAmount' => $unitAmount,
                    'quantity' => $quantity,
                    'subtotal' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'totalAmount' => $totalAmount,
                    'bills_client' => true,
                    'billsClient' => true,
                    'charge_type' => $meta['charge_type'] ?? 'misc',
                    'chargeType' => $meta['charge_type'] ?? 'misc',
                    'scheduled_at' => null,
                    'scheduledAt' => null,
                    'workflow_status' => 'not_applicable',
                    'workflowStatus' => 'not_applicable',
                    'delivery_status' => 'not_applicable',
                    'deliveryStatus' => 'not_applicable',
                    'is_deliverable' => false,
                    'isDeliverable' => false,
                    'paid_amount' => $paidAmount,
                    'paidAmount' => $paidAmount,
                    'balance_due' => $balanceDue,
                    'balanceDue' => $balanceDue,
                    'payment_status' => $shootIsPaid ? 'paid' : 'unpaid',
                    'paymentStatus' => $shootIsPaid ? 'paid' : 'unpaid',
                    'force_unlock_delivery' => false,
                    'forceUnlockDelivery' => false,
                    'is_unlocked_for_delivery' => true,
                    'isUnlockedForDelivery' => true,
                    'unlock_state' => 'not_applicable',
                    'unlockState' => 'not_applicable',
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    public function names(Shoot $shoot): array
    {
        return collect($this->summaries($shoot))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    public function applyShootPayableDelta(?Shoot $shoot, float $delta): ?Shoot
    {
        if (! $shoot || abs($delta) < 0.005) {
            return $shoot;
        }

        $lockedShoot = Shoot::query()->lockForUpdate()->find($shoot->id);
        if (! $lockedShoot) {
            return null;
        }

        $newTotal = round(max((float) ($lockedShoot->total_quote ?? 0) + $delta, 0), 2);
        $lockedShoot->total_quote = $newTotal;

        if ($newTotal > 0.01 && $lockedShoot->bypass_paywall) {
            $lockedShoot->bypass_paywall = false;
        }

        $lockedShoot->save();
        $lockedShoot->syncPaymentStatusFromRecords();

        return $lockedShoot->fresh();
    }

    /**
     * Keep all client-invoice total aliases tax-aware and aligned. This applies
     * only the billable line delta, preserving existing discounts and tax rather
     * than rebuilding the invoice from pre-discount service rows.
     */
    public function applyInvoiceTotalDelta(Invoice $invoice, float $delta): Invoice
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

        $oldSubtotal = (float) ($invoice->subtotal ?? max((float) ($invoice->total ?? $invoice->total_amount ?? 0) - (float) ($invoice->tax ?? 0), 0));
        $oldTotal = (float) ($invoice->total ?? $invoice->total_amount ?? ($oldSubtotal + (float) ($invoice->tax ?? 0)));
        $newSubtotal = round(max($oldSubtotal + $delta, 0), 2);
        $newTotal = round(max($oldTotal + $delta, 0), 2);

        // Payment records (including their refunds) are authoritative whenever
        // they exist. Stored aliases are a legacy fallback only; taking max()
        // would resurrect refunded money from a stale payments_total column.
        $hasPaymentRecords = $invoice->hasRelatedPaymentRecords();
        $paid = $hasPaymentRecords
            ? (float) $invoice->totalPaid()
            : max(
                (float) ($invoice->amount_paid ?? 0),
                (float) ($invoice->payments_total ?? 0)
            );
        $paid = round($paid, 2);
        $balance = round(max($newTotal - $paid, 0), 2);
        $isPaid = $newTotal <= 0.01 || $balance <= 0.01;

        $updates = [
            'subtotal' => $newSubtotal,
            'total' => $newTotal,
            'total_amount' => $newTotal,
            'amount_paid' => $paid,
            'is_paid' => $isPaid,
        ];

        $schema = $invoice->getConnection()->getSchemaBuilder();
        if ($schema->hasColumn($invoice->getTable(), 'charges_total')) {
            $updates['charges_total'] = $newSubtotal;
        }
        if ($schema->hasColumn($invoice->getTable(), 'payments_total')) {
            $updates['payments_total'] = $paid;
        }
        if ($schema->hasColumn($invoice->getTable(), 'balance_due')) {
            $updates['balance_due'] = $balance;
        }

        if ($isPaid) {
            $updates['status'] = Invoice::STATUS_PAID;
            $updates['paid_at'] = $paid > 0
                ? ($invoice->latestEffectivePaymentAt() ?? $invoice->paid_at)
                : null;
        } else {
            $updates['paid_at'] = null;
            if ($invoice->status === Invoice::STATUS_PAID) {
                $updates['status'] = Invoice::STATUS_SENT;
            }
        }

        $invoice->forceFill($updates)->save();

        return $invoice->fresh();
    }

    /**
     * Recompute every related client invoice after a shoot payment/refund.
     * Aggregate invoices are safe here because their paid total is recalculated
     * from all of their related shoots rather than copied from this one shoot.
     *
     * @return Collection<int, Invoice>
     */
    public function reconcileClientInvoicesForShoot(
        Shoot $shoot,
        ?Payment $payment = null,
        ?string $paymentMethod = null,
        mixed $paymentDetails = null
    ): Collection {
        $invoices = $this->clientInvoicesForShoot($shoot);
        $preferred = $invoices->first();
        $relatedInvoiceIds = $invoices->pluck('id')->map(fn ($id) => (int) $id);

        // Do not rewrite a valid historical/aggregate association merely
        // because a newer direct invoice is now preferred. Attach only an
        // unassigned or genuinely unrelated payment to the preferred invoice.
        if ($payment
            && $preferred
            && (! $payment->invoice_id || ! $relatedInvoiceIds->contains((int) $payment->invoice_id))) {
            $payment->forceFill(['invoice_id' => $preferred->id])->save();
        }

        return $invoices->map(function (Invoice $invoice) use ($paymentMethod, $paymentDetails) {
            if ($paymentMethod !== null && $paymentMethod !== '') {
                $invoice->forceFill([
                    'payment_method' => $paymentMethod,
                    'payment_details' => is_array($paymentDetails) ? $paymentDetails : null,
                ])->save();
            }

            return $this->applyInvoiceTotalDelta($invoice, 0.0);
        })->values();
    }

    /**
     * Clear both operational listing caches and the date-bucket cache used by
     * the dashboard overview. Dashboard upcoming shoots are cached under today,
     * even when the changed shoot is scheduled for a future date.
     *
     * @param  iterable<int, Shoot>  $shoots
     */
    public function invalidateShootCaches(iterable $shoots): void
    {
        try {
            ShootListingService::flushCachedListings();

            $scheduleScope = app(ScheduleDateScopeService::class);
            $dates = collect($shoots)
                ->filter(fn ($shoot) => $shoot instanceof Shoot)
                ->map(fn (Shoot $shoot) => $scheduleScope->localDateForShoot($shoot))
                ->filter()
                ->push(now()->startOfDay()->toDateString())
                ->unique()
                ->values()
                ->all();

            $scheduleScope->invalidateDates($dates);
        } catch (\Throwable $exception) {
            // The database mutation is already committed. A transient cache
            // failure must not make a successful invoice change look failed.
            Log::warning('Could not invalidate shoot caches after invoice adjustment', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
