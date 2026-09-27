<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\ShootUnit;
use App\Support\LockedWrite;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** A read projection for existing tour renderers; provider writes persist to the unit. */
class ShootUnitTourScope
{
    public const INHERITED_SETTINGS = ['tour_style', 'tour_palette', 'header_position', 'tour_version', 'realtor_info', 'realtor_client_id', 'autoplay', 'show_garage'];

    public function requestedUnit(Shoot $shoot, mixed $id): ShootUnit
    {
        abort_unless(is_scalar($id) && preg_match('/^[1-9]\d*$/', (string) $id), 404);
        return $shoot->units()->findOrFail($id);
    }

    public function project(Shoot $shoot, ShootUnit $unit, ?ShootService $line = null): Shoot
    {
        abort_unless((string) $unit->shoot_id === (string) $shoot->id, 404);
        if ($line) {
            abort_unless((string) $line->shoot_id === (string) $shoot->id && (string) $line->shoot_unit_id === (string) $unit->id, 422, 'The service line does not belong to this unit.');
        }
        $view = clone $shoot;
        $links = array_replace(Arr::only($shoot->tour_links ?? [], self::INHERITED_SETTINGS), $unit->tour_links ?? []);
        $details = array_replace($unit->property_details ?? [], ['beds' => $unit->beds, 'bedrooms' => $unit->beds, 'baths' => $unit->baths, 'bathrooms' => $unit->baths, 'sqft' => $unit->sqft, 'apt_suite' => $unit->label]);
        // No shoot-level provider identifiers, descriptions, listing facts or URLs may bleed into a unit.
        foreach (array_keys($view->getAttributes()) as $key) {
            if (str_starts_with($key, 'iguide_') || str_starts_with($key, 'cubicasa_')) {
                $view->setAttribute($key, null);
            }
        }
        $providerData = $unit->provider_data ?? [];
        if ($line) {
            $lineData = data_get($providerData, 'lines.'.$line->id, []);
            foreach (['iguide', 'cubicasa'] as $provider) {
                if (isset($providerData[$provider.'_service_line_id']) && (int) $providerData[$provider.'_service_line_id'] !== (int) $line->id) {
                    foreach (array_keys($providerData) as $key) if (str_starts_with($key, $provider.'_')) unset($providerData[$key]);
                }
            }
            $providerData = array_replace($providerData, $lineData);
        }
        foreach ($providerData as $key => $value) {
            if (str_starts_with($key, 'iguide_') || str_starts_with($key, 'cubicasa_')) {
                $view->setAttribute($key, $value);
            }
        }
        $view->forceFill([
            'tour_links' => $links, 'property_details' => $details,
            'bedrooms' => $unit->beds, 'bathrooms' => $unit->baths, 'sqft' => $unit->sqft,
            'mls_id' => $links['property_mls'] ?? null,
            'property_status' => $unit->property_status ?? 'available',
            'listing_type' => $unit->listing_type ?? $shoot->listing_type,
        ]);
        $lineIds = $this->fileLineIds($shoot, $unit);
        $view->setRelation('files', $shoot->files()->whereIn('shoot_service_id', $lineIds)->get());
        $items = $unit->serviceItems()->with('service')->get();
        $view->setRelation('serviceItems', $line ? $items->where('id', $line->id)->values() : $items);
        $view->setRelation('services', ($line ? $items->where('id', $line->id) : $items)->pluck('service')->filter()->values());
        $view->setRelation('tourUnit', $unit);
        $view->setRelation('tourServiceLine', $line);
        $view->syncOriginal();

        return $view;
    }

    public function refreshProjection(Shoot $shoot, bool $lock = false): Shoot
    {
        $fresh = Shoot::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($shoot->id);
        return $shoot->relationLoaded('tourUnit')
            ? $this->project($fresh, $shoot->getRelation('tourUnit')->fresh(), $shoot->getRelation('tourServiceLine'))
            : $fresh;
    }

    public function forLine(Shoot $shoot, ?int $lineId): Shoot
    {
        if (! $lineId) return $shoot;
        $line = $shoot->serviceItems()->with('unit')->findOrFail($lineId);
        return $line->unit ? $this->project($shoot, $line->unit, $line) : $shoot;
    }

