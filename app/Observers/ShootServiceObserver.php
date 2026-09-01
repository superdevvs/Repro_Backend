<?php

namespace App\Observers;

use App\Models\ShootService;
use App\Services\CompensationEligibilityService;
use Illuminate\Validation\ValidationException;

class ShootServiceObserver
{
    public function saved(ShootService $serviceItem): void
    {
        if (! $serviceItem->wasRecentlyCreated
            && ! $serviceItem->wasChanged(['workflow_status', 'delivery_status', 'delivered_at'])) {
            return;
        }

        app(CompensationEligibilityService::class)->syncForService($serviceItem);
    }

    public function deleting(ShootService $serviceItem): void
    {
        $eligibility = app(CompensationEligibilityService::class);
        if ($eligibility->serviceHasLockedCompensation($serviceItem)) {
            throw ValidationException::withMessages([
                'services' => 'A delivered complimentary-reshoot service has locked compensation. Create an accounting adjustment instead of removing it.',
            ]);
        }

        $eligibility->voidPlannedForService($serviceItem);
    }
}
