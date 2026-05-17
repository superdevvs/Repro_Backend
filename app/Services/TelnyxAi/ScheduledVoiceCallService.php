<?php

namespace App\Services\TelnyxAi;

use App\Models\ScheduledVoiceCall;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Models\VoiceCall;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ScheduledVoiceCallService
{
    public function __construct(private readonly VoiceSettingsService $settings)
    {
    }

    public function createCallbackForCall(VoiceCall $voiceCall, string $reason, ?CarbonImmutable $preferredAt = null): ScheduledVoiceCall
    {
        $target = $voiceCall->from_phone ?: $voiceCall->to_phone;
        $settings = $this->settings->all();
        $scheduledAt = $preferredAt ?: CarbonImmutable::now()->addMinutes((int) ($settings['callback_retry_delay_minutes'] ?? 60));

        $scheduled = ScheduledVoiceCall::query()->firstOrCreate(
            [
                'original_voice_call_id' => $voiceCall->id,
                'reason' => $reason,
                'status' => ScheduledVoiceCall::STATUS_SCHEDULED,
            ],
            [
                'automation_type' => $this->automationTypeFor($reason),
                'target_phone' => (string) $target,
                'from_phone' => $voiceCall->to_phone,
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
            ]
        );

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
    }

    public function automationEnabled(string $automationType): bool
    {
        $toggles = $this->settings->all()['automation_toggles'] ?? [];

        return (bool) ($toggles[$automationType] ?? false);
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
        if (!$this->automationEnabled('shoot_reminder')) {
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
            ->limit(100)
            ->get()
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
        if (!$this->automationEnabled('delivery_follow_up')) {
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
            ->limit(100)
            ->get()
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
        if (!$this->automationEnabled('unpaid_invoice_reminder')) {
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
            ->limit(100)
            ->get()
            ->filter(fn (Invoice $invoice): bool => !$invoice->is_paid && $invoice->balanceDue() > 0)
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
        if (!$targetPhone) {
            return null;
        }

        $identity = array_filter([
            'automation_type' => $automationType,
            'related_shoot_id' => $attributes['related_shoot_id'] ?? null,
            'related_invoice_id' => $attributes['related_invoice_id'] ?? null,
            'reason' => $reason,
        ], fn ($value) => $value !== null);

        $exists = ScheduledVoiceCall::query()
            ->where($identity)
            ->whereNotIn('status', [ScheduledVoiceCall::STATUS_CANCELLED, ScheduledVoiceCall::STATUS_EXHAUSTED])
            ->exists();

        if ($exists) {
            return null;
        }

        $settings = $this->settings->all();

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