    public function forUpload(Shoot $shoot, string $uploadId): Shoot
    {
        $session = \App\Models\IguideOfflineUploadSession::find($uploadId);
        $lineId = $session?->shoot_service_id;
        if (! $lineId) {
            $file = $shoot->files()->where('metadata->upload_id', $uploadId)->first();
            $lineId = $file?->shoot_service_id;
        }
        if (! $lineId) {
            $unit = $shoot->units()->where('provider_data->iguide_data->manual_offline_package->upload_id', $uploadId)->first();
            if ($unit) return $this->project($shoot, $unit, $this->resolveLine($unit, data_get($unit->provider_data, 'iguide_service_line_id'), 'iguide'));
        }
        return $this->forLine($shoot, $lineId);
    }

    public function fileLineIds(Shoot $shoot, ShootUnit $unit): array
    {
        if ($shoot->relationLoaded('releasedUnitLineIds')) return $shoot->getRelation('releasedUnitLineIds');
        $ids = [$unit->id];
        if ($unit->include_common_area_media && $unit->kind !== 'common_area') {
            $ids = array_merge($ids, $shoot->units()->where('kind', 'common_area')->pluck('id')->all());
        }
        return $shoot->serviceItems()->whereIn('shoot_unit_id', $ids)->pluck('id')->all();
    }

    public function projectPublic(Shoot $shoot, ShootUnit $unit): Shoot
    {
        $view = $this->project($shoot, $unit);
        $own = $unit->serviceItems()->where('is_deliverable', true)->whereNotIn('delivery_status', ['cancelled'])->get();
        $released = $own->filter(fn ($line) => $this->isReleased($line));
        abort_if($released->isEmpty(), 404, 'This unit’s tour is not available yet.');
        $ids = $shoot->serviceItems()->whereIn('id', $this->fileLineIds($shoot, $unit))->get()->filter(fn ($line) => $this->isReleased($line))->pluck('id')->all();
        $view->setRelation('releasedUnitLineIds', $ids);
        $view->setRelation('files', $view->files->whereIn('shoot_service_id', $ids)->whereIn('workflow_stage', [\App\Models\ShootFile::STAGE_COMPLETED, \App\Models\ShootFile::STAGE_VERIFIED])->filter(fn ($file) => ! $file->is_hidden && ! $file->isBlockedFromDelivery())->values());
        $safe = $this->releasedTourData($unit, $released->pluck('id')->all(), $own->count());
        foreach (array_keys($view->getAttributes()) as $key) {
            if (str_starts_with($key, 'iguide_') || str_starts_with($key, 'cubicasa_')) $view->setAttribute($key, $safe['provider_data'][$key] ?? null);
        }
        $view->tour_links = array_replace(Arr::only($shoot->tour_links ?? [], self::INHERITED_SETTINGS), $safe['tour_links']);
        return $view;
    }

    /** Shared by anonymous tours and authenticated client payloads; presentation and facts remain editable. */
    public function releasedTourData(ShootUnit $unit, array $releasedIds, int $totalLines): array
    {
        $releasedIds = array_map('intval', $releasedIds);
        $links = $unit->tour_links ?? [];
        $providerData = $unit->provider_data ?? [];
        foreach (['iguide', 'cubicasa'] as $provider) {
            $lineId = $providerData[$provider.'_service_line_id'] ?? null;
            if (! $releasedIds || ($lineId && ! in_array((int) $lineId, $releasedIds, true)) || (! $lineId && count($releasedIds) !== $totalLines)) {
                foreach (array_keys($providerData) as $key) if (str_starts_with($key, $provider.'_')) unset($providerData[$key]);
                foreach (array_keys($links) as $key) if (str_starts_with(strtolower($key), $provider)) unset($links[$key]);
            }
        }
        // Manual unit-wide embeds have no individual release identity. Publish only once all lines are released.
        if (! $releasedIds || count($releasedIds) !== $totalLines) {
            foreach (array_keys($links) as $key) if (preg_match('/^(matterport|zillow|video|embeds|featured_embed|branded$|mls$|generic_?mls$)/i', $key)) unset($links[$key]);
        }
        $providerData['lines'] = array_filter($providerData['lines'] ?? [], fn ($id) => in_array((int) $id, $releasedIds, true), ARRAY_FILTER_USE_KEY);
        if (! $releasedIds) $links = Arr::only($links, array_merge(self::INHERITED_SETTINGS, ['property_mls', 'property_price', 'property_lot_size', 'property_description']));
        return ['tour_links' => $links, 'provider_data' => $providerData];
    }

