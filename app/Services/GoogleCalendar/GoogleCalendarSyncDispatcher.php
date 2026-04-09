<?php

namespace App\Services\GoogleCalendar;

use App\Jobs\RemoveShootFromGoogleCalendarJob;
use App\Jobs\ResyncGoogleCalendarForUserJob;
use App\Jobs\SyncShootToGoogleCalendarJob;

class GoogleCalendarSyncDispatcher
{
    public function dispatchShootSync(int $shootId): void
    {
        SyncShootToGoogleCalendarJob::dispatch($shootId)->afterCommit();
    }

    public function dispatchShootRemoval(int $shootId): void
    {
        RemoveShootFromGoogleCalendarJob::dispatch($shootId)->afterCommit();
    }

    public function dispatchUserResync(int $userId): void
    {
        ResyncGoogleCalendarForUserJob::dispatch($userId)->afterCommit();
    }
}
