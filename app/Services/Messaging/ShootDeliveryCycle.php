<?php

namespace App\Services\Messaging;

use App\Models\Shoot;

/** Delivery identity follows the execution cycle, never a notification or sync timestamp. */
final class ShootDeliveryCycle
{
    public function key(Shoot $shoot): string
    {
        $reopened = $shoot->active_work_is_reopened
            ?? (bool) ($shoot->admin_verified_at || $shoot->completed_at);

        return $shoot->active_work_started_at && $reopened
            ? 'reopened:'.($shoot->getAttribute('active_work_cycle_key') ?: $shoot->active_work_started_at->toIso8601String())
            : 'initial';
    }

    public function isFullDelivery(Shoot $shoot): bool
    {
        return ($shoot->workflow_status ?: $shoot->status) === Shoot::STATUS_DELIVERED
            && ! in_array($shoot->status, ['on_hold', 'hold_on', 'cancelled', 'canceled', 'declined'], true)
            && $shoot->delivery_status === 'delivered'
            && ! app(\App\Services\Shoots\ShootActiveWorkLifecycle::class)->hasActiveWork($shoot);
    }

    public function emailKey(string $contentKey, Shoot $shoot): string
    {
        $cycle = $this->key($shoot);

        return $cycle === 'initial' ? $contentKey : $contentKey.':'.$cycle;
    }

    public function eventId(Shoot $shoot): string
    {
        $cycle = $this->key($shoot);
        // Keep initial-delivery replay identities compatible with previously accepted workflows.
        $version = $cycle === 'initial' ? ($shoot->completed_at?->toIso8601String() ?? 'full') : $cycle;

        return 'shoot-delivered:'.$shoot->id.':'.$version;
    }
}
