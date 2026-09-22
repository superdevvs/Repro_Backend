<?php

namespace App\Services\GoogleCalendar;

use App\Jobs\RemoveShootFromGoogleCalendarJob;
use App\Jobs\ResyncGoogleCalendarForUserJob;
use App\Jobs\SyncShootToGoogleCalendarJob;
use App\Models\Shoot;

class GoogleCalendarSyncDispatcher
{
    public function dispatchShootSync(int $shootId): void
    {
        if (Shoot::find($shootId)?->isInternalTestShoot()) {
            return;
        }

        SyncShootToGoogleCalendarJob::dispatch($shootId)->afterCommit();
    }

    public function dispatchShootRemoval(int $shootId): void
    {
        if (Shoot::find($shootId)?->isInternalTestShoot()) {
            return;
        }

        RemoveShootFromGoogleCalendarJob::dispatch($shootId)->afterCommit();
    }

    public function dispatchUserResync(int $userId): void
    {
        ResyncGoogleCalendarForUserJob::dispatch($userId)->afterCommit();
    }
}
