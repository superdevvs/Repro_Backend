<?php

namespace App\Services\Shoots;

use App\Models\ShootService;

/** Financial obligations are explicit booking facts, independent of media intake. */
final class FinancialServiceClassification
{
    public function snapshot(ShootService $item): void
    {
        // Changing today's catalog must never rewrite a historical booking or payout.
        if ($item->exists) return;
        $basis = $item->payout_snapshot ?? [];
        $financial = ($basis['delivery_requirement'] ?? null) === 'financial_only'
            || (bool) $item->service?->financial_only;
        $basis['delivery_requirement'] = $financial ? 'financial_only' : 'work';
        $item->payout_snapshot = $basis;
        if ($financial) $item->is_deliverable = false;
    }

    public function isFinancialOnly(ShootService $item): bool
    {
        return data_get($item->payout_snapshot, 'delivery_requirement') === 'financial_only';
    }
}
