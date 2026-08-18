<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateCubiCasaOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $shootId,
        public readonly string $source = 'auto',
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(CubiCasaService $cubicasa): void
    {
        $shoot = Shoot::with('services')->find($this->shootId);

        // Req 3.1 — missing shoot: complete silently.
        if (!$shoot) {
            return;
        }

        // Req 3.2 — cancelled/declined (either status or workflow_status): complete silently.
        $cancelledOrDeclined = [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED];
        if (in_array($shoot->status, $cancelledOrDeclined, true)
            || in_array($shoot->workflow_status, $cancelledOrDeclined, true)
        ) {
            return;
        }

        // Req 3.3 — no eligible service: complete silently.
        if (!$shoot->hasCubiCasaEligibleService()) {
            return;
        }

        // Req 3.4 — already linked: complete silently.
        if (!empty($shoot->cubicasa_order_id) || !empty($shoot->cubicasa_external_id)) {
            return;
        }

        // Req 4.1 / 4.2 — no credentials: info log, return WITHOUT throwing (no retry).
        if (!$cubicasa->hasCredentials()) {
            Log::info('CubiCasa auto-create skipped: credentials not configured.', [
                'shoot_id' => $shoot->id,
            ]);

            return;
        }

        // Req 3.5 — invoke createOrder with source "auto".
        $parsed = $cubicasa->createOrder($shoot, null, 'auto');

        if ($parsed === null) {
            $reason = $cubicasa->getLastFailureReason();

            // Req 4.3 — authentication failure: log, return WITHOUT throwing (no retry).
            if ($reason === CubiCasaService::FAILURE_AUTH) {
                Log::info('CubiCasa auto-create skipped: authentication failure.', [
                    'shoot_id' => $shoot->id,
                    'failure_reason' => $reason,
                ]);

                return;
            }

            // A missing env var is not transient, so burning three attempts and
            // an 18-minute backoff on it is pointless. CubiCasaService has
            // already logged the specific cause at error level.
            if ($reason === CubiCasaService::FAILURE_CONFIG) {
                Log::info('CubiCasa auto-create skipped: configuration incomplete.', [
                    'shoot_id' => $shoot->id,
                    'failure_reason' => $reason,
                ]);

                return;
            }

            // Req 5.1 / 5.2 — transient null: warn with failure reason, then throw to retry.
            Log::warning('CubiCasa auto-create returned no order.', [
                'shoot_id' => $shoot->id,
                'failure_reason' => $reason,
                'attempt' => $this->attempts(),
            ]);

            throw new \RuntimeException(
                'CubiCasa auto-create failed for shoot ' . $shoot->id . ' (' . ($reason ?? 'unknown') . ')'
            );
        }
    }
}
