<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Message;
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

    /**
     * True when this schedule key was already attempted for the rule.
     *
     * Provider failures (Telnyx 403/409, paused SMS, etc.) must count: otherwise
     * everyMinute / post-09:00 polling re-dispatches forever and storms recipients.
     * Any prior AutomationRun for the key blocks a retry; a leftover outbound
     * Message tagged with the same SCHEDULED_AUTOMATION identity is a second guard
     * for runs that somehow never persisted.
     */
    public function alreadyDispatched(AutomationRule $rule, string $key): bool
    {
        if (AutomationRun::query()
            ->where('automation_rule_id', $rule->id)
            ->where('context_json->schedule_dispatch_key', $key)
            ->exists()) {
            return true;
        }

        $tag = 'SCHEDULED_AUTOMATION:'.$rule->id.':'.$key;

        return Message::query()
            ->where(function ($query) use ($tag) {
                $query->whereJsonContains('tags_json', $tag)
                    ->orWhere('tags_json', 'like', '%'.$tag.'%');
            })
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
