<?php

namespace App\Jobs;

use App\Services\Shoots\ShootNotificationDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCreatedShootSideEffectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $shootId,
        public readonly bool $treatAsClientRequest,
        public readonly bool $isImmediatelyScheduled,
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ShootNotificationDispatchService $dispatcher): void
    {
        try {
            $dispatcher->processCreatedShoot(
                $this->shootId,
                $this->treatAsClientRequest,
                $this->isImmediatelyScheduled
            );
        } catch (\Throwable $exception) {
            Log::warning('Queued created-shoot side effects job failed.', [
                'shoot_id' => $this->shootId,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
