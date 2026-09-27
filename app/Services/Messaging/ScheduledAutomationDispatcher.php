<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use Carbon\Carbon;

/** Shared eligibility and deduplication for database-backed schedules. */
class ScheduledAutomationDispatcher
{
    public function dailyTimeReached(AutomationRule $rule, Carbon $now, string $defaultTime): bool
    {
        $time = (string) ($rule->schedule_json['time'] ?? $defaultTime);
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return false;
        }

        return $now->format('H:i') >= $time;
    }

    public function alreadyDispatched(AutomationRule $rule, string $key): bool
    {
        return AutomationRun::query()
            ->where('automation_rule_id', $rule->id)
            ->where('context_json->schedule_dispatch_key', $key)
            ->whereIn('status', ['completed', 'waiting', 'running'])
            ->exists();
    }

    public function dispatch(AutomationRule $rule, array $context, string $key): bool
    {
        if (! $rule->is_active || $this->alreadyDispatched($rule, $key)) {
            return false;
        }

        $context['automation_rule_id'] = $rule->id;
        $context['schedule_dispatch_key'] = $key;
        $context['tags_json'] = array_values(array_unique(array_merge(
            (array) ($context['tags_json'] ?? []),
            ['SCHEDULED_AUTOMATION:'.$rule->id.':'.$key],
        )));

        $result = app(AutomationService::class)->handleEvent($rule->trigger_type, $context);

        return (bool) ($result['handled'] ?? false);
    }
}
