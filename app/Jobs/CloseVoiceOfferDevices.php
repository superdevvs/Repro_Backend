<?php

namespace App\Jobs;

use App\Models\VoiceIncomingOffer;
use App\Services\Voice\VoiceIncomingOfferService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CloseVoiceOfferDevices implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public string $offerId)
    {
        $this->onConnection(config('voice_calls.realtime_connection'));
        $this->onQueue(config('voice_calls.realtime_queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(VoiceIncomingOfferService $offers): void
    {
        $offer = VoiceIncomingOffer::find($this->offerId);
        if ($offer) {
            $offers->closeLosingPhones($offer);
        }
    }
}
