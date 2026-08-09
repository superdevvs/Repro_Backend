<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "what order does this shoot's media go out in".
 *
 * Finalizing a shoot fans out into independent async jobs — the ZIP archive
 * builder, the Dropbox local-cache jobs, the Bright MLS publish and the client
 * delivery email. Each of them re-reads the database at whatever moment its
 * worker picks it up, so reading live `sort_order` would let a reorder that
 * lands mid-finalize produce a ZIP in one order and an MLS manifest in another.
 *
 * So finalize takes a snapshot of the ordered file ids and every delivery
 * consumer replays that snapshot instead of re-deriving the order. Files added
 * after the snapshot are not dropped — they append in live delivery order — so a
 * late upload is still delivered, just after the snapshotted block.
 *
 * `media_order_version` is bumped on every saved reorder, and the archive cache
 * signature includes it, so a reorder always invalidates a previously built ZIP
 * while repeated builds at the same version stay byte-for-byte idempotent.
 */
class DeliveryMediaOrderService
{
    /**
     * Record that the manual arrangement changed.
     *
     * Uses an atomic SQL increment rather than read-modify-write so two
     * concurrent reorders cannot land on the same version number.
     */
    public function bumpVersion(Shoot $shoot): int
    {
        DB::table('shoots')->where('id', $shoot->id)->update([
            'media_order_version' => DB::raw('COALESCE(media_order_version, 0) + 1'),
        ]);

        $version = (int) DB::table('shoots')->where('id', $shoot->id)->value('media_order_version');
        $shoot->setAttribute('media_order_version', $version);
        $shoot->syncOriginalAttribute('media_order_version');

        return $version;
    }

    public function currentVersion(Shoot $shoot): int
    {
        return (int) ($shoot->media_order_version ?? 0);
    }

    /**
     * Ordered shoot_file ids for the delivered set, straight from the database.
     *
     * @return array<int, int>
     */
    public function resolveOrderedIds(Shoot $shoot, ?int $shootServiceId = null): array
    {
        return ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->when($shootServiceId !== null, fn ($query) => $query->where('shoot_service_id', $shootServiceId))
            ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED])
            ->inDeliveryOrder()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Freeze the current delivery order onto the shoot.
     *
     * Called from inside the finalize transaction (which holds a row lock on the
     * shoot) so the snapshot cannot straddle a concurrent reorder: the reorder
     * either commits first and is captured, or waits and bumps the version
     * afterwards — which refreshSnapshotIfPresent() then folds in.
     *
     * @return array<int, int>
     */
    public function snapshot(Shoot $shoot, ?int $shootServiceId = null): array
    {
        $orderedIds = $this->resolveOrderedIds($shoot, $shootServiceId);

        $shoot->forceFill([
            'delivery_media_order' => $orderedIds,
            'delivery_media_order_version' => $this->currentVersion($shoot),
            'delivery_media_order_at' => now(),
        ])->save();

        return $orderedIds;
    }

    /**
     * Re-freeze an existing snapshot after a reorder.
     *
     * A shoot that has not been delivered yet has no snapshot and needs none —
     * the delivery jobs have not run, so live ordering is still correct. But once
     * a snapshot exists it is what every consumer reads, so a later reorder has
     * to be folded into it or the admin's change would silently never reach the
     * client.
     */
    public function refreshSnapshotIfPresent(Shoot $shoot): void
    {
        if (!is_array($shoot->delivery_media_order)) {
            return;
        }

        $this->snapshot($shoot);
    }

    /**
     * @return array<int, int>|null null when the shoot has no snapshot yet
     */
    public function snapshotIds(Shoot $shoot): ?array
    {
        $snapshot = $shoot->delivery_media_order;
        if (!is_array($snapshot)) {
            return null;
        }

        $ids = [];
        foreach ($snapshot as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Reorder an already-filtered collection to match the shoot's snapshot.
     *
     * Deliberately does not add or remove anything — access control, virus-scan
     * gating and extras filtering happen upstream, and this must not resurrect a
     * file those rules excluded. Snapshotted files keep their frozen positions;
     * anything not in the snapshot (uploaded after finalize) appends in live
     * delivery order so it is still delivered, just last.
     *
     * @param  Collection<int, ShootFile>  $files
     * @return Collection<int, ShootFile>
     */
    public function applyTo(Shoot $shoot, Collection $files): Collection
    {
        $snapshotIds = $this->snapshotIds($shoot);
        if ($snapshotIds === null || $snapshotIds === []) {
            return ShootFile::sortCollectionInDeliveryOrder($files);
        }

        $positions = [];
        foreach ($snapshotIds as $index => $id) {
            // First occurrence wins so a malformed snapshot with duplicates is
            // still deterministic.
            $positions[$id] ??= $index;
        }

        $snapshotted = [];
        $appended = [];
        foreach ($files as $file) {
            if (array_key_exists((int) $file->id, $positions)) {
                $snapshotted[] = $file;
                continue;
            }

            $appended[] = $file;
        }

        $ordered = collect($snapshotted)
            ->sortBy(fn (ShootFile $file) => [$positions[(int) $file->id], (int) $file->id], SORT_REGULAR)
            ->values();

        return $ordered
            ->concat(ShootFile::sortCollectionInDeliveryOrder(collect($appended)))
            ->values();
    }

    /**
     * Cache-busting token for generated artifacts (currently the ZIP archive
     * signature). Changes whenever the arrangement changes, and only then, so
     * regenerating at a stable order is idempotent.
     */
    public function orderFingerprint(Shoot $shoot): string
    {
        $snapshotIds = $this->snapshotIds($shoot);

        return implode(':', [
            'v' . $this->currentVersion($shoot),
            's' . ($shoot->delivery_media_order_version ?? 'none'),
            $snapshotIds === null ? 'live' : substr(sha1(implode(',', $snapshotIds)), 0, 16),
        ]);
    }
}
