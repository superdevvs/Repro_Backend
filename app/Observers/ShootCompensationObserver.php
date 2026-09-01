<?php

namespace App\Observers;

use App\Models\ShootCompensation;
use App\Services\CompensationEligibilityService;
use Illuminate\Validation\ValidationException;

class ShootCompensationObserver
{
    public function created(ShootCompensation $compensation): void
    {
        app(CompensationEligibilityService::class)->syncForCompensation($compensation);
    }

    public function deleting(ShootCompensation $compensation): bool
    {
        if ($compensation->locked_at || $compensation->earned_at || $compensation->invoiceItem()->exists()) {
            throw ValidationException::withMessages([
                'compensation' => 'Delivered compensation is locked. Create an accounting adjustment instead of deleting it.',
            ]);
        }

        $compensation->forceFill([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => 'removed_before_delivery',
            'updated_by' => auth()->id(),
        ])->saveQuietly();

        return false;
    }
}
