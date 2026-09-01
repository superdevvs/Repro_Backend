<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootCompensation;
use App\Models\ShootService;
use App\Models\User;

class ShootCompensationCalculator
{
    public const DEFAULT_SALES_REP_RATE = 15.0;

    public function nominalUnitPrice(Shoot $sourceShoot, Service $service, ?ShootService $sourceItem = null): float
    {
        if ($sourceItem && (int) $sourceItem->service_id === (int) $service->id) {
            // A complimentary line's client price is deliberately zero. Its
            // immutable nominal snapshot is the accounting source for any
            // later chained reshoot, even if the live service catalog changes.
            if ($sourceItem->nominal_value_snapshot !== null) {
                $sourceQuantity = max((int) ($sourceItem->quantity ?? 1), 1);

                return round((float) $sourceItem->nominal_value_snapshot / $sourceQuantity, 2);
            }

            if ($sourceShoot->isComplimentaryReshoot()) {
                $auditItem = $sourceItem->relationLoaded('compReshootItem')
                    ? $sourceItem->compReshootItem
                    : $sourceItem->compReshootItem()->first();

                if ($auditItem?->nominal_unit_price_snapshot !== null) {
                    return round((float) $auditItem->nominal_unit_price_snapshot, 2);
                }
            }

            if ($sourceItem->price !== null) {
                return round((float) $sourceItem->price, 2);
            }
        }

        return round((float) $service->getPriceForSqft($this->extractSqft($sourceShoot)), 2);
    }

    public function photographerStandard(
        Shoot $sourceShoot,
        Service $service,
        int $quantity,
        ?ShootService $sourceItem = null
    ): array {
        $unitPay = $service->getPhotographerPayForSqft($this->extractSqft($sourceShoot));
        if ($unitPay === null && $sourceItem?->photographer_pay !== null) {
            $unitPay = (float) $sourceItem->photographer_pay;
        }

        $unitPay = round((float) ($unitPay ?? 0), 2);
        $isPercentage = ($service->photographer_pay_type ?? Service::PAY_TYPE_FIXED) === Service::PAY_TYPE_PERCENT;

        return [
            'calculation_method' => $isPercentage
                ? ShootCompensation::CALCULATION_PERCENTAGE
                : ShootCompensation::CALCULATION_FIXED,
            'rate_snapshot' => $isPercentage
                ? round((float) ($service->photographer_pay_percent ?? 0), 4)
                : $unitPay,
            'amount' => round($unitPay * max($quantity, 1), 2),
        ];
    }

    public function salesRepStandard(float $basisAmount, ?User $salesRep): array
    {
        $configuredRate = data_get($salesRep?->metadata, 'repDetails.commissionPercentage');
        $rate = is_numeric($configuredRate) && (float) $configuredRate > 0
            ? round((float) $configuredRate, 4)
            : self::DEFAULT_SALES_REP_RATE;

        return [
            'calculation_method' => ShootCompensation::CALCULATION_PERCENTAGE,
            'rate_snapshot' => $rate,
            'amount' => round(max($basisAmount, 0) * ($rate / 100), 2),
        ];
    }

    public function resolveAmount(
        string $mode,
        array $standard,
        ?float $customAmount = null
    ): array {
        return match ($mode) {
            ShootCompensation::MODE_NONE => [
                'calculation_method' => null,
                'rate_snapshot' => null,
                'amount' => 0.0,
            ],
            ShootCompensation::MODE_CUSTOM => [
                'calculation_method' => ShootCompensation::CALCULATION_FIXED,
                'rate_snapshot' => null,
                'amount' => round((float) $customAmount, 2),
            ],
            default => $standard,
        };
    }

    private function extractSqft(Shoot $shoot): ?int
    {
        $details = is_array($shoot->property_details) ? $shoot->property_details : [];
        $value = $details['sqft'] ?? $details['squareFeet'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
