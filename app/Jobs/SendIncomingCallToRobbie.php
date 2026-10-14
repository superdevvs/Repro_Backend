<?php

namespace App\Jobs;

use App\Models\VoiceIncomingOffer;
use App\Services\Voice\ManualVoiceHandoffService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendIncomingCallToRobbie implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;
    public int $timeout = 40;

    public function __construct(public string $offerId)
    {
        $this->onConnection('database')->onQueue('voice-realtime')->afterCommit();
    }

    public function backoff(): array { return [1, 2, 3, 5, 10, 15, 20]; }

    public function handle(ManualVoiceHandoffService $handoff): void
    {
        if ($offer = VoiceIncomingOffer::find($this->offerId)) {
            $handoff->resume($offer);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($offer = VoiceIncomingOffer::find($this->offerId)) {
            app(ManualVoiceHandoffService::class)->failed($offer, $exception);
        }
    }
}
