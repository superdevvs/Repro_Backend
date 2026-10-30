<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DialVoiceMobileOffer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $offerId)
    {
        $this->onConnection(config('voice_calls.realtime_connection'));
        $this->onQueue(config('voice_calls.realtime_queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [2, 5];
    }

    public function handle(\App\Services\Voice\VoiceMobileCallService $calls): void
    {
        $calls->dial($this->offerId);
    }
}
