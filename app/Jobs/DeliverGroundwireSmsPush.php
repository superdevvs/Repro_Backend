<?php

namespace App\Jobs;

use App\Services\Messaging\GroundwireSmsPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverGroundwireSmsPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public int $pushId) {}

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(GroundwireSmsPushService $service): void
    {
        $service->deliver($this->pushId);
    }
}
