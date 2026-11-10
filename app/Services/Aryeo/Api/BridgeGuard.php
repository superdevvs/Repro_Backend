<?php

namespace App\Services\Aryeo\Api;

use App\Models\AryeoApiDestination;

class BridgeGuard
{
    public function assertAvailable(int $shootId, ?string $listingId = null): void
    {
        foreach (AryeoApiDestination::where(fn ($q) => $q->where('shoot_id', $shootId)->when($listingId, fn ($q) => $q->orWhere('legacy_listing_id', $listingId)))->get() as $d) {
            abort_if($d->active_export_id || $d->executor_token, 409, 'Reconcile the API export before using the Showcase bridge.');
            abort_if($d->executor !== 'bridge' && \App\Models\AryeoApiExport::where('destination_id', $d->id)->where('status', 'delivered')->exists(), 409, 'A verified handoff is required after API delivery.');
            abort_if($d->on_hold || ! data_get($d->consent, 'distribution_authorized'), 409, 'Distribution is on hold.');
            abort_if($d->installation->paused || in_array('showcase', $d->installation->paused_workflows ?? [], true), 409, 'Showcase delivery is paused.');
        }
    }
}
