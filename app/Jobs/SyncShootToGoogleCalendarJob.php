<?php

namespace App\Jobs;

use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncShootToGoogleCalendarJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $shootId
    ) {
    }

    public function handle(GoogleCalendarShootSyncService $syncService): void
    {
        $syncService->syncShoot($this->shootId);
    }
}
