<?php

namespace App\Services\Shoots;

use App\Models\Payment;
use App\Models\PaymentServiceAllocation;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Services\Invoices\InvoiceAdjustmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShootServiceItemSupport
{
    /**
     * Display-only identity for every booked execution row on the shoot.
     *
     * Separate from `summaries()` on purpose. `summaries()` is the operational
     * payload and is legitimately narrowed per role — editors only receive the
     * services they may work, photographers only the ones assigned to them. That
     * narrowing is a workflow-eligibility decision, and reusing it as a naming
     * source meant a file whose service the viewer may see but may not edit had
     * no resolvable name and rendered as "Service #<id>".
     *
     * Naming is not a permission. A viewer who is already allowed to see a file
     * is allowed to know which booked service produced it, so this projection is
     * never filtered. It carries no pricing, no assignments and no workflow
     * state — only the pivot id, the catalogue id and the name — so widening it
     * cannot widen access to anything else.
     *
     * @return list<array{shoot_service_id:int,shootServiceId:int,service_id:int|null,serviceId:int|null,name:string|null,serviceName:string|null}>
     */
    public function presentation(Shoot $shoot): array
    {
        return $shoot->serviceItems()
            ->with('service:id,name')
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ShootService $item) => [
                'shoot_service_id' => $item->id,
                'shootServiceId' => $item->id,
                'service_id' => $item->service_id,
                'serviceId' => $item->service_id,
                'name' => $item->service?->name,
                'serviceName' => $item->service?->name,
            ])
            ->values()
            ->all();
    }

    public function summaries(Shoot $shoot): array
    {
        $shoot->loadMissing('photographer');
        $items = $shoot->serviceItems()
            ->with(['service', 'photographer', 'editor', 'unlockedBy'])
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return app(InvoiceAdjustmentService::class)->summaries($shoot);
        }

        $paidByItem = $this->paidAmountsByItem($items);
        $hasAllocations = PaymentServiceAllocation::query()
            ->whereIn('shoot_service_id', $items->pluck('id'))
            ->exists();

        $brackets = app(BracketModeResolver::class);
        $intake = app(UploadIntakeResolver::class);

        $serviceSummaries = $items->map(function (ShootService $item) use ($shoot, $paidByItem, $hasAllocations, $brackets, $intake) {
            $subtotal = $this->subtotal($item);
            $paidAmount = (float) ($paidByItem[$item->id] ?? 0);

            if (! $hasAllocations && $shoot->payment_status === 'paid') {
                $paidAmount = $subtotal;
            }

            $paidAmount = round(min($paidAmount, $subtotal), 2);
            $balanceDue = max(round($subtotal - $paidAmount, 2), 0);
            $paymentStatus = $shoot->isComplimentaryReshoot()
                ? Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED
                : ($subtotal <= 0.01 || $balanceDue <= 0.01
                    ? ShootService::PAYMENT_PAID
                    : ($paidAmount <= 0 ? ShootService::PAYMENT_UNPAID : ShootService::PAYMENT_PARTIALLY_PAID));
            $unlockState = $this->unlockState($shoot, $item, $paymentStatus);

            return [
                'id' => $item->id,
                'shoot_service_id' => $item->id,
                'shootServiceId' => $item->id,
                'service_id' => $item->service_id,
                'serviceId' => $item->service_id,
                'name' => $item->service?->name,
                'serviceName' => $item->service?->name,
                'category' => $item->service?->category,
                'description' => $item->service?->description,
                'price' => (float) ($item->price ?? 0),
                'quantity' => (int) ($item->quantity ?? 1),
                'subtotal' => $subtotal,
                'photographer_pay' => $item->photographer_pay !== null ? (float) $item->photographer_pay : null,
                'photographerPay' => $item->photographer_pay !== null ? (float) $item->photographer_pay : null,
                'photographer_id' => $item->photographer_id,
                'photographerId' => $item->photographer_id,
                'photographer' => $this->compactUser($item->photographer),
                // Bracket state is per service item because two services on one
                // shoot can be captured by different photographers at different
                // sizes. `uses_hdr_brackets` says whether this deliverable stacks
                // exposures at all; `bracket_mode` is the recorded execution value;
                // `effective_bracket_mode` is what stacking will actually use, and
                // is null when the service does not bracket.
                // Upload capability is catalogue data, never inferred from the name or
                // category. The client uses these to build lane-specific selectors:
                // a service is offered to the photo lane or the video lane only if it
                // explicitly declares that lane.
                'upload_intake_type' => $intake->intakeTypeFor($item),
                'uploadIntakeType' => $intake->intakeTypeFor($item),
                'supports_photo_intake' => $intake->supportsPhotoIntake($item),
                'supportsPhotoIntake' => $intake->supportsPhotoIntake($item),
                'supports_video_intake' => $intake->supportsVideoIntake($item),
                'supportsVideoIntake' => $intake->supportsVideoIntake($item),
                'uses_hdr_brackets' => $brackets->serviceUsesBrackets($item),
                'usesHdrBrackets' => $brackets->serviceUsesBrackets($item),
                'bracket_mode' => $item->bracket_mode,
                'bracketMode' => $item->bracket_mode,
                'effective_bracket_mode' => $brackets->effectiveBracketMode($item),
                'effectiveBracketMode' => $brackets->effectiveBracketMode($item),
                // Null means "this item owes photos but no count is configured", which
                // is different from 0 ("owes no photos"). The client must render the
                // former as unset rather than fabricating a denominator.
                'expected_raw_count' => $brackets->expectedRawForService($item),
                'expectedRawCount' => $brackets->expectedRawForService($item),
                'expected_raw_unspecified' => $brackets->expectedRawUnspecified($item),
                'expectedRawUnspecified' => $brackets->expectedRawUnspecified($item),
                'photo_count' => $intake->contractedPhotoCount($item),
                'photoCount' => $intake->contractedPhotoCount($item),
                'resolved_photographer_id' => $item->photographer_id ?? $shoot->photographer_id,
                'resolvedPhotographerId' => $item->photographer_id ?? $shoot->photographer_id,
                'resolved_photographer' => $this->compactUser($item->photographer ?: $shoot->photographer),
                'resolvedPhotographer' => $this->compactUser($item->photographer ?: $shoot->photographer),
                'editor_id' => $item->editor_id,
                'editorId' => $item->editor_id,
                'editor' => $this->compactUser($item->editor),
                'scheduled_at' => $item->scheduled_at?->toIso8601String(),
                'scheduledAt' => $item->scheduled_at?->toIso8601String(),
                'workflow_status' => $item->workflow_status ?? ShootService::WORKFLOW_PENDING,
                'workflowStatus' => $item->workflow_status ?? ShootService::WORKFLOW_PENDING,
                'delivery_status' => $item->delivery_status ?? ShootService::DELIVERY_NOT_STARTED,
                'deliveryStatus' => $item->delivery_status ?? ShootService::DELIVERY_NOT_STARTED,
                'ready_at' => $item->ready_at?->toIso8601String(),
                'readyAt' => $item->ready_at?->toIso8601String(),
                'delivered_at' => $item->delivered_at?->toIso8601String(),
                'deliveredAt' => $item->delivered_at?->toIso8601String(),
                'cancelled_at' => $item->cancelled_at?->toIso8601String(),
                'cancelledAt' => $item->cancelled_at?->toIso8601String(),
                'is_deliverable' => (bool) $item->is_deliverable,
                'isDeliverable' => (bool) $item->is_deliverable,
                'force_unlock_delivery' => (bool) $item->force_unlock_delivery,
                'forceUnlockDelivery' => (bool) $item->force_unlock_delivery,
                'unlock_reason' => $item->unlock_reason,
                'unlockReason' => $item->unlock_reason,
                'unlocked_by' => $item->unlocked_by,
                'unlockedBy' => $this->compactUser($item->unlockedBy),
                'paid_amount' => $paidAmount,
                'paidAmount' => $paidAmount,
                'balance_due' => $balanceDue,
                'balanceDue' => $balanceDue,
                'payment_status' => $paymentStatus,
                'paymentStatus' => $paymentStatus,
                'is_unlocked_for_delivery' => $unlockState !== 'locked',
                'isUnlockedForDelivery' => $unlockState !== 'locked',
                'unlock_state' => $unlockState,
                'unlockState' => $unlockState,
            ];
        })->values()->all();

        return array_merge(
            $serviceSummaries,
            app(InvoiceAdjustmentService::class)->summaries($shoot)
        );
    }

    public function allocatePayment(Payment $payment, Shoot $shoot, array $options = []): void
    {
        $items = $shoot->serviceItems()
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return;
        }

        $allocations = $this->resolveAllocationRows($items, $amount, $payment->id, $options);

        DB::transaction(function () use ($payment, $allocations) {
            foreach ($allocations as $row) {
                if ((float) $row['amount'] <= 0) {
                    continue;
                }

                PaymentServiceAllocation::updateOrCreate(
                    [
                        'payment_id' => $payment->id,
                        'shoot_service_id' => $row['shoot_service_id'],
                    ],
                    [
                        'amount' => round((float) $row['amount'], 2),
                    ]
                );
            }
        });
    }

    /**
     * Keep per-service paid/balance projections aligned after service rows are
     * removed, replaced, or repriced. Payment records remain authoritative and
     * untouched; only their operational allocation rows are rebuilt.
     */
    public function reconcileAllocationsAfterServiceChange(Shoot $shoot): void
    {
        $items = $shoot->serviceItems()
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $allPayments = $shoot->payments()
            ->with(['refunds', 'serviceAllocations'])
            ->orderBy('processed_at')
            ->orderBy('id')
            ->get();
        $paymentIds = $allPayments->pluck('id')->all();

        if ($paymentIds === []) {
            return;
        }

        $existingByPayment = $allPayments->mapWithKeys(fn (Payment $payment) => [
            (int) $payment->id => $payment->serviceAllocations
                ->map(fn (PaymentServiceAllocation $allocation) => [
                    'shoot_service_id' => (int) $allocation->shoot_service_id,
                    'amount' => round((float) $allocation->amount, 2),
                ])
                ->values()
                ->all(),
        ]);

        PaymentServiceAllocation::query()->whereIn('payment_id', $paymentIds)->delete();

        if ($items->isEmpty()) {
            return;
        }

        $shoot->setRelation('payments', $allPayments);
        $canonicalPaymentIds = $shoot->getCanonicalCompletedPayments()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $capacity = $items->mapWithKeys(fn (ShootService $item) => [
            (int) $item->id => $this->subtotal($item),
        ])->all();
        $validItemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($allPayments->whereIn('id', $canonicalPaymentIds) as $payment) {
            // Allocation rows retain their gross-payment basis. Refunds are
            // applied proportionally at read time in paidAmountsByItem(); using
            // netAmount here would apply the same refund twice after a service
            // change rebuild.
            $remainingPayment = round((float) $payment->amount, 2);
            $rows = [];

            foreach ($existingByPayment->get((int) $payment->id, []) as $existing) {
                $itemId = (int) $existing['shoot_service_id'];
                if (! in_array($itemId, $validItemIds, true) || $remainingPayment <= 0.01) {
                    continue;
                }

                $amount = min(
                    round((float) $existing['amount'], 2),
                    round((float) ($capacity[$itemId] ?? 0), 2),
                    $remainingPayment
                );
                if ($amount <= 0.01) {
                    continue;
                }

                $rows[$itemId] = round(($rows[$itemId] ?? 0) + $amount, 2);
                $capacity[$itemId] = round(max(($capacity[$itemId] ?? 0) - $amount, 0), 2);
                $remainingPayment = round(max($remainingPayment - $amount, 0), 2);
            }

            foreach ($items as $item) {
                $itemId = (int) $item->id;
                if ($remainingPayment <= 0.01) {
                    break;
                }

                $amount = min((float) ($capacity[$itemId] ?? 0), $remainingPayment);
                if ($amount <= 0.01) {
                    continue;
                }

                $rows[$itemId] = round(($rows[$itemId] ?? 0) + $amount, 2);
                $capacity[$itemId] = round(max(($capacity[$itemId] ?? 0) - $amount, 0), 2);
                $remainingPayment = round(max($remainingPayment - $amount, 0), 2);
            }

            foreach ($rows as $itemId => $amount) {
                PaymentServiceAllocation::create([
                    'payment_id' => $payment->id,
                    'shoot_service_id' => $itemId,
                    'amount' => $amount,
                ]);
            }
        }
    }

    public function requiresExplicitAllocation(Shoot $shoot, float $amount, array $payload): bool
    {
        if ($shoot->serviceItems()->count() === 0) {
            return false;
        }

        $remaining = max((float) ($shoot->total_quote ?? 0) - (float) $shoot->calculateCanonicalTotalPaid(), 0);
        $isPartial = $amount > 0 && $amount + 0.01 < $remaining;

        if (! $isPartial) {
            return false;
        }

        return empty($payload['shoot_service_ids'])
            && empty($payload['allocations'])
            && empty($payload['allocation_strategy']);
    }

    public function paidAmountsByItem(Collection $items, ?int $excludePaymentId = null): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $allocations = PaymentServiceAllocation::with(['payment.refunds'])
            ->whereIn('shoot_service_id', $items->pluck('id')->all())
            ->whereHas('payment', fn ($query) => $query->where('status', Payment::STATUS_COMPLETED))
            ->when($excludePaymentId, fn ($query) => $query->where('payment_id', '!=', $excludePaymentId))
            ->get();

        return $allocations
            ->groupBy('shoot_service_id')
            ->map(function (Collection $itemAllocations): float {
                return round((float) $itemAllocations->sum(function (PaymentServiceAllocation $allocation) {
                    $payment = $allocation->payment;
                    $grossAmount = (float) ($payment?->amount ?? 0);
                    if (! $payment || $grossAmount <= 0) {
                        return 0;
                    }

                    // Refunds apply to the charge as a whole. Reduce each
                    // service allocation by the same net/gross ratio so
                    // partially refunded services no longer appear fully paid.
                    $netRatio = min(max($payment->netAmount() / $grossAmount, 0), 1);

                    return (float) $allocation->amount * $netRatio;
                }), 2);
            })
            ->all();
    }

    protected function resolveAllocationRows(Collection $items, float $amount, int $paymentId, array $options): array
    {
        if (! empty($options['allocations']) && is_array($options['allocations'])) {
            $rows = collect($options['allocations'])->map(function ($allocation) use ($items) {
                $itemId = (int) ($allocation['shoot_service_id'] ?? $allocation['service_item_id'] ?? 0);

                if (! $items->contains('id', $itemId)) {
                    throw ValidationException::withMessages([
                        'allocations' => ['One or more payment allocations do not belong to this shoot.'],
                    ]);
                }

                return [
                    'shoot_service_id' => $itemId,
                    'amount' => round((float) ($allocation['amount'] ?? 0), 2),
                ];
            })->filter(fn ($row) => $row['amount'] > 0)->values();

            if ($rows->sum('amount') > $amount + 0.01) {
                throw ValidationException::withMessages([
                    'allocations' => ['Payment allocations cannot exceed the payment amount.'],
                ]);
            }

            return $rows->all();
        }

        $selectedIds = collect($options['shoot_service_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $targetItems = $selectedIds->isNotEmpty()
            ? $items->whereIn('id', $selectedIds->all())->values()
            : $items;

        if ($selectedIds->isNotEmpty() && $targetItems->count() !== $selectedIds->count()) {
            throw ValidationException::withMessages([
                'shoot_service_ids' => ['One or more selected services do not belong to this shoot.'],
            ]);
        }

        $paidByItem = $this->paidAmountsByItem($items, $paymentId);
        $remainingPayment = $amount;
        $rows = [];

        foreach ($targetItems as $item) {
            $balance = max($this->subtotal($item) - (float) ($paidByItem[$item->id] ?? 0), 0);

            if ($balance <= 0.01 || $remainingPayment <= 0.01) {
                continue;
            }

            $allocated = min($balance, $remainingPayment);
            $rows[] = [
                'shoot_service_id' => $item->id,
                'amount' => round($allocated, 2),
            ];
            $remainingPayment = round($remainingPayment - $allocated, 2);
        }

        return $rows;
    }

    protected function subtotal(ShootService $item): float
    {
        return round((float) ($item->price ?? 0) * max((int) ($item->quantity ?? 1), 1), 2);
    }

    protected function unlockState(Shoot $shoot, ShootService $item, string $paymentStatus): string
    {
        if ($item->force_unlock_delivery) {
            return 'admin_unlocked';
        }

        if ($shoot->bypass_paywall) {
            return 'bypass';
        }

        if ($paymentStatus === ShootService::PAYMENT_PAID || $shoot->payment_status === 'paid') {
            return 'unlocked';
        }

        return 'locked';
    }

    protected function compactUser(mixed $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
