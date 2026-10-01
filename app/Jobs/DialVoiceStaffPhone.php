<?php

namespace App\Jobs;

use App\Services\Voice\VoiceIncomingOfferService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DialVoiceStaffPhone implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(public string $phoneOfferId)
    {
        $this->onConnection(config('voice_calls.realtime_connection'));
        $this->onQueue(config('voice_calls.realtime_queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [3];
    }

    public function handle(VoiceIncomingOfferService $offers): void
    {
        $offers->dialPhone($this->phoneOfferId);
    }
}
