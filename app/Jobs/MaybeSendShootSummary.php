<?php

namespace App\Jobs;

use App\Services\Messaging\ShootSummaryNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Recheck both delivery and payment after the originating transaction commits. */
class MaybeSendShootSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public int $timeout = 120;

    public function __construct(public int $shootId)
    {
        $this->onQueue('default');
    }

    public function handle(ShootSummaryNotificationService $summaries): void
    {
        $summaries->sendIfEligible($this->shootId);
    }
}
