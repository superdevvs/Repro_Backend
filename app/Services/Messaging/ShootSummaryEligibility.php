<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;
use App\Models\Shoot;

/** The client summary is available only after full delivery and settlement. */
class ShootSummaryEligibility
{
    public function isSettled(Shoot $shoot): bool
    {
        if ($shoot->isComplimentaryReshoot()
            || ($shoot->total_quote !== null && $shoot->isNoChargeShoot())) {
            return true;
        }

        $shoot->loadMissing('payments.refunds');

        return (float) $shoot->total_quote > 0.01
            && $shoot->calculateCanonicalTotalPaid() >= (float) $shoot->total_quote - 0.01;
    }

    public function canSend(Shoot $shoot): bool
    {
        return ! $shoot->suppressesExternalNotifications()
            && (! AutomationRule::forTrigger('SHOOT_COMPLETED')->exists()
                || AutomationRule::active()->forTrigger('SHOOT_COMPLETED')->exists())
            && $shoot->workflow_status === Shoot::STATUS_DELIVERED
            && $shoot->delivery_status === 'delivered'
            && $shoot->client_id !== null
            && $this->isSettled($shoot);
    }
}
