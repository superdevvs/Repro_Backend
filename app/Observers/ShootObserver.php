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

        // Only build a media archive when the shoot actually has the relevant
        // files. A no-media (fast-forward) delivery has nothing to archive, so
        // dispatching the job would just fail with "No downloadable files
        // available".
        $rawCount = (int) ($shoot->raw_photo_count ?? 0);
        $editedCount = (int) ($shoot->edited_photo_count ?? 0);

        if ($status === Shoot::STATUS_EDITING) {
            if ($rawCount > 0) {
                GenerateShootMediaArchiveJob::dispatch($shoot->id, 'raw', 'small');
            }
            return;
        }

        if ($status === Shoot::STATUS_READY || $status === Shoot::STATUS_DELIVERED) {
            if ($editedCount > 0) {
                GenerateShootMediaArchiveJob::dispatch($shoot->id, 'edited', 'small');
            }
        }
    }
}
