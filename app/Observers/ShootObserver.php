<?php

namespace App\Observers;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Shoot;

class ShootObserver
{
    public function updated(Shoot $shoot): void
    {
        if (!$shoot->wasChanged('workflow_status') && !$shoot->wasChanged('status')) {
            return;
        }

        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));

        if ($status === Shoot::STATUS_EDITING) {
            GenerateShootMediaArchiveJob::dispatch($shoot->id, 'raw', 'small');
            return;
        }

        if ($status === Shoot::STATUS_READY || $status === Shoot::STATUS_DELIVERED) {
            GenerateShootMediaArchiveJob::dispatch($shoot->id, 'edited', 'small');
        }
    }
}
