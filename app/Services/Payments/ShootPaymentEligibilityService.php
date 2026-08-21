<?php

namespace App\Services\Payments;

use App\Models\Shoot;

class ShootPaymentEligibilityService
{
    /** @return array{payable: bool, total: float, paid: float, remaining: float, status: string} */
    public function summarize(Shoot $shoot): array
    {
        $shoot->loadMissing('payments.refunds');
        $total = max((float) ($shoot->total_quote ?? 0), 0);
        $paid = max((float) $shoot->calculateCanonicalTotalPaid(), 0);
        $remaining = max(round($total - $paid, 2), 0);
        $bypass = (bool) ($shoot->bypass_paywall ?? false);
        $payable = ! $bypass && $total > 0.01 && $remaining > 0.01;

        return [
            'payable' => $payable,
            'total' => $total,
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => ! $payable
                ? 'paid'
                : ($paid > 0.01 ? 'partial' : 'unpaid'),
        ];
    }

    public function canPay(Shoot $shoot): bool
    {
        return $this->summarize($shoot)['payable'];
    }
}
