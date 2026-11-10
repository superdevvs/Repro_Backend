<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ShootPhotographerAssignmentService
{
    private const PREPARED_READ_KEY = 'shoot_photographer_prepared_read';

    /** Use freshly batched policy clones only for this bounded read operation. */
    public function withPreparedRead(Collection $shoots, callable $read): mixed
    {
        $request = request();
        $previous = $request->attributes->get(self::PREPARED_READ_KEY);
        $assignments = new \WeakMap;
        foreach ($shoots as $shoot) {
            if (! $shoot->relationLoaded('serviceItems')) continue;
            $items = $shoot->getRelation('serviceItems');
            $owners = $items->pluck('photographer_id')->filter()->map(fn ($id) => (int) $id);
            if ($shoot->photographer_id && ($items->isEmpty() || $items->contains(fn ($item) => ! $item->photographer_id
                && ! app(FinancialServiceClassification::class)->isFinancialOnly($item)
                && ($item->service?->allowsPhotographerAssignment() ?? false)))) {
                $owners->push((int) $shoot->photographer_id);
            }
            $assignments[$shoot] = $owners->unique()->all();
        }
        $request->attributes->set(self::PREPARED_READ_KEY, $assignments);
        try {
            return $read();
        } finally {
            $previous === null ? $request->attributes->remove(self::PREPARED_READ_KEY)
                : $request->attributes->set(self::PREPARED_READ_KEY, $previous);
        }
    }

    public function preparedReadAssignment(Shoot $shoot, int $photographerId): ?bool
    {
        $assignments = request()->attributes->get(self::PREPARED_READ_KEY);

        return $assignments instanceof \WeakMap && isset($assignments[$shoot])
            ? in_array($photographerId, $assignments[$shoot], true) : null;
    }

    /** A nested policy clone shares the same freshly fetched assignment snapshot. */
    public function copyPreparedRead(Shoot $source, Shoot $copy): void
    {
        $assignments = request()->attributes->get(self::PREPARED_READ_KEY);
        if ($assignments instanceof \WeakMap && isset($assignments[$source]) && $source->getKey() === $copy->getKey()) {
            $assignments[$copy] = $assignments[$source];
        }
    }

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
