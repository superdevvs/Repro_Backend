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
        $shoot = Shoot::with('services.category')->find($this->shootId);
        if (!$shoot) {
            return;
        }

        // Only auto-sync iGUIDE when the shoot booked a floorplan / iGuide
        // service. Otherwise this is just unnecessary external traffic.
        if (!$shoot->hasIguideEligibleService()) {
            Log::info('Auto iGUIDE sync skipped (no floorplan/iGuide service booked)', [
                'shoot_id' => $shoot->id,
            ]);
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

            $floorplans = is_array($iguideData['floorplans'] ?? null) ? $iguideData['floorplans'] : [];
            if (!empty($floorplans)) {
                IngestIguideAssetsJob::dispatch($shoot->id, $floorplans);
            }

            Log::info('Auto iGUIDE sync completed', [
                'shoot_id' => $shoot->id,
                'iguide_property_id' => $iguideData['property_id'] ?? null,
                'tour_url' => $iguideData['tour_url'] ?? null,
                'asset_count' => count($floorplans),
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
