<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Illuminate\Console\Command;

/**
 * Rolling-horizon sweep for the continuous payment-reminder cadence (Req 4.6).
 *
 * The monthly (last-Sunday) phase has no fixed end: an unpaid shoot keeps receiving one reminder
 * per month for as long as it stays unpaid. {@see AutomationService::schedulePaymentReminders()}
 * only materializes a bounded look-ahead window from "now", so this command re-runs scheduling for
 * every unpaid, ready-notified shoot on a cadence SHORTER than that window (registered weekly in
 * the Console Kernel). Each run rolls the window forward, so the next last-Sunday reminder is
 * always materialized before it becomes due.
 *
 * Idempotency and stop-on-paid are guaranteed by schedulePaymentReminders() itself: the
 * (shoot_id, scheduled_date) upsert prevents duplicate rows, and a paid shoot early-returns after
 * cancelling its pending reminders. The command is therefore safe to run repeatedly.
 */
class PaymentRemindersSweep extends Command
{
    protected $signature = 'messaging:payment-reminders-sweep';

    protected $description = 'Roll the payment-reminder cadence forward for every unpaid, ready-notified shoot';

    public function handle(AutomationService $automationService): int
    {
        $this->info('Sweeping payment reminders for unpaid, ready-notified shoots...');

        $processed = 0;

        try {
            Shoot::query()
                ->whereNotNull('shoot_ready_notified_at')
                // Unpaid shoots only (case-insensitive); NULL/empty payment_status counts as unpaid.
                // schedulePaymentReminders() re-checks paid status, so a slip-through is still safe.
                ->whereRaw("LOWER(COALESCE(payment_status, '')) != ?", ['paid'])
                ->chunkById(200, function ($shoots) use ($automationService, &$processed) {
                    foreach ($shoots as $shoot) {
                        $automationService->schedulePaymentReminders($shoot);
                        $processed++;
                    }
                });

            $this->info("Payment reminder sweep complete. Processed {$processed} shoot(s).");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Payment reminder sweep failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
