<?php

namespace App\Services\Shoots;

use App\Models\PaymentServiceAllocation;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootMediaAlbum;
use App\Models\ShootService;
use App\Models\ShootUploadAttempt;
use App\Models\User;
use App\Services\Invoices\InvoiceAdjustmentService;
use Illuminate\Http\Exceptions\HttpResponseException;

class ShootServiceChangeGuard
{
    private const ZERO_SERVICE_ROLES = ['admin', 'superadmin', 'super_admin'];

    private const PRE_DELIVERY_STATUSES = [
        Shoot::STATUS_REQUESTED,
        Shoot::STATUS_SCHEDULED,
        Shoot::STATUS_ON_HOLD,
        Shoot::STATUS_UPLOADED,
        Shoot::STATUS_EDITING,
        Shoot::STATUS_REVIEW,
        Shoot::STATUS_READY,
    ];

    public function __construct(
        protected ShootMutationSupportService $support,
        protected InvoiceAdjustmentService $invoiceAdjustments
    ) {}

    public function canRemoveAllServices(Shoot $shoot, ?User $actor): bool
    {
        return $actor !== null
            && in_array($this->normalizeRole($actor->role), self::ZERO_SERVICE_ROLES, true)
            && in_array($this->normalizeStatus($shoot), self::PRE_DELIVERY_STATUSES, true);
    }

    /**
     * Validate a target service set and require a state-bound acknowledgement
     * before any existing service item is deleted.
     */
    public function assertChangeAllowed(
        Shoot $shoot,
        array $targetServices,
        User $actor,
        bool $confirmed = false,
        ?string $confirmationToken = null,
        ?float $adjustedTotal = null,
        ?string $pricingState = null,
        ?string $taxRegion = null
    ): ?array {
        $shoot->loadMissing(['client', 'serviceItems.service']);
        $targetServiceIds = collect($targetServices)
            ->map(fn (array $service) => (int) ($service['id'] ?? $service['service_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($targetServiceIds->isEmpty() && ! $this->canRemoveAllServices($shoot, $actor)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Only Admin and Super Admin can save a pre-delivery shoot without services.',
                'errors' => ['services' => ['At least one service must be selected.']],
            ], 422));
        }

        $removedItems = $shoot->serviceItems
            ->filter(fn (ShootService $item) => ! $targetServiceIds->contains((int) $item->service_id))
            ->values();

        if ($removedItems->isEmpty()) {
            return null;
        }

