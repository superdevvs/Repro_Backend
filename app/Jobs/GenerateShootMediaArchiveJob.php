<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Services\Shoots\ShootMediaArchiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateShootMediaArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $shootId,
        public string $type,
        public string $size,
        public ?int $shootServiceId = null
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(ShootMediaArchiveService $shootMediaArchiveService): void
    {
        $shoot = Shoot::find($this->shootId);
        if (!$shoot) {
            Log::warning('Shoot media archive job skipped because shoot was not found', [
                'shoot_id' => $this->shootId,
                'shoot_service_id' => $this->shootServiceId,
                'type' => $this->type,
                'size' => $this->size,
            ]);

            return;
        }

        $shootMediaArchiveService->generateArchive($shoot, $this->type, $this->size, true, $this->shootServiceId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Shoot media archive job failed permanently', [
            'shoot_id' => $this->shootId,
            'shoot_service_id' => $this->shootServiceId,
            'type' => $this->type,
            'size' => $this->size,
            'error' => $exception->getMessage(),
        ]);
    }
}
