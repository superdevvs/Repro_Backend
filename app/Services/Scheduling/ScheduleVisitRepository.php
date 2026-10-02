<?php

namespace App\Services\Scheduling;

use App\Models\PhotographerAvailability;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootDurationResolver;
use Illuminate\Database\Eloquent\Builder;

class ScheduleVisitRepository
{
    private function shoots(array $photographerIds): Builder
    {
        return Shoot::query()->where(function ($query) use ($photographerIds) {
            $query->whereIn('photographer_id', $photographerIds)->orWhereHas('serviceItems',
                fn ($items) => $items->whereIn('photographer_id', $photographerIds));
        });
    }

    public function visits(array $photographerIds, array $excludedShootIds = []): array
    {
        if ($photographerIds === []) {
            return [];
        }
        $shoots = $this->shoots($photographerIds)->with(['services', 'serviceItems.service', 'serviceItems.unit'])
            ->whereNotIn('id', $excludedShootIds)
            ->whereNotIn('status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED, Shoot::STATUS_ON_HOLD, 'hold_on', Shoot::STATUS_REQUESTED, Shoot::STATUS_IMPORT_DRAFT])
            ->get();
        $photographerNames = User::whereIn('id', $photographerIds)->pluck('name', 'id');
        $visits = [];
        foreach ($shoots as $shoot) {
            foreach ($photographerIds as $photographerId) {
                foreach (app(ShootDurationResolver::class)->windowsForShoot($shoot, $photographerId) as $window) {
                    if (! $window['start'] || $window['minutes'] <= 0) {
                        continue;
                    }
                    $rows = $shoot->serviceItems->whereIn('id', $window['row_indexes']);
                    $active = ['scheduled', 'in_progress', 'editing'];
                    if (! in_array($shoot->status, $active, true)
                        && ! $rows->contains(fn ($item) => in_array($item->workflow_status, [ShootService::WORKFLOW_SCHEDULED, ShootService::WORKFLOW_IN_PROGRESS, ShootService::WORKFLOW_READY], true))) {
                        continue;
                    }
                    $start = $window['start']->copy()->utc();
                    $visits[] = ['id' => 'booked:'.$shoot->id.':'.$photographerId.':'.$start->getTimestamp(),
                        'photographer_id' => (int) $photographerId, 'start' => $start->toIso8601String(),
                        'end' => $start->copy()->addMinutes($window['minutes'])->toIso8601String(),
                        'duration_minutes' => $window['minutes'],
                        'timezone' => $shoot->timezone ?: $window['start']->timezoneName,
                        'proposed' => false, '_shoot' => $shoot,
                        '_photographer' => ['id' => (int) $photographerId, 'name' => (string) ($photographerNames[$photographerId] ?? '')],
                        '_services' => $rows->map(fn ($item) => $item->service)
                            ->filter()->unique('id')->map(fn ($service) => ['id' => (int) $service->id,
                                'name' => (string) $service->name])->values()->all()];
                }
            }
        }

        return $visits;
    }

    /** Only office/sales staff with access to this exact booking receive context. */
    public function neighborContext(array $visit, ?User $actor): ?array
    {
        $shoot = $visit['_shoot'] ?? null;
        $access = app(ShootAuthorizationSupport::class);
        if (! $shoot instanceof Shoot || ! $access->hasRole($actor, ['admin', 'superadmin', 'salesRep'])
            || ! $access->canViewShootDetails($shoot, $actor)) {
            return null;
        }

        return ['shoot_id' => (int) $shoot->id, 'scheduled_at' => $visit['start'], 'end_at' => $visit['end'],
            'timezone' => $visit['timezone'], 'services' => $visit['_services'] ?? [],
            'photographer' => $visit['_photographer'] ?? null, 'can_view_details' => true];
    }

    /** Read-only fingerprint includes empty-day insertions, line assignments and hours. */
    public function fingerprint(array $photographerIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $photographerIds)));
        sort($ids);
        $shoots = $ids ? $this->shoots($ids)->with(['serviceItems.service', 'serviceItems.unit', 'services'])->orderBy('id')->get() : collect();
        $state = $shoots->map(fn ($shoot) => ['shoot' => $shoot->getAttributes(),
            'items' => $shoot->serviceItems->sortBy('id')->map(fn ($item) => [$item->getAttributes(),
                $item->service?->getAttributes(), $item->unit?->getAttributes()])->values()->all(),
            'windows' => app(ShootDurationResolver::class)->windowsForShoot($shoot),
        ])->all();
        $hours = $ids ? PhotographerAvailability::whereIn('photographer_id', $ids)->orderBy('id')->get()->toArray() : [];

        return hash('sha256', json_encode([$ids, $state, $hours], JSON_THROW_ON_ERROR));
    }
}
