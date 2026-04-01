<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Services\IguideService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncShootIguideJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $shootId)
    {
    }

    public function handle(IguideService $iguideService): void
    {
        $shoot = Shoot::find($this->shootId);
        if (!$shoot) {
            return;
        }

        try {
            $iguideData = $iguideService->syncShoot($shoot);

            if (!$iguideData) {
                Log::info('Auto iGUIDE sync did not find a matching iGUIDE', [
                    'shoot_id' => $shoot->id,
                ]);
                return;
            }

            Log::info('Auto iGUIDE sync completed', [
                'shoot_id' => $shoot->id,
                'iguide_property_id' => $iguideData['property_id'] ?? null,
                'tour_url' => $iguideData['tour_url'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Auto iGUIDE sync failed', [
                'shoot_id' => $this->shootId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
