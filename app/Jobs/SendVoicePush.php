<?php

namespace App\Jobs;

use App\Services\Voice\VoicePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendVoicePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public string $deliveryId)
    {
        $this->onConnection(config('voice_push.connection'));
        $this->onQueue(config('voice_push.queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [3, 8];
    }

    public function handle(VoicePushService $push): void
    {
        $push->deliver($this->deliveryId);
    }

    public function failed(?\Throwable $exception): void
    {
        \App\Models\VoicePushDelivery::whereKey($this->deliveryId)->whereIn('status', ['queued', 'retrying'])->update(['status' => 'failed', 'error_code' => 'push_attempts_exhausted']);
    }
}
