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

        $serviceSummaries = $items->map(function (ShootService $item) use ($shoot, $paidByItem, $hasAllocations, $brackets) {
            $subtotal = $this->subtotal($item);
            $paidAmount = (float) ($paidByItem[$item->id] ?? 0);

            if (! $hasAllocations && $shoot->payment_status === 'paid') {
                $paidAmount = $subtotal;
            }

            $paidAmount = round(min($paidAmount, $subtotal), 2);
            $balanceDue = max(round($subtotal - $paidAmount, 2), 0);
            $paymentStatus = $subtotal <= 0.01 || $balanceDue <= 0.01
                ? ShootService::PAYMENT_PAID
                : ($paidAmount <= 0 ? ShootService::PAYMENT_UNPAID : ShootService::PAYMENT_PARTIALLY_PAID);
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
                'uses_hdr_brackets' => $brackets->serviceUsesBrackets($item),
                'usesHdrBrackets' => $brackets->serviceUsesBrackets($item),
                'bracket_mode' => $item->bracket_mode,
                'bracketMode' => $item->bracket_mode,
                'effective_bracket_mode' => $brackets->effectiveBracketMode($item),
                'effectiveBracketMode' => $brackets->effectiveBracketMode($item),
                'expected_raw_count' => $brackets->expectedRawForService($item),
                'expectedRawCount' => $brackets->expectedRawForService($item),
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

        return PaymentServiceAllocation::query()
            ->select('payment_service_allocations.shoot_service_id', DB::raw('SUM(payment_service_allocations.amount) as paid_amount'))
            ->join('payments', 'payments.id', '=', 'payment_service_allocations.payment_id')
            ->whereIn('payment_service_allocations.shoot_service_id', $items->pluck('id')->all())
            ->where('payments.status', Payment::STATUS_COMPLETED)
            ->when($excludePaymentId, fn ($query) => $query->where('payments.id', '!=', $excludePaymentId))
            ->groupBy('payment_service_allocations.shoot_service_id')
            ->pluck('paid_amount', 'shoot_service_id')
            ->map(fn ($amount) => round((float) $amount, 2))
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
