<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootFile, ShootService};
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;

/** A shoot-level provider order may own exactly one booked execution line. */
class CubiCasaServiceOwnership
{
    public function resolve(Shoot $shoot): ?ShootService
    {
        if ($shoot->units()->exists()) return null;
        $items = $shoot->serviceItems()->with('service.category')->where('is_deliverable', true)
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled'))
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'cancelled'))
            ->get()->filter(function (ShootService $item): bool {
                $name = $item->service?->name ?? '';
                if (preg_match('/iguide|matterport/i', $name)) return false;
                return Shoot::textMatchesCubicasaService($name)
                    || Shoot::textMatchesCubicasaService($item->service?->category?->name);
            });
        return $items->count() === 1 ? $items->first() : null;
    }

    /** Preserve existing bytes, IDs, scan state and timestamps when ownership is unambiguous. */
    public function adoptUnscoped(Shoot $shoot, int $lineId, array $assetKeys): array
    {
        if ($assetKeys === [] || ! ShootFile::where('shoot_id', $shoot->id)->whereNull('shoot_service_id')
            ->where('media_type', 'floorplan')->where('metadata->source', 'cubicasa')
            ->whereIn('metadata->cubicasa_asset_key', $assetKeys)->exists()) return [];
        return LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $lineId, $assetKeys): array {
            // Obtain SQLite's writer reservation before reading the ownership snapshot.
            DB::table('shoots')->where('id', $shoot->id)->update(['id' => DB::raw('id')]);
            $owner = $this->resolve($shoot);
            if (! $owner || (int) $owner->id !== $lineId) return [];
            $ownedKeys = ShootFile::where('shoot_id', $shoot->id)->where('shoot_service_id', $lineId)
                ->where('media_type', 'floorplan')->where('metadata->source', 'cubicasa')
                ->pluck('metadata')->map(fn ($meta) => $meta['cubicasa_asset_key'] ?? null)->filter()->all();
            $unownedKeys = array_values(array_diff($assetKeys, $ownedKeys));
            if ($unownedKeys === []) return [];
            $ids = ShootFile::where('shoot_id', $shoot->id)->whereNull('shoot_service_id')
                ->where('media_type', 'floorplan')->where('metadata->source', 'cubicasa')
                ->whereIn('metadata->cubicasa_asset_key', $unownedKeys)
                ->when($owner->created_at, fn ($q) => $q->where('created_at', '>=', $owner->created_at))
                ->pluck('id')->all();
            if ($ids) DB::table('shoot_files')->whereIn('id', $ids)->whereNull('shoot_service_id')->update(['shoot_service_id' => $lineId]);
            return $ids;
        }), 'cubicasa.service-ownership');
    }
}
