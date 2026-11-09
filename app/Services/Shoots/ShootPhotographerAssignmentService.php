<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use Illuminate\Database\Eloquent\Builder;

class ShootPhotographerAssignmentService
{
    /** Match the same per-service ownership used by the photographer's shoot details. */
    public function scopeAssignedTo(Builder $query, int|array $photographerIds): Builder
    {
        $photographerIds = (array) $photographerIds;

        return $query->where(function (Builder $scope) use ($photographerIds) {
            $scope->whereHas('serviceItems', fn (Builder $items) => $items->whereIn('photographer_id', $photographerIds))
                ->orWhere(fn (Builder $legacy) => $legacy->whereIn('shoots.photographer_id', $photographerIds)
                    ->where(fn (Builder $fallback) => $fallback->whereDoesntHave('serviceItems')
                        ->orWhereHas('serviceItems', fn (Builder $items) => $this->scopeInheritingHeader($items))));
        });
    }

    /**
     * Keep the legacy header only while it owns a line or supplies a legacy
     * unassigned line. Call after all line changes, before saving the shoot.
     */
    public function reconcileHeader(Shoot $shoot): void
    {
        if (! $shoot->photographer_id) {
            return;
        }

        $assignments = $shoot->serviceItems()->orderBy('id')->pluck('photographer_id');
        if ($assignments->isEmpty() || $this->scopeInheritingHeader($shoot->serviceItems()->getQuery())->exists()
            || $assignments->contains(fn ($id) => (int) $id === (int) $shoot->photographer_id)) {
            return;
        }

        $replacement = $assignments->filter()->first();
        $shoot->photographer_id = $replacement ? (int) $replacement : null;
        $shoot->unsetRelation('photographer');
    }

    private function scopeInheritingHeader(Builder $items): Builder
    {
        // Financial fees have explicit optional recipients; digital work has no
        // photographer. Neither may retain an obsolete capture assignment.
        return $items->whereNull('photographer_id')
            ->where(fn (Builder $work) => $work->whereNull('payout_snapshot->delivery_requirement')
                ->orWhere('payout_snapshot->delivery_requirement', '!=', 'financial_only'))
            ->whereHas('service', fn (Builder $service) => $service
                ->where(fn (Builder $assignable) => $assignable->where('photographer_required', true)
                    ->orWhere('financial_only', true)->orWhereRaw("LOWER(name) LIKE '%travel fee%'"))
                ->whereRaw("LOWER(TRIM(name)) NOT IN ('virtual staging', 'virtual staging (per image)')")
                ->whereDoesntHave('category', fn (Builder $category) => $category->whereRaw("LOWER(TRIM(name)) = 'digital enhancements'")));
    }
}
