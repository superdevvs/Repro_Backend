<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootFile, ShootService};
use App\Support\{EditingDispatchWriteLock, LockedWrite};
use Illuminate\Support\Facades\DB;

/** Provider identity is pinned to one booked execution, never guessed across units. */
final class IguideServiceOwnership
{
    public function resolve(Shoot $shoot): ?ShootService
    {
        if ($shoot->units()->exists()) return null;
        $items = $shoot->serviceItems()->with('service.category')->get()
            ->filter(fn ($item) => app(ShootUploadTargetPolicy::class)->isWorkItem($item));
        $ownerId = data_get($shoot->iguide_data, 'service_line_id');
        $sameIdentity = $shoot->iguide_property_id
            ? data_get($shoot->iguide_data, 'ownership_property_id') === $shoot->iguide_property_id
            : data_get($shoot->iguide_data, 'ownership_tour_url') === $shoot->iguide_tour_url;
        if ($ownerId && $sameIdentity) {
            return $items->firstWhere('id', (int) $ownerId);
        }
        // Legacy provider eligibility, used only until an unambiguous execution
        // has been pinned. Financial classification is never inferred from names.
        $candidates = $items->filter(fn ($item) => $this->isProviderLine($shoot, $item));
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    public function isProviderLine(Shoot $shoot, ShootService $item): bool
    {
        if (! app(ShootUploadTargetPolicy::class)->isWorkItem($item)) return false;
        $data = $item->shoot_unit_id ? ($item->unit?->provider_data ?? []) : ($shoot->iguide_data ?? []);
        $pinned = $item->shoot_unit_id ? ($data['iguide_service_line_id'] ?? null) : ($data['service_line_id'] ?? null);
        $same = $item->shoot_unit_id || ($shoot->iguide_property_id
            ? ($data['ownership_property_id'] ?? null) === $shoot->iguide_property_id
            : ($data['ownership_tour_url'] ?? null) === $shoot->iguide_tour_url);
        return ($pinned && (int) $pinned === (int) $item->id && $same)
            || (bool) preg_match('/i[ -]?guide/i', ($item->service?->name ?? '').' '.json_encode($item->service?->included_components ?? []));
    }

    public function adoptUnscoped(Shoot $shoot, int $lineId, array $keys): array
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($shoot, $lineId, $keys) {
            EditingDispatchWriteLock::acquire((int) $shoot->id);
            $shoot = $shoot->fresh();
            $owner = $this->resolve($shoot);
            if (! $owner || (int) $owner->id !== $lineId) return [];
            $data = $shoot->iguide_data ?? [];
            $data['service_line_id'] = $lineId;
            $data['ownership_property_id'] = $shoot->iguide_property_id;
            $data['ownership_tour_url'] = $shoot->iguide_tour_url;
            DB::table('shoots')->where('id', $shoot->id)->update(['iguide_data' => json_encode($data, JSON_THROW_ON_ERROR)]);
            $owned = $shoot->files()->where('shoot_service_id', $lineId)->where('metadata->source', 'iguide')->pluck('metadata')
                ->map(fn ($meta) => $meta['iguide_asset_key'] ?? null)->filter()->all();
            $ids = $shoot->files()->whereNull('shoot_service_id')->where('media_type', 'floorplan')->where('metadata->source', 'iguide')
                ->whereIn('metadata->iguide_asset_key', array_values(array_diff($keys, $owned)))
                ->when($owner->created_at, fn ($q) => $q->where('created_at', '>=', $owner->created_at))->pluck('id')->all();
            if ($ids) DB::table('shoot_files')->whereIn('id', $ids)->whereNull('shoot_service_id')->update(['shoot_service_id' => $lineId]);
            return $ids;
        }), 'iguide.service-ownership');
    }
}
