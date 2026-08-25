<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Services\IguideService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Look for an iGUIDE belonging to a shoot's property and attach it.
 *
 * Four things can trigger this for the same shoot: booking, a lifecycle
 * transition, the half-hourly iguide:resync-pending command, and a manual
 * resync. ShouldBeUnique collapses those into a single in-flight job per
 * shoot so overlapping triggers cannot double-attach or double-ingest.
 */
class SyncShootIguideJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /** Release the uniqueness lock even if the worker dies mid-job. */
    public int $uniqueFor = 600;

    public function __construct(public int $shootId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->shootId;
    }

    /**
     * Spread retries out: a provider that has not published the iGuide yet
     * will not have published it a second later either.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
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
