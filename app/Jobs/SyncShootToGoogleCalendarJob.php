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

    /** Infrastructure failures (e.g. missing APP_KEY in a stale worker) should retry. */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public int $shootId
    ) {
    }

    public function handle(GoogleCalendarShootSyncService $syncService): void
    {
        $syncService->syncShoot($this->shootId);
    }
}
