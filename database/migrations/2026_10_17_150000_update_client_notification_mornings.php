<?php

use App\Models\AutomationRule;
use App\Services\Messaging\AutomationWorkflowConverter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (AutomationRule::where('trigger_type', 'SHOOT_REMINDER')->get() as $rule) {
            $schedule = $rule->schedule_json ?? [];
            $workflow = $rule->workflow_definition_json;
            $rule->schedule_json = $this->updateSchedule($schedule);
            if (is_array($workflow)) {
                foreach ($workflow['nodes'] as &$node) {
                    if (str_starts_with($node['type'] ?? '', 'trigger.') && is_array($node['config']['schedule'] ?? null)) {
                        $node['config']['schedule'] = $this->updateSchedule($node['config']['schedule']);
                    }
                }
                unset($node);
                $rule->workflow_definition_json = $workflow;
            }
            $rule->entry_trigger_json = app(AutomationWorkflowConverter::class)->getEntryTrigger($rule);
            $rule->save();
        }
    }

    private function updateSchedule(array $schedule): array
    {
        if (is_array($schedule['client_email_schedule'] ?? null)) {
            $client = $schedule['client_email_schedule'];
            $client['previous_day_time'] = max('09:00', $client['previous_day_time'] ?? '09:00');
            $client['day_of_time'] = max('09:00', $client['day_of_time'] ?? '09:00');
            $client['morning_start'] = '09:00';
            $client['morning_end'] = '11:00';
            $client['morning_lead_minutes'] = 120;
            $schedule['client_email_schedule'] = $client;
        }

        return $schedule;
    }

    public function down(): void
    {
        // Do not restore notification times that violate the current delivery policy.
    }
};
