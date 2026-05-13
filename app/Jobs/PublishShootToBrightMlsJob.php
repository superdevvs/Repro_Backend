<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\User;
use App\Services\BrightMlsService;
use App\Services\ShootActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background auto-publish to Bright MLS after a shoot is finalized.
 *
 * Non-blocking: failures here must never revert delivery state. Retries are
 * owned by the queue worker. Workflow logs capture success/failure for audit.
 */
class PublishShootToBrightMlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 300;

    public function __construct(public int $shootId, public ?int $actorUserId = null)
    {
        $this->onQueue('integrations');
    }

    public function handle(BrightMlsService $brightMls, ShootActivityLogger $activityLogger): void
    {
        /** @var Shoot|null $shoot */
        $shoot = Shoot::query()->find($this->shootId);
        if (!$shoot) {
            return;
        }

        if (!$brightMls->isAutoPublishAvailable()) {
            return;
        }

        try {
            $result = $brightMls->autoPublishForShoot($shoot);
            if (!$result) {
                return;
            }

            if (!empty($result['success'])) {
                try {
                    $actor = $this->actorUserId ? User::find($this->actorUserId) : null;
                    $activityLogger->log(
                        $shoot,
                        'bright_mls_synced',
                        [
                            'manifest_id' => $result['manifest_id'] ?? null,
                            'mls_id' => $result['mls_id'] ?? $shoot->mls_id,
                            'status' => $result['status'] ?? null,
                            'mode' => $result['mode'] ?? null,
                            'environment' => $result['environment'] ?? null,
                            'auto_publish' => true,
                        ],
                        $actor
                    );
                } catch (\Throwable $logEx) {
                    Log::warning('Failed to log Bright MLS auto-publish activity', [
                        'shoot_id' => $shoot->id,
                        'error' => $logEx->getMessage(),
                    ]);
                }

                Log::info('Bright MLS auto-published on finalize (async)', [
                    'shoot_id' => $shoot->id,
                    'manifest_id' => $result['manifest_id'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Bright MLS auto-publish failed (async, non-blocking)', [
                'shoot_id' => $shoot->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('PublishShootToBrightMlsJob exhausted retries', [
            'shoot_id' => $this->shootId,
            'error' => $exception->getMessage(),
        ]);
    }
}
