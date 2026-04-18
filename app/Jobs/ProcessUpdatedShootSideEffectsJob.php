<?php

namespace App\Jobs;

use App\Services\Shoots\ShootNotificationDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessUpdatedShootSideEffectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $shootId,
        public readonly string $changesSummary,
        public readonly string $changesHtml,
        public readonly ?bool $notifyClient,
        public readonly ?bool $notifyPhotographer,
        public readonly ?int $originalPhotographerId,
        public readonly ?string $originalStatus,
        public readonly ?string $originalWorkflow,
        public readonly bool $photographerChanged,
        public readonly bool $photographerNewlyAssigned,
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ShootNotificationDispatchService $dispatcher): void
    {
        try {
            $dispatcher->processUpdatedShoot(
                $this->shootId,
                $this->changesSummary,
                $this->changesHtml,
                $this->notifyClient,
                $this->notifyPhotographer,
                $this->originalPhotographerId,
                $this->originalStatus,
                $this->originalWorkflow,
                $this->photographerChanged,
                $this->photographerNewlyAssigned
            );
        } catch (\Throwable $exception) {
            Log::warning('Queued updated-shoot side effects job failed.', [
                'shoot_id' => $this->shootId,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
