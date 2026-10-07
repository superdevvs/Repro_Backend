<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\IguideDataVisibilityService;

class ShootUnitPresenter
{
    public function forUser(Shoot $shoot, array $summaries, ?User $viewer): array
    {
        $role = strtolower((string) $viewer?->role);
        $bookingRep = app(ShootManagementAccess::class)->isSalesRep($viewer) && app(ShootManagementAccess::class)->can($viewer);
        $scoped = in_array($role, ['editor', 'photographer'], true) && ! $bookingRep;
        $visibility = app(IguideDataVisibilityService::class);

        return $shoot->units()->get()
            ->filter(fn ($unit) => ! $scoped || collect($summaries)->contains(fn ($item) => (int) ($item['shoot_unit_id'] ?? 0) === (int) $unit->id))
            ->map(function ($unit) use ($shoot, $summaries, $visibility, $viewer, $role, $bookingRep) {
                $items = collect($summaries)->where('shoot_unit_id', $unit->id)
                    ->filter(fn ($item) => ($item['is_deliverable'] ?? true) && ($item['workflow_status'] ?? '') !== 'cancelled');
                $ready = $items->filter(fn ($item) => in_array($item['delivery_status'] ?? '', ['ready', 'delivered'], true)
                    && ($item['is_unlocked_for_delivery'] ?? false));
                $providerData = $unit->provider_data ?? [];
                $tourLinks = $unit->tour_links;
                if ($role === 'client') {
                    $safe = app(ShootUnitTourScope::class)->releasedTourData($unit, $ready->pluck('shoot_service_id')->all(), $items->count());
                    $providerData = $safe['provider_data'];
                    $tourLinks = $safe['tour_links'];
                }
                $iguideData = $providerData['iguide_data'] ?? null;
                $providerView = null;
                if ($role === 'client') {
                    $providerView = clone $shoot;
                    $providerView->setRelation('tourUnit', $unit);
                    $providerView->setRelation('releasedUnitLineIds', $ready->pluck('shoot_service_id')->all());
                }
                if (! $visibility->canManage($viewer)) {
                    $providerData = $role === 'client'
                        ? $this->clientProviderView($providerData, $visibility, $viewer, $providerView)
                        : array_intersect_key($providerData, array_flip(['iguide_status', 'cubicasa_status']));
                }
                if ($iguideData !== null && $role !== 'client') {
                    $providerData['iguide_data'] = $visibility->forUser($iguideData, $viewer);
                }

                return [
                    'id' => $unit->id, 'client_key' => $unit->client_key, 'label' => $unit->label,
                    'kind' => $unit->kind, 'sqft' => $unit->sqft, 'beds' => $unit->beds, 'baths' => $unit->baths,
                    'access_notes' => $role === 'editor' && ! $bookingRep ? null : $unit->access_notes, 'sort_order' => $unit->sort_order,
                    'tour_links' => $tourLinks, 'property_details' => $unit->property_details,
                    'provider_data' => $providerData, 'include_common_area_media' => $unit->include_common_area_media,
                    'property_status' => $unit->property_status, 'listing_type' => $unit->listing_type,
                    'service_count' => $items->count(), 'ready_service_count' => $ready->count(),
                    'delivery_status' => $items->isEmpty() ? 'empty' : ($ready->count() === $items->count() ? 'ready' : ($ready->isNotEmpty() ? 'partial' : 'pending')),
                    'is_ready_for_delivery' => $items->isNotEmpty() && $ready->count() === $items->count(),
                ];
            })->values()->all();
    }

    /** Released lines may expose tour viewers, but never provider download URLs or credentials. */
    private function clientProviderView(array $data, IguideDataVisibilityService $visibility, User $viewer, Shoot $shoot): array
    {
        $safe = array_intersect_key($data, array_flip(['iguide_status', 'cubicasa_status']));
        foreach (['iguide_tour_url', 'cubicasa_tour_url'] as $key) {
            $url = $visibility->forUser(['tour_url' => $data[$key] ?? null], $viewer)['tour_url'] ?? null;
            if ($url !== null) $safe[$key] = $url;
        }
        $view = clone $shoot;
        $view->iguide_data = $data['iguide_data'] ?? null;
        $iguide = $visibility->forUser($data['iguide_data'] ?? null, $viewer, $view);
        if ($iguide !== null) $safe['iguide_data'] = $iguide;
        foreach ($data['lines'] ?? [] as $lineId => $lineData) {
            if (! is_array($lineData)) continue;
            unset($lineData['lines']);
            $visible = $this->clientProviderView($lineData, $visibility, $viewer, $view);
            if ($visible !== []) $safe['lines'][$lineId] = $visible;
        }
        return $safe;
    }
}
