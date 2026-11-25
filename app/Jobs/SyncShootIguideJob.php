<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Services\IguideService;
use App\Services\Shoots\IguideServiceOwnership;
use App\Services\Shoots\ProviderFloorplanRecovery;
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

    public ?int $shootServiceId = null;
    public bool $scheduledRecovery = false;

    public function __construct(public int $shootId, ?int $shootServiceId = null, bool $scheduledRecovery = false)
    {
        $this->shootServiceId = $shootServiceId;
        $this->scheduledRecovery = $scheduledRecovery;
    }

    public function uniqueId(): string
    {
        return $this->shootServiceId ? "{$this->shootId}:{$this->shootServiceId}" : (string) $this->shootId;
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
        if (!$shoot || $shoot->isInternalTestShoot()) {
            return;
        }
        if ($this->shootServiceId) {
            $line = $shoot->serviceItems()->with('service', 'unit')->find($this->shootServiceId);
            if (!$line?->unit || !app(\App\Services\Shoots\IguideServiceOwnership::class)->isProviderLine($shoot, $line)) return;
            $shoot = app(\App\Services\Shoots\ShootUnitTourScope::class)->project($shoot, $line->unit, $line);
        } elseif ($shoot->units()->exists()) {
            return;
        }

        // Only auto-sync iGUIDE when the shoot booked a floorplan / iGuide
        // service. Otherwise this is just unnecessary external traffic.
        if (!$this->shootServiceId && !$shoot->hasIguideEligibleService()) {
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
                if ($this->scheduledRecovery) {
                    // Recovery counts unscoped files as present, so first move legacy unscoped
                    // iGUIDE files onto the booked iGUIDE line, as the full-manifest ingest did.
                    $this->adoptUnscopedFloorplans($shoot, $floorplans);
                    // The sweep may have already queued these assets before metadata was refreshed.
                    app(ProviderFloorplanRecovery::class)->recover('iguide', 1, $shoot->id);
                } else {
                    IngestIguideAssetsJob::dispatch($shoot->id, $floorplans, $this->shootServiceId);
                }
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

    /**
     * IngestIguideAssetsJob adopts unscoped files for a whole-shoot iGUIDE line. Scheduled
     * recovery queues only missing assets, so a shoot whose files are all present but still
     * unscoped would never reach that step.
     */
    private function adoptUnscopedFloorplans(Shoot $shoot, array $floorplans): void
    {
        if ($this->shootServiceId) {
            return;
        }
        $keys = array_values(array_filter(array_column($floorplans, 'asset_key'),
            static fn ($key) => is_string($key) && $key !== ''));
        $ownership = app(IguideServiceOwnership::class);
        $owner = $keys === [] ? null : $ownership->resolve($shoot->fresh());
        if ($owner) {
            $ownership->adoptUnscoped($shoot, (int) $owner->id, $keys);
        }
    }
}
