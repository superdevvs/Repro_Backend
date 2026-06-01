<?php

namespace App\Services\Shoots;

use App\Http\Controllers\StripePaymentController;
use App\Models\Shoot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShootPaymentStatusSupport
{
    public function __construct(protected ShootMediaMutationSupportService $shootMediaMutationSupportService)
    {
    }

    public function calculatePaymentStatus(float $totalPaid, float $totalQuote): string
    {
        if ($totalQuote <= 0.01) {
            return 'paid';
        }

        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        if ($totalPaid >= $totalQuote) {
            return 'paid';
        }

        return 'partial';
    }

    public function reconcileStripePaymentState(Shoot $shoot, array $relations = []): Shoot
    {
        $shoot->loadMissing(array_values(array_unique(array_merge($relations, ['payments', 'client']))));

        $summary = $shoot->syncPaymentStatusFromRecords($shoot->payment_type ?: null);
        if (($summary['remaining_balance'] ?? 0) <= 0) {
            return $shoot;
        }

        try {
            app(StripePaymentController::class)->reconcileShootPayments($shoot);
        } catch (\Throwable $exception) {
            Log::warning('Stripe payment reconciliation failed while preparing shoot response', [
                'shoot_id' => $shoot->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $refreshRelations = array_values(array_unique(array_merge($relations, ['payments', 'client'])));
        $shoot = $shoot->fresh($refreshRelations) ?? $shoot->loadMissing($refreshRelations);
        $shoot->syncPaymentStatusFromRecords($shoot->payment_type ?: null);

        return $shoot;
    }

    public function clearShootCachesAfterPayment(Shoot $shoot): void
    {
        $this->shootMediaMutationSupportService->clearShootFilesCache($shoot);

        if (!$shoot->client_id) {
            return;
        }

        $clientId = $shoot->client_id;
        foreach (['all', 'active', 'completed', 'delivered', 'pending', 'canceled', 'requested', 'on_hold', 'scheduled'] as $tab) {
            for ($page = 1; $page <= 3; $page++) {
                $pattern = "shoots_{$clientId}_{$tab}_{$page}_";
                foreach ([20, 25, 50, 100] as $perPage) {
                    Cache::forget($pattern . $perPage);
                }
            }
        }
    }
}
