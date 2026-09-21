<?php

namespace App\Services\TelnyxAi;

use App\Models\Invoice;
use App\Models\ScheduledVoiceCall;
use App\Models\Shoot;
use App\Models\User;
use App\Models\VoiceAutomationRun;
use App\Models\VoiceCall;
use App\Services\Voice\VoiceAutomationService;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ScheduledVoiceCallService
{
    public function __construct(private readonly VoiceSettingsService $settings, private readonly VoiceAutomationService $automations) {}

    public function createCallbackForCall(VoiceCall $voiceCall, string $reason, ?CarbonImmutable $preferredAt = null): ?ScheduledVoiceCall
    {
        if ($this->hasCustomRuleForReason($reason)) {
            return $this->automations->forCall($voiceCall, $this->automationTypeFor($reason), $reason);
        }
        $settings = $this->settings->all();
        $scheduledAt = $preferredAt ?: CarbonImmutable::now()->addMinutes((int) ($settings['callback_retry_delay_minutes'] ?? 60));

        return LockedWrite::run(fn () => DB::transaction(function () use ($voiceCall, $reason, $settings, $scheduledAt): ScheduledVoiceCall {
            $voiceCall = VoiceCall::query()->lockForUpdate()->findOrFail($voiceCall->id);
            $outbound = strtoupper((string) $voiceCall->direction) === 'OUTBOUND';
            $target = $outbound ? $voiceCall->to_phone : $voiceCall->from_phone;
            $from = $outbound ? $voiceCall->from_phone : $voiceCall->to_phone;

            $scheduled = ScheduledVoiceCall::query()
                ->where('original_voice_call_id', $voiceCall->id)
                ->where('reason', $reason)
                ->whereIn('status', [
                    ScheduledVoiceCall::STATUS_SCHEDULED,
                    ScheduledVoiceCall::STATUS_DEFERRED,
                    ScheduledVoiceCall::STATUS_DIALING,
                    ScheduledVoiceCall::STATUS_FAILED,
                ])->first();

            $scheduled ??= ScheduledVoiceCall::query()->create([
                'original_voice_call_id' => $voiceCall->id,
                'reason' => $reason,
                'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
                'automation_type' => $this->automationTypeFor($reason),
                'target_phone' => (string) $target,
                'from_phone' => $from,
                'caller_user_id' => $voiceCall->caller_user_id,
                'caller_contact_id' => $voiceCall->caller_contact_id,
                'related_shoot_id' => $voiceCall->related_shoot_id,
                'scheduled_at' => $scheduledAt,
                'next_attempt_at' => $scheduledAt,
                'max_attempts' => (int) ($settings['callback_max_attempts'] ?? 3),
                'quiet_hours' => $settings['quiet_hours'] ?? null,
                'summary' => $voiceCall->summary,
                'metadata' => [
                    'source' => 'voice_call',
                    'voice_call_id' => $voiceCall->id,
                    'transcript_excerpt' => $voiceCall->transcript ? mb_substr($voiceCall->transcript, 0, 500) : null,
                ],
            ]);

            $voiceCall->forceFill([
                'callback_status' => $scheduled->status,
                'callback_requested_at' => $voiceCall->callback_requested_at ?: now(),
                'preferred_callback_at' => $scheduled->scheduled_at,
                'scheduled_voice_call_id' => $scheduled->id,
                'disposition' => $voiceCall->disposition ?: 'callback_needed',
                'metadata' => array_merge($voiceCall->metadata ?? [], [
                    'callback_reason' => $reason,
                    'scheduled_voice_call_id' => $scheduled->id,
                ]),
            ])->save();

            return $scheduled;
        }), 'voice-callback-create');
    }

    public function quietHours(): array
    {
        return $this->settings->all()['quiet_hours'] ?? [];
    }

    public function hasCustomRuleForReason(string $reason): bool
    {
        return in_array($reason, ['missed_call', 'transfer_failed'], true)
            && $this->automations->ownsTrigger($this->automationTypeFor($reason));
    }

    public function retryDelayMinutes(?ScheduledVoiceCall $scheduled = null): int
    {
        return max(1, (int) ($scheduled?->metadata['retry_delay_minutes'] ?? $this->settings->all()['callback_retry_delay_minutes'] ?? 60));
    }

    /** Reconcile only the current attempt, including a webhook that beats the dial response. */
    public function syncResult(VoiceCall $call): void
    {
        $callStatus = strtolower((string) $call->status);
        if ($callStatus === 'missed' && $call->ended_at && ! $call->answered_at
            && strtoupper((string) $call->direction) === 'INBOUND'
            && empty($call->metadata['dynamic_variables']['scheduled_voice_call_id'])
            && $this->hasCustomRuleForReason('missed_call')) {
            // Telnyx can report an unanswered leg solely as call.hangup. The
            // same source key deduplicates it against a later no-answer event.
            $this->automations->forCall($call, 'missed_call_callback', 'missed_call');
        }
        $completed = $call->answered_at !== null
            && ! in_array($callStatus, ['failed', 'missed', 'cancelled'], true)
            && ($call->ended_at !== null || in_array($callStatus, ['completed', 'ended'], true));
        if (! $completed && ! in_array($callStatus, ['completed', 'ended', 'failed', 'missed', 'cancelled'], true)) {
            return;
        }

        $variables = is_array($call->metadata['dynamic_variables'] ?? null) ? $call->metadata['dynamic_variables'] : [];
        $scheduledId = $variables['scheduled_voice_call_id'] ?? null;
        LockedWrite::run(fn () => DB::transaction(function () use ($call, $variables, $scheduledId, $completed): void {
            $scheduled = ScheduledVoiceCall::query()
                ->whereIn('status', $completed
                    ? [ScheduledVoiceCall::STATUS_DIALING, ScheduledVoiceCall::STATUS_FAILED, ScheduledVoiceCall::STATUS_EXHAUSTED]
                    : [ScheduledVoiceCall::STATUS_DIALING])
                ->where(function (Builder $query) use ($call, $scheduledId): void {
                    $query->where('result_voice_call_id', $call->id);
                    if ($scheduledId) {
                        $query->orWhere(function (Builder $query) use ($scheduledId): void {
                            $query->whereKey($scheduledId)->whereNull('result_voice_call_id');
                        });
                    }
                })->lockForUpdate()->first();

            if (! $scheduled) {
                return;
            }
            if (! $scheduled->result_voice_call_id && (
                empty($variables['scheduled_call_attempt_token'])
                || ($scheduled->metadata['dial_attempt_token'] ?? null) !== $variables['scheduled_call_attempt_token']
            )) {
                return;
            }

            $exhausted = (int) $scheduled->attempts >= (int) $scheduled->max_attempts;
            $status = $completed ? ScheduledVoiceCall::STATUS_COMPLETED
                : ($exhausted ? ScheduledVoiceCall::STATUS_EXHAUSTED : ScheduledVoiceCall::STATUS_FAILED);
            $scheduled->forceFill([
                'status' => $status,
                'result_voice_call_id' => $call->id,
                'completed_at' => $completed ? ($call->ended_at ?: now()) : null,
                'next_attempt_at' => $completed || $exhausted ? null : now()->addMinutes($this->retryDelayMinutes($scheduled)),
                'last_error' => $completed ? null : ($call->carrier_failure_reason ?: 'The callback ended without a completed, answered conversation.'),
            ])->save();

            VoiceCall::query()->whereKey($scheduled->original_voice_call_id)
                ->where('scheduled_voice_call_id', $scheduled->id)
                ->update(['callback_status' => $status]);
        }), 'voice-callback-result');
    }

    public function automationEnabled(string $automationType): bool
    {
        $toggles = $this->settings->all()['automation_toggles'] ?? [];

        return ! $this->automations->ownsTrigger($automationType) && (bool) ($toggles[$automationType] ?? false);
    }

    public function scheduledAutomationEnabled(ScheduledVoiceCall $scheduled): bool
    {
        return $this->automations->enabledForScheduled($scheduled);
    }

    /** Recheck the live source immediately before a delayed/retried outbound claim. */
    public function sourceIneligibility(ScheduledVoiceCall $scheduled): ?string
    {
        $trigger = (string) $scheduled->automation_type;
        $run = isset($scheduled->metadata['automation_run_id'])
            ? VoiceAutomationRun::query()->find($scheduled->metadata['automation_run_id']) : null;
        $context = $run?->context ?? [];
        if ($trigger === 'unpaid_invoice_reminder') {
            $invoice = Invoice::query()->find($scheduled->related_invoice_id ?: ($context['related_invoice_id'] ?? null));
            if (! $invoice || $invoice->is_paid || $invoice->balanceDue() <= 0
                || (! $invoice->is_sent && $invoice->status !== Invoice::STATUS_SENT)
                || ($invoice->due_date && $invoice->due_date->toDateString() > now()->addDay()->toDateString())) {
                return 'Invoice is no longer unpaid and due for a reminder.';
            }
            $context['amount_due'] = $invoice->balanceDue();
            $context['days_overdue'] = $invoice->due_date ? max(0, (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay(), false)) : 0;
            $context['known_caller'] = (bool) $invoice->client_id;
        }
        if (in_array($trigger, ['shoot_reminder', 'delivery_follow_up'], true)) {
            $shoot = Shoot::query()->find($scheduled->related_shoot_id ?: ($context['related_shoot_id'] ?? null));
            if (! $shoot || in_array($shoot->workflow_status, [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED], true)) {
                return 'Shoot is no longer eligible for a reminder.';
            }
            if ($trigger === 'shoot_reminder') {
                $scheduledAt = $shoot->scheduled_at
                    ? CarbonImmutable::parse($shoot->scheduled_at)
                    : ($shoot->scheduled_date ? CarbonImmutable::parse($shoot->scheduled_date)->endOfDay() : null);
                if (! $scheduledAt || $scheduledAt->lessThanOrEqualTo(now())) {
                    return 'The shoot is no longer upcoming.';
                }
                $currentTime = (string) ($shoot->scheduled_at ?: $shoot->scheduled_date);
                if (isset($context['source_scheduled_at']) && $context['source_scheduled_at'] !== $currentTime) {
                    return 'The shoot was rescheduled after this reminder was queued.';
                }
            } elseif ($shoot->workflow_status !== Shoot::STATUS_DELIVERED && $shoot->delivery_status !== 'delivered') {
                return 'Media is no longer marked delivered.';
            }
            $context['shoot_status'] = $shoot->workflow_status;
            $context['known_caller'] = (bool) $shoot->client_id;
        }
        if ($run && ($context['voice_call_id'] ?? null)) {
            $call = VoiceCall::query()->find($context['voice_call_id']);
            if (! $call) {
                return 'The source call no longer exists.';
            }
            if ($trigger === 'missed_call_callback' && $call->answered_at && $call->ended_at) {
                return 'The source call has since been confirmed answered.';
            }
            if ($trigger === 'failed_transfer_callback' && ($call->status === 'transferred' || $call->disposition === 'transferred')) {
                return 'The source call has since been transferred successfully.';
            }
            $context['known_caller'] = (bool) ($call->caller_user_id || $call->caller_contact_id);
            $context['direction'] = strtoupper((string) $call->direction);
            $context['intent'] = $call->intent;
        }
        foreach ($run?->rule_snapshot['conditions'] ?? [] as $condition) {
            $actual = $context[$condition['field']] ?? null;
            $matched = $actual !== null && match ($condition['operator']) {
                'eq' => $actual === $condition['value'],
                'gte' => is_numeric($actual) && $actual >= $condition['value'],
                'lte' => is_numeric($actual) && $actual <= $condition['value'],
            };
            if (! $matched) {
                return 'The source no longer matches the saved rule conditions.';
            }
        }

        return null;
    }

    public function createDueProactiveCalls(): array
    {
        return [
            'shoot_reminder' => $this->createShootReminderCalls(),
            'delivery_follow_up' => $this->createDeliveryFollowUpCalls(),
            'unpaid_invoice_reminder' => $this->createUnpaidInvoiceReminderCalls(),
        ];
    }

    private function createShootReminderCalls(): int
    {
        if (! $this->automationEnabled('shoot_reminder') && ! $this->automations->ownsTrigger('shoot_reminder')) {
            return 0;
        }

        $from = now()->addHours(23);
        $to = now()->addHours(25);
        $tomorrow = now()->addDay()->toDateString();
        $created = 0;

        Shoot::query()
            ->with('client:id,name,phone,phonenumber')
            ->where(function (Builder $query) use ($from, $to, $tomorrow): void {
                $query->whereBetween('scheduled_at', [$from, $to])
                    ->orWhereDate('scheduled_date', $tomorrow);
            })
            ->whereNotIn('workflow_status', [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED])
            ->lazyById(100)
            ->each(function (Shoot $shoot) use (&$created): void {
                $created += $this->createProactiveCall(
                    'shoot_reminder',
                    'shoot_reminder',
                    $this->userPhone($shoot->client),
                    "Reminder call for shoot #{$shoot->id}",
                    ['related_shoot_id' => $shoot->id, 'caller_user_id' => $shoot->client_id],
                ) ? 1 : 0;
            });

        return $created;
    }

    private function createDeliveryFollowUpCalls(): int
    {
        if (! $this->automationEnabled('delivery_follow_up') && ! $this->automations->ownsTrigger('delivery_follow_up')) {
            return 0;
        }

        $created = 0;
        Shoot::query()
            ->with('client:id,name,phone,phonenumber')
            ->where(function (Builder $query): void {
                $query->where('workflow_status', Shoot::STATUS_DELIVERED)
                    ->orWhere('delivery_status', 'delivered');
            })
            ->where('completed_at', '>=', now()->subDays(2))
            ->where('completed_at', '<=', now()->subHour())
            ->lazyById(100)
            ->each(function (Shoot $shoot) use (&$created): void {
                $created += $this->createProactiveCall(
                    'delivery_follow_up',
                    'delivery_follow_up',
                    $this->userPhone($shoot->client),
                    "Delivery follow-up call for shoot #{$shoot->id}",
                    ['related_shoot_id' => $shoot->id, 'caller_user_id' => $shoot->client_id],
                ) ? 1 : 0;
            });

        return $created;
    }

    private function createUnpaidInvoiceReminderCalls(): int
    {
        if (! $this->automationEnabled('unpaid_invoice_reminder') && ! $this->automations->ownsTrigger('unpaid_invoice_reminder')) {
            return 0;
        }

        $created = 0;
        Invoice::query()
            ->with('client:id,name,phone,phonenumber')
            ->where('role', Invoice::ROLE_CLIENT)
            ->where(function (Builder $query): void {
                $query->where('status', Invoice::STATUS_SENT)->orWhere('is_sent', true);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('due_date')->orWhereDate('due_date', '<=', now()->addDay()->toDateString());
            })
            ->lazyById(100)
            ->filter(fn (Invoice $invoice): bool => ! $invoice->is_paid && $invoice->balanceDue() > 0)
            ->each(function (Invoice $invoice) use (&$created): void {
                $created += $this->createProactiveCall(
                    'unpaid_invoice_reminder',
                    'unpaid_invoice_reminder',
                    $this->userPhone($invoice->client),
                    "Payment reminder call for invoice {$invoice->invoice_number}",
                    ['related_invoice_id' => $invoice->id, 'caller_user_id' => $invoice->client_id],
                ) ? 1 : 0;
            });

        return $created;
    }

    private function createProactiveCall(string $automationType, string $reason, ?string $targetPhone, string $summary, array $attributes): ?ScheduledVoiceCall
    {
        if ($this->automations->ownsTrigger($automationType)) {
            $shoot = isset($attributes['related_shoot_id']) ? Shoot::query()->find($attributes['related_shoot_id']) : null;
            $invoice = isset($attributes['related_invoice_id']) ? Invoice::query()->find($attributes['related_invoice_id']) : null;
            $sourceKey = $invoice ? 'invoice:'.$invoice->id.':'.($invoice->due_date?->toDateString() ?? 'no-due-date')
                : 'shoot:'.$shoot?->id.':'.($automationType === 'delivery_follow_up' ? $shoot?->completed_at : ($shoot?->scheduled_at ?: $shoot?->scheduled_date));
            $runs = $this->automations->evaluate($automationType, $sourceKey, array_merge($attributes, [
                'target_phone' => $targetPhone, 'summary' => $summary, 'reason' => $reason,
                'known_caller' => (bool) ($attributes['caller_user_id'] ?? null),
                'source_scheduled_at' => $shoot ? (string) ($shoot->scheduled_at ?: $shoot->scheduled_date) : null,
                'shoot_status' => $shoot?->workflow_status,
                'amount_due' => $invoice?->balanceDue(),
                'days_overdue' => $invoice?->due_date ? max(0, (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay(), false)) : 0,
            ]));
            foreach ($runs as $run) {
                if ($run->wasRecentlyCreated && $run->scheduled_voice_call_id) {
                    return $run->scheduledCall;
                }
            }

            return null;
        }
        if (! $targetPhone) {
            return null;
        }

        $identity = array_filter([
            'automation_type' => $automationType,
            'related_shoot_id' => $attributes['related_shoot_id'] ?? null,
            'related_invoice_id' => $attributes['related_invoice_id'] ?? null,
            'reason' => $reason,
        ], fn ($value) => $value !== null);

        $settings = $this->settings->all();

        return LockedWrite::run(fn () => DB::transaction(function () use ($identity, $attributes, $automationType, $reason, $targetPhone, $summary, $settings): ?ScheduledVoiceCall {
            $exists = ScheduledVoiceCall::query()
                ->where($identity)
                ->whereNotIn('status', [ScheduledVoiceCall::STATUS_CANCELLED, ScheduledVoiceCall::STATUS_EXHAUSTED])
                ->exists();

            if ($exists) {
                return null;
            }

            return ScheduledVoiceCall::query()->create(array_merge($attributes, [
                'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
                'automation_type' => $automationType,
                'reason' => $reason,
                'target_phone' => $targetPhone,
                'scheduled_at' => now(),
                'next_attempt_at' => now(),
                'max_attempts' => (int) ($settings['callback_max_attempts'] ?? 3),
                'quiet_hours' => $settings['quiet_hours'] ?? null,
                'summary' => $summary,
                'metadata' => ['source' => 'voice_proactive_automation'],
            ]));
        }), 'voice-proactive-callback-create');
    }

    private function userPhone(?User $user): ?string
    {
        return $user?->phone ?: $user?->phonenumber;
    }

    private function automationTypeFor(string $reason): string
    {
        return match ($reason) {
            'missed_call' => 'missed_call_callback',
            'transfer_failed' => 'failed_transfer_callback',
            default => 'missed_call_callback',
        };
    }
}
