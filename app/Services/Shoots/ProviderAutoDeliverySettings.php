<?php

namespace App\Services\Shoots;

use App\Models\{Shoot, ShootService, User};
use App\Services\RolePermissionService;
use Illuminate\Support\Collection;

class ProviderAutoDeliverySettings
{
    private array $availability = [];
    public function forget(int $shootId): void { unset($this->availability[$shootId]); }
    /** Only authoritative full database rows may prime history's bounded batch. */
    public function primeAvailability(Collection $shoots): void
    {
        $items = ShootService::whereIn('shoot_id', $shoots->pluck('id'))->with('service.category')->get()->groupBy('shoot_id');
        foreach ($shoots as $shoot) $this->availability[$shoot->id] = ! $shoot->isImportDraft() && ! $shoot->isInternalTestShoot()
            && $items->get($shoot->id, collect())->contains(fn ($item) => $this->supports($item));
    }
    private function supports(ShootService $item): bool
    {
        return $item->is_deliverable && ! $item->service?->is_migration_only && ! $item->cancelled_at && $item->workflow_status !== 'cancelled' && $item->delivery_status !== 'cancelled'
            && ! data_get($item->payout_snapshot, 'billing_correction_only') && data_get($item->payout_snapshot, 'delivery_requirement') !== 'financial_only'
            && collect(app(DeliveryComponentRequirements::class)->forItem($item))->contains(fn ($part) =>
                (($part['provider'] ?? null) === 'iguide' && in_array($part['kind'] ?? '', ['floorplan', 'tour'], true))
                || (($part['provider'] ?? null) === 'cubicasa' && ($part['kind'] ?? '') === 'floorplan'));
    }
    public function supportedItems(Shoot $shoot): Collection
    {
        if (! $shoot->id || $shoot->isImportDraft() || $shoot->isInternalTestShoot()) return collect();
        // ListReadContext is a complete authorized page snapshot, unlike a
        // unit projection or role-filtered relation on a presenter model.
        $items = ShootListReadContext::current()?->serviceItems($shoot) ?? $shoot->serviceItems()->with(['service.category', 'unit'])->get();
        return $items->filter(fn ($item) => $this->supports($item));
    }
    public function canManage(Shoot $shoot, ?User $actor): bool
    {
        return $actor && in_array($actor->role, ['admin', 'superadmin', 'super_admin', 'editing_manager'], true)
            && app(RolePermissionService::class)->userCan($actor, 'shoots', 'manage')
            && app(ShootAuthorizationSupport::class)->canViewShootDetails($shoot, $actor);
    }
    public function payload(Shoot $shoot, ?User $actor): array
    {
        $available = $this->availability[$shoot->id] ??= $this->supportedItems($shoot)->isNotEmpty();
        $result = ['enabled' => $shoot->auto_delivery_enabled === null ? true : (bool) $shoot->auto_delivery_enabled,
            'available' => $available, 'can_manage' => $available && $this->canManage($shoot, $actor)];
        if (! $available) $result['reason'] = 'No supported iGuide or CubiCasa delivery components are booked.';
        return $result;
    }
}
