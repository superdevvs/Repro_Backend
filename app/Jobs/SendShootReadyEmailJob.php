<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ShootDeliveryNotificationRecorder;
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
        $recorder = app(ShootDeliveryNotificationRecorder::class);

        /** @var Shoot|null $shoot */
        $shoot = Shoot::query()->find($this->shootId);
        if (! $shoot) {
            $progress->stageSkipped($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, 'Shoot not found');

            return;
        }

        if ($shoot->isInternalTestShoot()) {
            $progress->stageSkipped($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, 'Internal test: external side effects suppressed');

            return;
        }

        $progress->stageRunning($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL);

        $shoot->loadMissing(['client', 'photographer', 'rep', 'service']);
        $client = $shoot->client ?: User::find($shoot->client_id);

        $failure = null;
        $systemEmailAlreadySent = false;
        $accepted = collect();
        $dispatch = [];
        if ($client && (! $this->isFullOrderDelivery || $automation->shouldUseFallback('SHOOT_COMPLETED'))) {
            try {
                $sent = false;
                if ($this->shootServiceId) {
                    $sent = $mail->sendShootReadyEmail($client, $shoot, [$this->shootServiceId], $this->isFullOrderDelivery);
                } elseif ($this->isFullOrderDelivery) {
                    $sent = $mail->sendShootReadyEmail($client, $shoot);
                }
                if ($sent) {
                    $accepted = Message::where('related_shoot_id', $shoot->id)->where('send_source', 'SHOOT_DELIVERED')
                        ->where('to_address', $client->email)->whereIn('status', ['SENT', 'DELIVERED'])->get();
                    $systemEmailAlreadySent = $this->isFullOrderDelivery && $accepted->isNotEmpty();
                } else {
                    $failure = new \RuntimeException('The delivery email was not accepted. The notification will be retried.');
                }
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        if ($this->fireAutomation && $this->isFullOrderDelivery) {
            try {
                $context = $automation->buildShootContext($shoot);
                if ($shoot->rep) {
                    $context['rep'] = $shoot->rep;
                }
                $context['system_email_already_sent'] = $systemEmailAlreadySent;
                $context['delivery_notification_full_order'] = true;
                // Updating the successful-notification anchor must not change a
                // replay's identity and duplicate its already accepted channels.
                $context['event_id'] = 'shoot-delivered:'.$shoot->id.':'.($shoot->completed_at?->toIso8601String() ?? 'full');
                $dispatch = $automation->handleEvent('SHOOT_COMPLETED', $context);
                $accepted = $accepted->merge($recorder->acceptedMessages($dispatch['message_ids'] ?? []));
                if ((int) ($dispatch['failed_run_count'] ?? 0) > 0) {
                    $failure = new \RuntimeException('A delivery notification workflow failed. Its unfinished channels will be retried.');
                }
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        if ($failure) {
            $progress->stageFailed($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, 'Delivery notification failed and will be retried.');
            throw $failure;
        }

        if ($accepted->isEmpty()) {
            $progress->stageSkipped($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL,
                (int) ($dispatch['waiting_run_count'] ?? 0) > 0
                    ? 'Notification is waiting for its saved workflow schedule.'
                    : 'No delivery notification was sent. Check the saved rule and recipient contact details.');

            return;
        }

        $clientNotified = $recorder->clientWasNotified($accepted, $client);
        if ($clientNotified && $this->fireAutomation && $this->isFullOrderDelivery) {
            try {
                $recorder->record($shoot, $accepted);
            } catch (\Throwable $e) {
                $progress->stageFailed($this->shootId, FinalizeProgressTracker::STAGE_DELIVERY_EMAIL, 'Client notified; payment reminder scheduling will be retried.');
                throw $e;
            }
        }

        $progress->stageCompleted(
            $this->shootId,
            FinalizeProgressTracker::STAGE_DELIVERY_EMAIL,
            $clientNotified ? 'Client notification accepted for delivery' : 'Notification sent to configured recipients'
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
            'Delivery notification could not complete. Review the automation run and message delivery history.'
        );
    }
}
