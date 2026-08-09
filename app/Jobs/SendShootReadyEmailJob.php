<?php

namespace App\Jobs;

use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\FinalizeProgressTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background ready/delivered email + automation event dispatch.
 *
 * Decoupled from FinalizeShootJob so a slow/failing mail provider can never
 * block the user-facing "delivered" transition.
 */
class SendShootReadyEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 120;

    public function __construct(
        public int $shootId,
        public ?int $shootServiceId = null,
        public bool $isFullOrderDelivery = true,
        public bool $fireAutomation = true
    ) {
        // Use the default queue so it lines up with the running workers.
        // The dedicated 'mail' queue is not consumed in production, which
        // previously left the delivered/ready email permanently unsent while
        // the rest of the finalize flow (status flip, MLS publish) succeeded.
        $this->onQueue('default');
    }

    public function handle(
        MailService $mail,
        AutomationService $automation,
        ?FinalizeProgressTracker $progress = null
    ): void {
        $progress ??= app(FinalizeProgressTracker::class);

        /** @var Shoot|null $shoot */
        $shoot = Shoot::query()->find($this->shootId);
        if (!$shoot) {
            $progress->stageSkipped($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, 'Shoot not found');
            return;
        }

        $progress->stageRunning($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL);

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
        $client = $shoot->client ?: User::find($shoot->client_id);

        $emailError = null;
        $systemEmailAlreadySent = false;
        if ($client) {
            try {
                if ($this->shootServiceId) {
                    $mail->sendShootReadyEmail($client, $shoot, [$this->shootServiceId], $this->isFullOrderDelivery);
                    $systemEmailAlreadySent = $this->isFullOrderDelivery;
                } elseif ($this->isFullOrderDelivery) {
                    $mail->sendShootReadyEmail($client, $shoot);
                    $systemEmailAlreadySent = true;
                }
            } catch (\Throwable $e) {
                $emailError = $e->getMessage();
                Log::warning('SendShootReadyEmailJob: ready email failed', [
                    'shoot_id' => $shoot->id,
                    'shoot_service_id' => $this->shootServiceId,
                    'error' => $e->getMessage(),
                ]);
                // Do not rethrow — we still want the automation event to fire
                // if configured. Email idempotency is handled upstream.
            }
        }

        if ($this->fireAutomation && $this->isFullOrderDelivery) {
            try {
                $context = $automation->buildShootContext($shoot);
                if ($shoot->rep) {
                    $context['rep'] = $shoot->rep;
                }
                $context['system_email_already_sent'] = $systemEmailAlreadySent;
                $automation->handleEvent('SHOOT_COMPLETED', $context);
            } catch (\Throwable $e) {
                Log::warning('SendShootReadyEmailJob: automation dispatch failed', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Gap C anchor: the automated ready/delivered path must also start the payment-reminder
            // cadence (the manual shoot_ready send already does). Stamp shoot_ready_notified_at only
            // when it is not already set so a later ready send / job re-run never moves the anchor and
            // reshuffles the cadence (Req 4.2 anchor stability). The stamp/schedule runs regardless of
            // whether the mail send above succeeded — the anchor represents "we attempted the ready
            // notification" — and is scoped to the same full-order-delivery condition as the automation.
            try {
                if ($shoot->shoot_ready_notified_at === null) {
                    $shoot->forceFill(['shoot_ready_notified_at' => now()])->save();
                }

                // schedulePaymentReminders() self-guards: it cancels/skips for a paid shoot, no-ops
                // without an anchor, and is idempotent on (shoot_id, scheduled_date), so calling it on
                // an already-anchored shoot neither moves the anchor nor duplicates reminder rows
                // (Req 4.3, 4.4, 4.5). Refresh so the just-stamped anchor is visible to the scheduler.
                $automation->schedulePaymentReminders($shoot->refresh());
            } catch (\Throwable $e) {
                Log::warning('SendShootReadyEmailJob: payment reminder scheduling failed', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($emailError !== null) {
            $progress->stageFailed($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, $emailError);
            return;
        }

        $progress->stageCompleted(
            $this->shootId,
            FinalizeProgressTracker::STAGE_DELIVERY_EMAIL,
            $client ? 'Client notified' : 'No client contact on this shoot'
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendShootReadyEmailJob exhausted retries', [
            'shoot_id' => $this->shootId,
            'error' => $exception->getMessage(),
        ]);

        app(FinalizeProgressTracker::class)->stageFailed(
            $this->shootId,
            FinalizeProgressTracker::STAGE_DELIVERY_EMAIL,
            $exception->getMessage()
        );
    }
}
