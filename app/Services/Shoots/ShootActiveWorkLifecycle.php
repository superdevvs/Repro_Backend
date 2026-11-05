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
        return $shoot->active_work_started_at !== null && $shoot->active_work_completed_at === null
            && ($shoot->active_work_is_reopened ?? (bool) ($shoot->admin_verified_at || $shoot->completed_at));
    }

    public function isInitialPartial(Shoot $shoot): bool
    {
        return ! $this->hasActiveWork($shoot) && ! $shoot->admin_verified_at && ! $shoot->completed_at
            && (($shoot->active_work_started_at && $shoot->active_work_is_reopened === false)
                || $shoot->delivery_status === 'partially_delivered' || app(DeliveryPlanService::class)->hasReleasedComponents($shoot));
    }

    /** Original services still being edited when the order is first partially delivered. */
    public function initialDeliveryItemIds(Shoot $shoot): array
    {
        if (! $this->hasActiveWork($shoot) || $this->isSuspended($shoot)
            || $shoot->admin_verified_at || $shoot->completed_at) return [];
        return $shoot->serviceItems()->get()->filter(fn (ShootService $item) => ! $item->delivered_at
            && ! data_get($item->payout_snapshot, 'additional_work', false))->modelKeys();
    }

    public function outstandingItems(Shoot $shoot): Collection
    {
        return $shoot->serviceItems()->with(['service', 'photographer'])->get()
            ->filter(fn (ShootService $item) => $item->is_deliverable
                && ! $item->cancelled_at
                && $item->workflow_status !== ShootService::WORKFLOW_CANCELLED
                && $item->delivery_status !== ShootService::DELIVERY_CANCELLED
                && ! app(DeliveryPlanService::class)->itemResolved($shoot, $item)
                && ! data_get($item->payout_snapshot, 'billing_correction_only', false));
    }

    public function begin(Shoot $shoot): bool
    {
        if ($this->outstandingItems($shoot)->isEmpty()) return false;
        if (! $this->hasActiveWork($shoot) && ! $shoot->admin_verified_at && ! $shoot->completed_at
            && ! in_array($shoot->workflow_status ?: $shoot->status, ['delivered', 'admin_verified', 'ready_for_client'], true)
            && ! $shoot->serviceItems()->get()->contains(fn ($item) => data_get($item->payout_snapshot, 'additional_work', false))) {
            if (! in_array($shoot->workflow_status, ['editing', 'review'], true)) {
                $shoot->status = $shoot->workflow_status = Shoot::STATUS_EDITING;
                $shoot->save();
            }
            return false;
        }
        if (! $this->hasActiveWork($shoot)) {
            $shoot->active_work_is_reopened = true;
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
        if (! $this->hasActiveWork($shoot)) {
            if (\Illuminate\Support\Facades\Schema::hasTable('shoot_delivery_receipts') && $this->isInitialPartial($shoot) && ! $this->isSuspended($shoot)
                && \Illuminate\Support\Facades\DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)->exists()
                && $this->outstandingItems($shoot)->isEmpty()) {
                $actorId = auth()->id() ?? \Illuminate\Support\Facades\DB::table('shoot_delivery_receipts')->where('shoot_id', $shoot->id)
                    ->whereNotNull('actor_id')->orderByDesc('id')->value('actor_id');
                if ($actorId) $shoot->updateWorkflowStatus(Shoot::STATUS_DELIVERED, $actorId);
                else {
                    // An automatic rollup has no invented user identity. The
                    // immutable receipts retain the available attribution.
                    $shoot->forceFill(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
                        'delivery_status' => 'delivered', 'admin_verified_at' => $shoot->admin_verified_at ?? now(), 'completed_at' => $shoot->completed_at ?? now()])->save();
                }
                return;
            }
            if ($shoot->status === Shoot::STATUS_ACTIVE && ! $shoot->admin_verified_at && ! $shoot->completed_at) {
                $shoot->status = $shoot->workflow_status = Shoot::STATUS_EDITING;
                $shoot->save();
            }
            return;
        }
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
            $anchors = $pending->map(fn ($item) => ['at' => $item->scheduled_at ? $resolver->forServiceItem($shoot, $item)?->utc() : ($item->created_at ?? $shoot->active_work_started_at), 'scheduled' => (bool) $item->scheduled_at])
                ->filter(fn ($anchor) => $anchor['at'])->sortBy(fn ($anchor) => $anchor['at']->getTimestamp());
            $anchor = $anchors->first();
            $shoot->active_work_at = $anchor['at'] ?? $shoot->active_work_started_at;
            $shoot->active_work_date = $shoot->active_work_at->copy()
                ->setTimezone($shoot->timezone ?: $resolver->timezoneForShoot($shoot))->toDateString();
            $shoot->active_work_time = ($anchor['scheduled'] ?? false)
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
            'display_status' => $active ? Shoot::STATUS_ACTIVE : (($shoot->workflow_status ?: $shoot->status) === Shoot::STATUS_ACTIVE ? Shoot::STATUS_EDITING : ($shoot->workflow_status ?: $shoot->status)),
            'active_work_started_at' => $shoot->active_work_started_at?->toIso8601String(),
            'active_work_completed_at' => $shoot->active_work_completed_at?->toIso8601String(),
            'active_work_at' => $at?->toIso8601String(),
            'active_work_date' => $active ? $at?->copy()->setTimezone($zone)->toDateString() : null,
            'active_work_time' => $active ? $shoot->active_work_time : null,
        ];
    }

    private function isSuspended(Shoot $shoot): bool
    {
        return collect([$shoot->status, $shoot->workflow_status])->contains(fn ($state) => in_array(strtolower((string) $state),
            ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true));
    }
}
