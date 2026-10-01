<?php

use App\Models\AutomationRule;
use App\Services\Messaging\AutomationWorkflowConverter;
use App\Services\Messaging\ShootAppointmentReminderSchedule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (AutomationRule::where('scope', 'SYSTEM')->where('trigger_type', 'SHOOT_REMINDER')->get() as $rule) {
            $workflow = $rule->workflow_definition_json;
            $trigger = collect($workflow['nodes'] ?? [])->first(fn ($node) => str_starts_with($node['type'] ?? '', 'trigger.'));
            $saved = array_replace($rule->schedule_json ?? [], $trigger['config']['schedule'] ?? []);
            if (isset($saved['client_email_schedule']) || ($saved['offset'] ?? '-24h') !== '-24h') {
                continue;
            }
            $schedule = $saved + ['client_email_schedule' => ShootAppointmentReminderSchedule::CLIENT_DEFAULTS];
            $rule->schedule_json = $schedule;
            if (is_array($workflow)) {
                foreach ($workflow['nodes'] as &$node) {
                    if (str_starts_with($node['type'] ?? '', 'trigger.')) {
                        $node['config']['schedule'] = $schedule;
                    }
                }
                unset($node);
                $rule->workflow_definition_json = $workflow;
            }
            $rule->entry_trigger_json = app(AutomationWorkflowConverter::class)->getEntryTrigger($rule);
            $rule->save();
        }
    }

    public function down(): void
    {
        // Saved business schedules and later edits are intentionally retained.
    }
};