        if (! in_array($this->normalizeStatus($shoot), self::PRE_DELIVERY_STATUSES, true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Services cannot be removed after delivery or from a cancelled or declined shoot.',
                'errors' => ['services' => ['Service removal is only available before delivery.']],
            ], 422));
        }

        $impact = $this->buildImpact(
            $shoot,
            $targetServices,
            $removedItems,
            $adjustedTotal,
            $pricingState,
            $taxRegion
        );
        $expectedToken = $this->confirmationToken($shoot, $actor, $targetServices, $impact);

        if (! $confirmed || ! is_string($confirmationToken) || ! hash_equals($expectedToken, $confirmationToken)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Confirm service removal before saving this shoot.',
                'code' => 'service_detach_confirmation_required',
                'confirmation_token' => $expectedToken,
                'confirmationToken' => $expectedToken,
                'impact' => $impact,
            ], 409));
        }

        return $impact;
    }

    private function buildImpact(
        Shoot $shoot,
        array $targetServices,
        $removedItems,
        ?float $adjustedTotal,
        ?string $pricingState,
        ?string $taxRegion
    ): array {
        $removedIds = $removedItems->pluck('id')->map(fn ($id) => (int) $id)->all();
        $files = ShootFile::query()->whereIn('shoot_service_id', $removedIds)->count();
        $albums = ShootMediaAlbum::query()->whereIn('shoot_service_id', $removedIds)->count();
        $uploadAttempts = ShootUploadAttempt::query()->whereIn('shoot_service_id', $removedIds)->count();
        $allocated = (float) PaymentServiceAllocation::query()
            ->whereIn('shoot_service_id', $removedIds)
            ->sum('amount');
        $assignmentCount = $removedItems->filter(
            fn (ShootService $item) => $item->photographer_id !== null || $item->editor_id !== null
        )->count();
        $progressCount = $removedItems->filter(function (ShootService $item) {
            return ! in_array((string) $item->workflow_status, ['pending', 'scheduled'], true)
                || ! in_array((string) $item->delivery_status, ['', 'not_started'], true);
        })->count();

        $pricing = $this->support->buildPricingCalculationForExistingShoot(
            $targetServices,
            $shoot,
            $pricingState ?? $shoot->state,
            $taxRegion
        );
        $adjustments = (float) $this->invoiceAdjustments
            ->billableItemsForShoot($shoot)
            ->sum(fn ($item) => (float) $item->total_amount);
        $calculatedTotal = round((float) $pricing['total_quote'] + $adjustments, 2);
        $newTotal = $adjustedTotal !== null ? round($adjustedTotal, 2) : $calculatedTotal;
        $paid = $shoot->calculateCanonicalTotalPaid();

        return [
            'removed_services' => $removedItems->map(fn (ShootService $item) => [
                'shoot_service_id' => (int) $item->id,
                'service_id' => (int) $item->service_id,
                'name' => $item->service?->name ?? 'Service',
                'price' => round((float) ($item->price ?? 0), 2),
                'quantity' => max((int) ($item->quantity ?? 1), 1),
                'subtotal' => round((float) ($item->price ?? 0) * max((int) ($item->quantity ?? 1), 1), 2),
            ])->all(),
            'removedServices' => $removedItems->map(fn (ShootService $item) => [
                'shootServiceId' => (int) $item->id,
                'serviceId' => (int) $item->service_id,
                'name' => $item->service?->name ?? 'Service',
            ])->all(),
            'files_detached' => $files,
            'albums_detached' => $albums,
            'upload_attempts_detached' => $uploadAttempts,
            'assignments_removed' => $assignmentCount,
            'progress_rows_removed' => $progressCount,
            'payment_allocations_released' => round($allocated, 2),
            'leaves_no_services' => count($targetServices) === 0,
            'current_total' => round((float) ($shoot->total_quote ?? 0), 2),
            'new_total' => $newTotal,
            'total_paid' => round($paid, 2),
            'new_balance' => round(max($newTotal - $paid, 0), 2),
            'refund_credit_due' => round(max($paid - $newTotal, 0), 2),
        ];
    }

    private function confirmationToken(Shoot $shoot, User $actor, array $targetServices, array $impact): string
    {
        $target = collect($targetServices)
            ->map(function (array $service) {
                $service['id'] = (int) ($service['id'] ?? $service['service_id'] ?? 0);
                unset($service['service_id']);

                return $this->canonicalize($service);
            })
            ->sortBy('id')
            ->values()
            ->all();
        $current = $shoot->serviceItems
            ->map(function (ShootService $item) {
                $state = $item->getAttributes();
                ksort($state);

                return $state;
            })
            ->sortBy('id')
            ->values()
            ->all();
        $shootState = $shoot->getAttributes();
        ksort($shootState);
        $payload = json_encode([
            'actor_id' => (int) $actor->id,
            'shoot_id' => (int) $shoot->id,
            'shoot_state' => $shootState,
            'current' => $current,
            'target' => $target,
            'impact' => $impact,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return hash_hmac('sha256', (string) $payload, (string) config('app.key'));
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function normalizeRole(?string $role): string
    {
        return strtolower(trim((string) $role));
    }

    private function normalizeStatus(Shoot $shoot): string
    {
        $status = strtolower(trim((string) ($shoot->workflow_status ?: $shoot->status ?: '')));

        return match ($status) {
            'booked' => Shoot::STATUS_SCHEDULED,
            'completed' => Shoot::STATUS_UPLOADED,
            'canceled' => Shoot::STATUS_CANCELLED,
            default => $status,
        };
    }
}
