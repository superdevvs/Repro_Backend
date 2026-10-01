<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\PaymentReminder;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DispatchScheduledMessages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 30;

    public function handle(
        MessagingService $messaging,
        AutomationWorkflowExecutor $workflowExecutor,
        AutomationService $automation,
    ): void {
        Message::query()
            ->where('status', 'SCHEDULED')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(50, function ($messages) use ($messaging) {
                foreach ($messages as $message) {
                    $messaging->dispatchScheduledMessage($message);
                }
            });

        $this->dispatchDuePaymentReminders($automation);

        $workflowExecutor->resumeDueSteps();
    }

    /**
     * Dispatch every Payment_Reminder whose scheduled time has arrived (Req 12.14, 12.15).
     *
     * Only `pending` reminders are considered, so a reminder already marked `sent`/`cancelled`
     * is never picked up again — this is the no-duplicate guard per `(shoot_id, scheduled_date)`
     * (Req 12.15). A per-reminder cache lock serializes delivery without holding a SQLite
     * write transaction while an external provider responds.
     */
    private function dispatchDuePaymentReminders(AutomationService $automation): void
    {
        PaymentReminder::query()
            ->where('status', PaymentReminder::STATUS_PENDING)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(50, function ($reminders) use ($automation) {
                foreach ($reminders as $reminder) {
                    $this->dispatchPaymentReminder($reminder, $automation);
                }
            });
    }

    /**
     * Send one due Payment_Reminder, guarding against duplicates and post-payment races.
     *
     * The row is re-read under a lock so that:
     *   - a reminder already moved out of `pending` by a concurrent run is skipped (no duplicate
     *     send for the same shoot/date, Req 12.15); and
     *   - the shoot's payment status is re-checked at send time — if it is now paid, the reminder
     *     (and any sibling pending reminders) are cancelled rather than sent, so a payment that
     *     landed after scheduling cannot trigger a stale reminder (Req 12.14).
     */
    private function dispatchPaymentReminder(PaymentReminder $reminder, AutomationService $automation): void
    {
        Cache::lock('payment-reminder:'.$reminder->getKey(), 300)->get(function () use ($reminder, $automation) {
            $locked = PaymentReminder::query()
                ->whereKey($reminder->getKey())
                ->first();

            // No-duplicate guard: a concurrent run may have already sent or cancelled this row.
            if ($locked === null || $locked->status !== PaymentReminder::STATUS_PENDING) {
                return;
            }

            $shoot = $locked->shoot;
            if ($shoot === null) {
                $locked->forceFill(['status' => PaymentReminder::STATUS_CANCELLED])->save();

                return;
            }

            // Stop-on-paid race guard (Req 12.14): cancel instead of sending a stale reminder.
            if ($shoot->suppressesExternalNotifications() || $automation->shootPaymentIsComplete($shoot)) {
                $automation->cancelPaymentReminders($shoot);

                return;
            }

            if (! $automation->paymentReminderIsCurrent($locked)) {
                return;
            }

            try {
                $message = $automation->sendPaymentReminder($shoot);
            } catch (\Throwable $exception) {
                Log::error('Failed to dispatch scheduled payment reminder', [
                    'payment_reminder_id' => $locked->id,
                    'shoot_id' => $shoot->id,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            if ($message?->status === 'BLOCKED' && data_get($message->metadata, 'delivery.blocked_reason') === 'notification_preferences') {
                $locked->forceFill([
                    'status' => PaymentReminder::STATUS_CANCELLED,
                    'message_id' => $message->id,
                ])->save();

                return;
            }

            // A disabled action, suppressed recipient or provider failure is not a send.
            if (! $message || ! in_array(strtoupper((string) $message->status), ['SENT', 'DELIVERED', 'QUEUED', 'SCHEDULED'], true)) {
                return;
            }

            // Record the send and link the Message so the (shoot_id, scheduled_date) row is never
            // re-sent on a subsequent run (Req 12.15).
            PaymentReminder::whereKey($locked->id)->where('status', PaymentReminder::STATUS_PENDING)->update([
                'status' => PaymentReminder::STATUS_SENT,
                'sent_at' => now(),
                'message_id' => $message->id,
            ]);
        });
    }
}
