<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncCubiCasaShootJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $shootId,
        public readonly string $jobReference
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->shootId;
    }

    public function handle(CubiCasaService $cubicasa): void
    {
        $shoot = Shoot::query()
            ->with('services.category')
            ->find($this->shootId);

        if (!$shoot) {
            return;
        }

        try {
            $cubicasa->markSyncRunning($shoot, $this->jobReference);
            $parsed = $cubicasa->syncShoot($shoot);

            if (!$parsed) {
                return;
            }

            $floorplans = is_array($parsed['floorplans'] ?? null) ? $parsed['floorplans'] : [];
            if (!empty($floorplans) && $shoot->fresh()?->hasCubiCasaEligibleService()) {
                IngestCubiCasaAssetsJob::dispatch($shoot->id, $floorplans);
            }
        } catch (\Throwable $exception) {
            $cubicasa->markSyncFailed($shoot, CubiCasaService::SYNC_STATUS_FAILED, $exception->getMessage());

            Log::error('Queued CubiCasa sync failed', [
                'shoot_id' => $this->shootId,
                'job_reference' => $this->jobReference,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
