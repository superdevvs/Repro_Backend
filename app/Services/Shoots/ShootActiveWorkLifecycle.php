<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootService;
use App\Services\Schedule\ScheduleInstantResolver;
use Illuminate\Support\Collection;

/** Additional production work has its own cycle; the original delivery is immutable. */
class ShootActiveWorkLifecycle
{
    public function hasActiveWork(Shoot $shoot): bool
    {
        return $shoot->active_work_started_at !== null && $shoot->active_work_completed_at === null;
    }

    public function outstandingItems(Shoot $shoot): Collection
    {
        return $shoot->serviceItems()->with(['service', 'photographer'])->get()
            ->filter(fn (ShootService $item) => $item->is_deliverable
                && ! $item->cancelled_at
                && $item->workflow_status !== ShootService::WORKFLOW_CANCELLED
                && $item->delivery_status !== ShootService::DELIVERY_CANCELLED
                && ($item->delivery_status !== ShootService::DELIVERY_DELIVERED || $item->workflow_status !== ShootService::WORKFLOW_DELIVERED)
                && ! data_get($item->payout_snapshot, 'billing_correction_only', false));
    }

    public function begin(Shoot $shoot): bool
    {
        if ($this->outstandingItems($shoot)->isEmpty()) return false;
        if (! $this->hasActiveWork($shoot)) {
            $shoot->active_work_started_at = now();
            $shoot->active_work_completed_at = null;
            $shoot->status = Shoot::STATUS_ACTIVE;
            $shoot->workflow_status = Shoot::STATUS_ACTIVE;
            app(ActiveVideoLinkOutput::class)->startCycle($shoot);
        }
        $this->reconcile($shoot);
        return true;
    }

    /** Called inside the owning write transaction, after service changes or delivery. */
    public function reconcile(Shoot $shoot): void
    {
        if (! $this->hasActiveWork($shoot)) return;
        if ($this->isSuspended($shoot)) return;
        app(ActiveVideoLinkOutput::class)->ensureBaseline($shoot);
        $pending = $this->outstandingItems($shoot);
        if ($pending->isEmpty()) {
            $shoot->active_work_completed_at = now();
            $shoot->status = Shoot::STATUS_DELIVERED;
            $shoot->workflow_status = Shoot::STATUS_DELIVERED;
            $shoot->delivery_status = ShootService::DELIVERY_DELIVERED;
        } else {
            $resolver = app(ScheduleInstantResolver::class);
            $scheduled = $pending->filter(fn ($item) => $item->scheduled_at)
                ->map(fn ($item) => $resolver->forServiceItem($shoot, $item)?->utc())->filter()->sort();
            $shoot->active_work_at = $scheduled->first()
                ?? $pending->pluck('created_at')->filter()->sort()->first()
                ?? $shoot->active_work_started_at;
            $shoot->active_work_date = $shoot->active_work_at->copy()
                ->setTimezone($shoot->timezone ?: $resolver->timezoneForShoot($shoot))->toDateString();
            $shoot->active_work_time = $scheduled->isNotEmpty()
                ? $shoot->active_work_at->copy()->setTimezone($shoot->timezone ?: $resolver->timezoneForShoot($shoot))->format('H:i') : null;
            $shoot->status = Shoot::STATUS_ACTIVE;
            if (in_array($shoot->workflow_status, ['delivered', 'ready_for_client', 'admin_verified'], true)) {
                $shoot->workflow_status = Shoot::STATUS_ACTIVE;
            }
            $shoot->delivery_status = $shoot->serviceItems()->where('delivery_status', ShootService::DELIVERY_DELIVERED)->exists()
                ? 'partially_delivered' : ShootService::DELIVERY_NOT_STARTED;
        }
        if ($shoot->isDirty()) $shoot->save();
    }

    public function presentation(Shoot $shoot): array
    {
        $active = $this->hasActiveWork($shoot) && ! $this->isSuspended($shoot);
        $at = $shoot->active_work_at;
        $zone = $shoot->timezone ?: app(ScheduleInstantResolver::class)->timezoneForShoot($shoot);
        return [
            'has_active_work' => $active,
            'display_status' => $active ? Shoot::STATUS_ACTIVE : ($shoot->workflow_status ?: $shoot->status),
            'active_work_started_at' => $shoot->active_work_started_at?->toIso8601String(),
            'active_work_completed_at' => $shoot->active_work_completed_at?->toIso8601String(),
            'active_work_at' => $at?->toIso8601String(),
            'active_work_date' => $active ? $at?->copy()->setTimezone($zone)->toDateString() : null,
            'active_work_time' => $active ? $shoot->active_work_time : null,
        ];
    }

    private function isSuspended(Shoot $shoot): bool
    {
        return in_array(strtolower((string) ($shoot->workflow_status ?: $shoot->status)),
            ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true);
    }
}
