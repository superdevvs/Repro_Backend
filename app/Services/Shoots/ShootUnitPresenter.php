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
        $scoped = in_array($role, ['editor', 'photographer'], true);
        $visibility = app(IguideDataVisibilityService::class);

        return $shoot->units()->get()
            ->filter(fn ($unit) => ! $scoped || collect($summaries)->contains(fn ($item) => (int) ($item['shoot_unit_id'] ?? 0) === (int) $unit->id))
            ->map(function ($unit) use ($summaries, $visibility, $viewer, $role) {
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
                if (! $visibility->canManage($viewer)) {
                    $providerData = array_intersect_key($providerData, array_flip(['iguide_status', 'cubicasa_status']));
                }
                if ($iguideData !== null) {
                    $providerData['iguide_data'] = $visibility->forUser($iguideData, $viewer);
                }

                return [
                    'id' => $unit->id, 'client_key' => $unit->client_key, 'label' => $unit->label,
                    'kind' => $unit->kind, 'sqft' => $unit->sqft, 'beds' => $unit->beds, 'baths' => $unit->baths,
                    'access_notes' => $role === 'editor' ? null : $unit->access_notes, 'sort_order' => $unit->sort_order,
                    'tour_links' => $tourLinks, 'property_details' => $unit->property_details,
                    'provider_data' => $providerData, 'include_common_area_media' => $unit->include_common_area_media,
                    'property_status' => $unit->property_status, 'listing_type' => $unit->listing_type,
                    'service_count' => $items->count(), 'ready_service_count' => $ready->count(),
                    'delivery_status' => $items->isEmpty() ? 'empty' : ($ready->count() === $items->count() ? 'ready' : ($ready->isNotEmpty() ? 'partial' : 'pending')),
                    'is_ready_for_delivery' => $items->isNotEmpty() && $ready->count() === $items->count(),
                ];
            })->values()->all();
    }
}
