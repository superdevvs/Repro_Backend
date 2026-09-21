<?php

namespace App\Services\Voice;

use App\Models\ScheduledVoiceCall;
use App\Models\VoiceAutomationRule;
use App\Models\VoiceAutomationRun;
use App\Models\VoiceCall;
use App\Models\VoiceFollowUpTask;
use App\Services\TelnyxAi\VoiceSettingsService;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VoiceAutomationService
{
    public function __construct(private readonly VoiceSettingsService $settings) {}

    /** Archived and disabled custom rules keep ownership; never revive a legacy dial. */
    public function ownsTrigger(string $trigger): bool
    {
        return VoiceAutomationRule::withTrashed()->where('trigger_type', $trigger)->exists();
    }

    public function enabledForScheduled(ScheduledVoiceCall $scheduled): bool
    {
        $ruleId = $scheduled->metadata['automation_rule_id'] ?? null;
        if ($ruleId) {
            return VoiceAutomationRule::query()->whereKey($ruleId)->where('enabled', true)->exists();
        }

        return ! $this->ownsTrigger((string) $scheduled->automation_type)
            && (bool) ($this->settings->all()['automation_toggles'][$scheduled->automation_type] ?? false);
    }

    /** Read-only calculation used by both execution and the fictional sample preview. */
    public function plan(array $rule, array $context, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $checks = [];
        foreach ($rule['conditions'] as $condition) {
            $actual = $context[$condition['field']] ?? null;
            $matches = $actual !== null && match ($condition['operator']) {
                'eq' => $actual === $condition['value'],
                'gte' => is_numeric($actual) && $actual >= $condition['value'],
                'lte' => is_numeric($actual) && $actual <= $condition['value'],
            };
            $checks[] = array_merge($condition, ['actual' => $actual, 'matched' => $matches]);
        }
        $matched = ! in_array(false, array_column($checks, 'matched'), true);
        $reason = ! $rule['enabled'] ? 'This rule is disabled.'
            : (! $matched ? 'One or more conditions did not match.' : null);
        if (! $reason && $rule['action_type'] === 'ai_callback'
            && ! preg_match('/^\+[1-9]\d{7,14}$/', (string) ($context['target_phone'] ?? ''))) {
            $reason = 'An international customer phone number is required for an AI callback.';
        }
        $requested = $now->addMinutes((int) $rule['delay_minutes']);
        $due = $this->outsideQuietHours($requested, [$this->settings->all()['quiet_hours'] ?? [], $rule['quiet_hours']]);
        if (! $reason && ! $due) {
            $reason = 'The rule and global quiet hours leave no shared available time in the next seven days.';
        }

        return [
            'would_run' => $reason === null, 'reason' => $reason, 'checks' => $checks,
            'action_type' => $rule['action_type'], 'requested_at' => $requested->toIso8601String(),
            'scheduled_at' => $due?->toIso8601String(), 'quiet_hours_adjusted' => $due && ! $due->equalTo($requested),
            'max_attempts' => (int) $rule['max_attempts'], 'retry_delay_minutes' => (int) $rule['retry_delay_minutes'],
        ];
    }

    /** Persist the run and its single durable action together, before any worker/provider work. */
    public function evaluate(string $trigger, string $sourceEventKey, array $context): array
    {
        $results = [];
        foreach (VoiceAutomationRule::query()->where('trigger_type', $trigger)->where('enabled', true)->orderBy('id')->get() as $rule) {
            $results[] = LockedWrite::run(fn () => DB::transaction(function () use ($rule, $trigger, $sourceEventKey, $context): ?VoiceAutomationRun {
                $rule = VoiceAutomationRule::query()->lockForUpdate()->find($rule->id);
                if (! $rule || ! $rule->enabled) {
                    return null;
                }
                $existing = VoiceAutomationRun::query()->where('voice_automation_rule_id', $rule->id)
                    ->where('source_event_key', $sourceEventKey)->first();
                if ($existing) {
                    return $existing;
                }
                $definition = VoiceTimezone::normalizeWindows($rule->only(['name', 'trigger_type', 'enabled', 'conditions', 'delay_minutes', 'quiet_hours', 'max_attempts', 'retry_delay_minutes', 'action_type', 'action_config']));
                $plan = $this->plan($definition, $context);
                $run = VoiceAutomationRun::query()->create([
                    'voice_automation_rule_id' => $rule->id, 'source_event_key' => $sourceEventKey,
                    'trigger_type' => $trigger, 'status' => $plan['would_run'] ? 'queued' : 'skipped',
                    'reason' => $plan['reason'], 'rule_snapshot' => $definition, 'context' => $context,
                    'scheduled_at' => $plan['would_run'] ? $plan['scheduled_at'] : null,
                ]);
                if (! $plan['would_run']) {
                    return $run;
                }
                if ($rule->action_type === 'internal_task') {
                    VoiceFollowUpTask::query()->create([
                        'automation_run_id' => $run->id, 'voice_call_id' => $context['voice_call_id'] ?? null,
                        'related_shoot_id' => $context['related_shoot_id'] ?? null,
                        'related_invoice_id' => $context['related_invoice_id'] ?? null,
                        'assigned_to_user_id' => $rule->action_config['assigned_to_user_id'] ?? null,
                        'title' => $rule->action_config['task_title'], 'due_at' => $plan['scheduled_at'], 'status' => 'open',
                    ]);
                    if ($context['voice_call_id'] ?? null) {
                        $call = VoiceCall::query()->lockForUpdate()->find($context['voice_call_id']);
                        $call?->forceFill([
                            'needs_follow_up' => true,
                            'metadata' => array_merge($call->metadata ?? [], ['needs_follow_up' => true]),
                        ])->save();
                    }
                    $run->forceFill(['status' => 'task_open'])->save();
                } else {
                    $scheduled = ScheduledVoiceCall::query()->create([
                        'original_voice_call_id' => $context['voice_call_id'] ?? null,
                        'related_shoot_id' => $context['related_shoot_id'] ?? null,
                        'related_invoice_id' => $context['related_invoice_id'] ?? null,
                        'caller_user_id' => $context['caller_user_id'] ?? null,
                        'caller_contact_id' => $context['caller_contact_id'] ?? null,
                        'created_by_user_id' => $rule->created_by_user_id,
                        'target_phone' => $context['target_phone'], 'from_phone' => $context['from_phone'] ?? null,
                        'status' => ScheduledVoiceCall::STATUS_SCHEDULED, 'automation_type' => $trigger,
                        'reason' => $context['reason'] ?? $trigger, 'summary' => $context['summary'] ?? $rule->name,
                        'scheduled_at' => $plan['scheduled_at'], 'next_attempt_at' => $plan['scheduled_at'],
                        'max_attempts' => $rule->max_attempts, 'quiet_hours' => $definition['quiet_hours'],
                        'metadata' => [
                            'source' => 'voice_automation_rule', 'automation_rule_id' => $rule->id,
                            'automation_run_id' => $run->id, 'retry_delay_minutes' => $rule->retry_delay_minutes,
                        ],
                    ]);
                    $run->forceFill(['scheduled_voice_call_id' => $scheduled->id])->save();
                    if ($context['voice_call_id'] ?? null) {
                        VoiceCall::query()->whereKey($context['voice_call_id'])->update([
                            'scheduled_voice_call_id' => $scheduled->id, 'callback_status' => 'scheduled',
                            'callback_requested_at' => now(), 'preferred_callback_at' => $plan['scheduled_at'],
                        ]);
                    }
                }

                return $run;
            }), 'voice-automation-evaluate');
        }

        return array_values(array_filter($results));
    }

    public function forCall(VoiceCall $call, string $trigger, string $reason): ?ScheduledVoiceCall
    {
        $outbound = strtoupper((string) $call->direction) === 'OUTBOUND';
        $runs = $this->evaluate($trigger, 'call:'.$call->id.':'.$trigger, [
            'voice_call_id' => $call->id, 'related_shoot_id' => $call->related_shoot_id,
            'caller_user_id' => $call->caller_user_id, 'caller_contact_id' => $call->caller_contact_id,
            'known_caller' => (bool) ($call->caller_user_id || $call->caller_contact_id),
            'direction' => strtoupper((string) $call->direction), 'intent' => $call->intent,
            'target_phone' => $outbound ? $call->to_phone : $call->from_phone,
            'from_phone' => $outbound ? $call->from_phone : $call->to_phone,
            'summary' => $call->summary, 'reason' => $reason,
        ]);
        foreach ($runs as $run) {
            if ($run->scheduled_voice_call_id) {
                return $run->scheduledCall;
            }
        }

        return null;
    }

    private function outsideQuietHours(CarbonImmutable $at, array $windows): ?CarbonImmutable
    {
        $limit = $at->addDays(7);
        while ($at->lessThanOrEqualTo($limit)) {
            $before = $at;
            foreach ($windows as $quiet) {
                if (empty($quiet['enabled']) || ($quiet['start'] ?? '20:00') === ($quiet['end'] ?? '08:00')) {
                    continue;
                }
                $local = $at->setTimezone(VoiceTimezone::normalize($quiet['timezone'] ?? 'UTC'));
                $start = $local->setTimeFromTimeString($quiet['start'] ?? '20:00');
                $end = $local->setTimeFromTimeString($quiet['end'] ?? '08:00');
                $inside = $start->lessThan($end)
                    ? ($local->greaterThanOrEqualTo($start) && $local->lessThan($end))
                    : ($local->greaterThanOrEqualTo($start) || $local->lessThan($end));
                if ($inside) {
                    if ($start->greaterThan($end) && $local->greaterThanOrEqualTo($start)) {
                        $end = $end->addDay();
                    }
                    $at = $end->setTimezone('UTC');
                }
            }
            if ($at->equalTo($before)) {
                return $at;
            }
        }

        return null;
    }
}
