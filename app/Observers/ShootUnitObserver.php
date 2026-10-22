<?php

namespace App\Observers;

use App\Models\ShootUnit;
use App\Services\Messaging\ShootDeliveryUpdateNotifier;

class ShootUnitObserver
{
    public function updated(ShootUnit $unit): void
    {
        app(ShootDeliveryUpdateNotifier::class)->unitChanged($unit);
    }
}
