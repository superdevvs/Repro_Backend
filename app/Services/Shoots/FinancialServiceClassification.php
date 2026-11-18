<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\ShootService;
use Illuminate\Database\Eloquent\Model;

/** Financial obligations are explicit booking facts, independent of media intake. */
final class FinancialServiceClassification
{
    /** New lines only: ShootService saves and attach/sync pivot rows (ShootServicePivot). */
    public function snapshot(Model $item, ?Service $service = null): void
    {
        // Changing today's catalog must never rewrite a historical booking or payout.
        if ($item->exists) return;
        $service ??= $item instanceof ShootService ? $item->service : Service::find($item->getAttribute('service_id'));
        $basis = $item->payout_snapshot ?? [];
        $financial = ($basis['delivery_requirement'] ?? null) === 'financial_only'
            || (bool) $service?->financial_only;
        $basis['delivery_requirement'] = $financial ? 'financial_only' : 'work';
        $item->payout_snapshot = $basis;
        if ($financial) $item->is_deliverable = false;
    }

    public function isFinancialOnly(ShootService $item): bool
    {
        return data_get($item->payout_snapshot, 'delivery_requirement') === 'financial_only';
    }
}
