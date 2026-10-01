<?php

use App\Models\AutomationRule;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const PREVIOUS = ['reminder_days' => [1, 3, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7];
    private const CURRENT = ['reminder_days' => [1, 2, 4, 7], 'repeat_after_day' => 7, 'repeat_every_days' => 7];

    public function up(): void
    {
        if (! Schema::hasTable('automation_rules')) {
            return;
        }
        foreach (AutomationRule::where('scope', 'SYSTEM')->where('trigger_type', 'SHOOT_PAYMENT_REMINDER')
            ->where('name', 'Shoot Payment Reminder')->get() as $rule) {
            if ($rule->schedule_json != self::PREVIOUS) {
                continue;
            }
            $workflow = $rule->workflow_definition_json;
            $nodes = $workflow['nodes'] ?? [];
            $authored = collect($nodes)->filter(fn ($node) => str_starts_with((string) ($node['type'] ?? ''), 'trigger.'))
                ->contains(fn ($node) => isset($node['config']['schedule']) && $node['config']['schedule'] != self::PREVIOUS);
            if ($authored) {
                continue;
            }
            foreach ($nodes as &$node) {
                if (str_starts_with((string) ($node['type'] ?? ''), 'trigger.') && isset($node['config']['schedule'])) {
                    $node['config']['schedule'] = self::CURRENT;
                }
            }
            unset($node);
            if (is_array($workflow)) {
                $workflow['nodes'] = $nodes;
            }
            // Saved recipients, content and disabled state remain operator-owned.
            $rule->update(['schedule_json' => self::CURRENT, 'workflow_definition_json' => $workflow]);
        }

        if (! Schema::hasTable('payment_reminders')) {
            return;
        }
        // Reconcile only existing series. No historical shoot is enrolled by a
        // deployment, past reminders are never backfilled and sent rows remain.
        Shoot::whereHas('paymentReminders', fn ($query) => $query->where('status', PaymentReminder::STATUS_PENDING))
            ->chunkById(100, function ($shoots): void {
                foreach ($shoots as $shoot) {
                    app(AutomationService::class)->schedulePaymentReminders($shoot);
                    PaymentReminder::where('shoot_id', $shoot->id)->where('status', PaymentReminder::STATUS_PENDING)
                        ->where('scheduled_at', '<', now())->update(['status' => PaymentReminder::STATUS_CANCELLED]);
                }
            });
    }

    public function down(): void
    {
        // Do not restore obsolete billing behavior or replace later operator edits.
    }
};
