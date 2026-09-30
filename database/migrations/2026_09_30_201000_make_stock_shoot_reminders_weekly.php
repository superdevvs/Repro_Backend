<?php

use App\Models\AutomationRule;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\PaymentReminderScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STOCK_CADENCE = [
        'reminder_days' => [1, 3, 7, 14, 21, 28],
        'monthly_day_of_week' => 0,
        'time' => '09:00',
    ];

    private const WEEKLY_CADENCE = [
        'reminder_days' => [1, 3, 7],
        'repeat_after_day' => 7,
        'repeat_every_days' => 7,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('automation_rules')) {
            return;
        }

        $upgraded = false;
        foreach (AutomationRule::query()
            ->where('scope', 'SYSTEM')
            ->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')
            ->where('name', 'Shoot Payment Reminder')
            ->get() as $rule) {
            if (! $this->isStockCadence($rule->schedule_json)) {
                continue;
            }

            $workflow = $rule->workflow_definition_json;
            if (is_array($workflow) && is_array($workflow['nodes'] ?? null)) {
                $hasAuthoredVisualSchedule = collect($workflow['nodes'])
                    ->filter(fn ($node) => str_starts_with((string) ($node['type'] ?? ''), 'trigger.'))
                    ->contains(fn ($node) => isset($node['config']['schedule'])
                        && ! $this->isStockCadence($node['config']['schedule']));
                if ($hasAuthoredVisualSchedule) {
                    continue;
                }
                foreach ($workflow['nodes'] as &$node) {
                    if (str_starts_with((string) ($node['type'] ?? ''), 'trigger.')
                        && $this->isStockCadence($node['config']['schedule'] ?? null)) {
                        $node['config']['schedule'] = self::WEEKLY_CADENCE;
                    }
                }
                unset($node);
            }

            $rule->update([
                'schedule_json' => self::WEEKLY_CADENCE,
                'workflow_definition_json' => $workflow,
            ]);
            $upgraded = true;
        }

        if (! $upgraded || ! Schema::hasTable('payment_reminders')
            || AutomationRule::active()->forTrigger('SHOOT_PAYMENT_REMINDER')->first()?->schedule_json !== self::WEEKLY_CADENCE) {
            return;
        }

        // Reconcile already queued stock rows during migration, then populate
        // the next rolling window on the new weekly cadence.
        $scheduler = new PaymentReminderScheduler;
        Shoot::query()->whereNotNull('shoot_ready_notified_at')
            ->whereNotIn('payment_status', ['paid', Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED])
            ->whereHas('paymentReminders', fn ($query) => $query->where('status', PaymentReminder::STATUS_PENDING))
            ->chunkById(100, function ($shoots) use ($scheduler): void {
                foreach ($shoots as $shoot) {
                    $pending = PaymentReminder::query()
                        ->where('shoot_id', $shoot->id)
                        ->where('status', PaymentReminder::STATUS_PENDING)
                        ->get();
                    $anchor = CarbonImmutable::parse($shoot->shoot_ready_notified_at);
                    $lastPending = $pending->max(fn (PaymentReminder $row) => $row->scheduled_at?->getTimestamp() ?? 0);
                    $horizon = CarbonImmutable::createFromTimestamp(max(
                        (int) $lastPending,
                        CarbonImmutable::now()->addMonths(3)->getTimestamp()
                    ));
                    $desiredDates = collect($scheduler->schedule($anchor, $horizon, self::WEEKLY_CADENCE))
                        ->mapWithKeys(fn (CarbonImmutable $at) => [$at->toDateString() => true]);
                    foreach ($pending as $row) {
                        if (! $desiredDates->has($row->scheduled_date->toDateString())) {
                            $row->update(['status' => PaymentReminder::STATUS_CANCELLED]);
                        }
                    }
                    app(AutomationService::class)->schedulePaymentReminders($shoot);
                }
            });
    }

    public function down(): void
    {
        // Restoring a prior cadence would overwrite subsequent operator edits.
    }

    private function isStockCadence(mixed $schedule): bool
    {
        return is_array($schedule)
            && count($schedule) === count(self::STOCK_CADENCE)
            && ($schedule['reminder_days'] ?? null) === self::STOCK_CADENCE['reminder_days']
            && ($schedule['monthly_day_of_week'] ?? null) === self::STOCK_CADENCE['monthly_day_of_week']
            && ($schedule['time'] ?? null) === self::STOCK_CADENCE['time'];
    }
};