    public function isReleased(ShootService $line): bool
    {
        return $line->is_deliverable && in_array($line->delivery_status, [ShootService::DELIVERY_READY, ShootService::DELIVERY_DELIVERED], true) && $line->is_unlocked_for_delivery;
    }

    public function persistProvider(Shoot $view): void
    {
        if (! $view->relationLoaded('tourUnit')) {
            $view->save();
            return;
        }
        $unit = $view->getRelation('tourUnit');
        $dirty = $view->getDirty();
        $provider = [];
        foreach (array_keys($dirty) as $key) {
            if (str_starts_with($key, 'iguide_') || str_starts_with($key, 'cubicasa_')) {
                $value = $view->getAttribute($key);
                $provider[$key] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
            }
        }
        $links = [];
        $originalLinks = $view->getOriginal('tour_links') ?? [];
        foreach ($view->tour_links ?? [] as $key => $value) {
            if (! array_key_exists($key, $originalLinks) || $originalLinks[$key] !== $value) {
                $links[$key] = $value;
            }
        }
        $lineId = $view->relationLoaded('tourServiceLine') ? $view->getRelation('tourServiceLine')?->id : null;
        $write = function () use ($unit, $provider, $links, $lineId) {
            $unit->refresh();
            $data = array_replace($unit->provider_data ?? [], $provider);
            if ($lineId) {
                $data['lines'][$lineId] = array_replace($data['lines'][$lineId] ?? [], $provider);
                foreach (['iguide', 'cubicasa'] as $prefix) {
                    if (collect(array_keys($provider))->contains(fn ($key) => str_starts_with($key, $prefix.'_'))) $data[$prefix.'_service_line_id'] = $lineId;
                }
            }
            $unit->provider_data = $data;
            $unit->tour_links = array_replace($unit->tour_links ?? [], $links);
            $unit->save();
        };
        if (DB::transactionLevel() > 0) $write();
        else LockedWrite::run(fn () => DB::transaction($write), 'shoot-unit.provider');
        $view->syncOriginal();
    }

    public function matchProvider(string $provider, array $identifiers): ?Shoot
    {
        foreach ($identifiers as $key => $value) {
            if ($provider === 'cubicasa' && is_string($value) && preg_match('/^shoot-(\d+)-unit-(\d+)-line-(\d+)$/', $value, $match)) {
                $unit = ShootUnit::where('shoot_id', $match[1])->find($match[2]);
                if ($unit) return $this->project($unit->shoot, $unit, $this->resolveLine($unit, $match[3], $provider));
            }
            if (! is_string($value) || trim($value) === '') continue;
            $matches = ShootUnit::where('provider_data->'.$key, trim($value))->get();
            if ($matches->isEmpty()) {
                $matches = ShootUnit::whereNotNull('provider_data')->get()->filter(fn ($unit) => collect(data_get($unit->provider_data, 'lines', []))->contains(fn ($data) => ($data[$key] ?? null) === trim($value)));
            }
            if ($matches->count() > 1) abort(409, 'Provider identifier matches several units.');
            if ($matches->count() === 1) {
                $unit = $matches->first();
                $lineId = data_get($unit->provider_data, $provider.'_service_line_id');
                foreach (data_get($unit->provider_data, 'lines', []) as $id => $data) if (($data[$key] ?? null) === trim($value)) $lineId = $id;
                $line = $this->resolveLine($unit, $lineId, $provider);
                return $this->project($unit->shoot, $unit, $line);
            }
        }
        return null;
    }

    public function resolveLine(ShootUnit $unit, mixed $id, string $provider): ShootService
    {
        $lines = $unit->serviceItems()->with('service')->get()->filter(function ($line) use ($provider) {
            $name = strtolower((string) ($line->service?->name ?? ''));
            return str_contains($name, 'floor') || ($provider === 'iguide' && (str_contains($name, 'iguide') || str_contains($name, 'i-guide') || str_contains($name, '3d')));
        });
        $line = $id ? $lines->firstWhere('id', $id) : ($lines->count() === 1 ? $lines->first() : null);
        abort_unless($line, 422, 'Choose the booked service line for this provider.');
        return $line;
    }
}
