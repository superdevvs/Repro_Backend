<?php

namespace App\Services\Shoots;

use App\Models\Payment;
use App\Models\Shoot;

class PendingOfflinePaymentSummary
{
    public function forShoot(Shoot $shoot): array
    {
        $shoot->loadMissing('payments');
        $payments = $shoot->payments
            ->filter(fn (Payment $payment) => $payment->status === Payment::STATUS_PENDING
                && in_array($payment->payment_method, ['cash', 'check'], true))
            ->map(function (Payment $payment) {
                $details = is_array($payment->payment_details) ? $payment->payment_details : [];

                return [
                    'id' => (int) $payment->id,
                    'amount' => (float) $payment->amount,
                    'currency' => strtoupper((string) ($payment->currency ?: 'USD')),
                    'paymentMethod' => (string) $payment->payment_method,
                    'status' => (string) $payment->status,
                    'createdAt' => optional($payment->created_at)->toIso8601String(),
                    'submittedByName' => $details['submitted_by_name'] ?? null,
                    'submittedByRole' => $details['submitted_by_role'] ?? null,
                    'checkNumber' => $details['check_number'] ?? null,
                    'paymentDate' => $details['payment_date'] ?? null,
                    'notes' => $details['notes'] ?? null,
                ];
            })->values()->all();

        return ['pendingPayments' => $payments, 'pendingTotal' => (float) array_sum(array_column($payments, 'amount'))];
    }
}
