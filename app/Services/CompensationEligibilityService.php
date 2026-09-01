<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\ShootService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompensationEligibilityService
{
    public function syncForService(ShootService $serviceItem): void
    {
        if ($this->serviceIsCancelled($serviceItem)) {
            $this->voidPlannedForService($serviceItem, 'service_cancelled');

            return;
        }

        if (! $this->serviceIsDelivered($serviceItem)) {
            return;
        }

        $earnedAt = $serviceItem->delivered_at
            ?? $serviceItem->shoot?->admin_verified_at
            ?? $serviceItem->shoot?->completed_at
            ?? now();

        $this->lockAndEarn(
            ShootCompensation::query()
                ->where('shoot_service_id', $serviceItem->id)
                ->where('recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER),
            Carbon::parse($earnedAt),
        );
    }

    public function syncForShoot(Shoot $shoot): void
    {
        if (! $shoot->isComplimentaryReshoot()) {
            return;
        }

        if ($this->shootIsCancelled($shoot)) {
            $this->voidPlannedForShoot($shoot, 'shoot_'.strtolower((string) ($shoot->workflow_status ?: $shoot->status)));

            return;
        }

        $shoot->loadMissing('serviceItems');
        $shootDelivered = $this->shootIsDelivered($shoot);
        $shootEarnedAt = $shoot->admin_verified_at
            ?? $shoot->completed_at
            ?? ($shootDelivered ? $shoot->updated_at : null)
            ?? now();

        if ($shootDelivered) {
            $this->lockAndEarn(
                ShootCompensation::query()
                    ->where('shoot_id', $shoot->id)
                    ->where('recipient_type', ShootCompensation::RECIPIENT_SALES_REP)
                    ->where('scope_key', ShootCompensation::shootScopeKey()),
                Carbon::parse($shootEarnedAt),
            );
        }

        foreach ($shoot->serviceItems as $serviceItem) {
            if ($shootDelivered || $this->serviceIsDelivered($serviceItem)) {
                $this->syncForServiceAt(
                    $serviceItem,
                    $serviceItem->delivered_at
                        ? Carbon::parse($serviceItem->delivered_at)
                        : Carbon::parse($shootEarnedAt),
                );
            }
        }
    }

    public function syncForCompensation(ShootCompensation $compensation): void
    {
        if ($compensation->voided_at) {
            return;
        }

        $compensation->loadMissing(['shoot', 'serviceItem']);
        if (! $compensation->shoot?->isComplimentaryReshoot()) {
            return;
        }

        if ($compensation->recipient_type === ShootCompensation::RECIPIENT_SALES_REP) {
            if ($this->shootIsDelivered($compensation->shoot)) {
                $this->syncForShoot($compensation->shoot);
            }

            return;
        }

        if ($compensation->serviceItem
            && ($this->serviceIsDelivered($compensation->serviceItem)
                || $this->shootIsDelivered($compensation->shoot))) {
            $this->syncForShoot($compensation->shoot);
        }
    }

    public function voidPlannedForService(ShootService $serviceItem, string $reason = 'service_removed'): void
    {
        $this->voidPlanned(
            ShootCompensation::query()->where('shoot_service_id', $serviceItem->id),
            $reason,
        );
    }

    public function voidPlannedForShoot(Shoot $shoot, string $reason): void
    {
        $this->voidPlanned(
            ShootCompensation::query()->where('shoot_id', $shoot->id),
            $reason,
        );
    }

    public function serviceHasLockedCompensation(ShootService $serviceItem): bool
    {
        return ShootCompensation::query()
            ->where('shoot_service_id', $serviceItem->id)
            ->where(function ($query) {
                $query->whereNotNull('locked_at')
                    ->orWhereNotNull('earned_at')
                    ->orWhereHas('invoiceItem');
            })
            ->exists();
    }

    private function syncForServiceAt(ShootService $serviceItem, Carbon $earnedAt): void
    {
        $this->lockAndEarn(
            ShootCompensation::query()
                ->where('shoot_service_id', $serviceItem->id)
                ->where('recipient_type', ShootCompensation::RECIPIENT_PHOTOGRAPHER),
            $earnedAt,
        );
    }

    private function lockAndEarn($query, Carbon $earnedAt): void
    {
        DB::transaction(function () use ($query, $earnedAt): void {
            $compensations = $query
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->get();

            foreach ($compensations as $compensation) {
                $updates = [];
                if (! $compensation->locked_at) {
                    $updates['locked_at'] = $earnedAt;
                }

                $isPayable = $compensation->mode !== ShootCompensation::MODE_NONE
                    && (float) ($compensation->amount ?? 0) > 0
                    && $compensation->recipient_user_id !== null;

                if ($isPayable && ! $compensation->earned_at) {
                    $updates['earned_at'] = $earnedAt;
                }

                if (! $isPayable && $compensation->mode !== ShootCompensation::MODE_NONE) {
                    Log::warning('Complimentary reshoot compensation was locked but is not payout eligible.', [
                        'compensation_id' => $compensation->id,
                        'shoot_id' => $compensation->shoot_id,
                        'recipient_type' => $compensation->recipient_type,
                        'recipient_user_id' => $compensation->recipient_user_id,
                        'amount' => $compensation->amount,
                    ]);
                }

                if ($updates !== []) {
                    $compensation->forceFill($updates)->saveQuietly();
                }
            }
        });
    }

    private function voidPlanned($query, string $reason): void
    {
        DB::transaction(function () use ($query, $reason): void {
            $compensations = $query
                ->whereNull('voided_at')
                ->whereNull('locked_at')
                ->whereNull('earned_at')
                ->whereDoesntHave('invoiceItem')
                ->lockForUpdate()
                ->get();

            foreach ($compensations as $compensation) {
                $compensation->forceFill([
                    'voided_at' => now(),
                    'voided_by' => auth()->id(),
                    'void_reason' => $reason,
                    'updated_by' => auth()->id(),
                ])->saveQuietly();
            }
        });
    }

    private function serviceIsDelivered(ShootService $serviceItem): bool
    {
        return $serviceItem->workflow_status === ShootService::WORKFLOW_DELIVERED
            || $serviceItem->delivery_status === ShootService::DELIVERY_DELIVERED
            || $serviceItem->delivered_at !== null;
    }

    private function serviceIsCancelled(ShootService $serviceItem): bool
    {
        return $serviceItem->workflow_status === ShootService::WORKFLOW_CANCELLED
            || $serviceItem->delivery_status === ShootService::DELIVERY_CANCELLED
            || $serviceItem->cancelled_at !== null;
    }

    private function shootIsDelivered(Shoot $shoot): bool
    {
        return $shoot->admin_verified_at !== null
            || $shoot->completed_at !== null
            || in_array(strtolower((string) $shoot->workflow_status), [
                Shoot::STATUS_DELIVERED,
                'admin_verified',
                'completed',
            ], true)
            || in_array(strtolower((string) $shoot->status), [
                Shoot::STATUS_DELIVERED,
                'admin_verified',
                'completed',
            ], true);
    }

    private function shootIsCancelled(Shoot $shoot): bool
    {
        return in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)
            || in_array($shoot->status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true);
    }
}
