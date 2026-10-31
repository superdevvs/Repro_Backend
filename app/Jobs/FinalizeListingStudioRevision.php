<?php

namespace App\Jobs;

use App\Services\ListingStudio\ListingStudioRevisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeListingStudioRevision implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public array $backoff = [15, 60];

    public function __construct(public string $deliveryId)
    {
        $this->onConnection(config('services.fal.workspace_queue_connection', 'studio'));
        $this->onQueue('studio');
    }

    public function handle(ListingStudioRevisionService $service): void
    {
        $service->finalize($this->deliveryId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(ListingStudioRevisionService::class)->holdDelivery($this->deliveryId);
    }
}
