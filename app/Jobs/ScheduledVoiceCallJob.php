<?php

namespace App\Jobs;

use App\Models\ScheduledVoiceCall;
use App\Models\User;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use App\Services\TelnyxAi\TelnyxVoiceCallService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScheduledVoiceCallJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $scheduledVoiceCallId)
    {
    }

    public function handle(TelnyxVoiceCallService $calls, ScheduledVoiceCallService $scheduledCalls): void
    {
        $scheduled = ScheduledVoiceCall::query()->find($this->scheduledVoiceCallId);
        if (!$scheduled || in_array($scheduled->status, [ScheduledVoiceCall::STATUS_CANCELLED, ScheduledVoiceCall::STATUS_COMPLETED, ScheduledVoiceCall::STATUS_EXHAUSTED], true)) {
            return;
        }

        if ($scheduled->automation_type && !$scheduledCalls->automationEnabled($scheduled->automation_type)) {
            $scheduled->forceFill([
                'status' => ScheduledVoiceCall::STATUS_FAILED,
                'last_error' => 'Automation is disabled.',
            ])->save();
            return;
        }

        $deferredUntil = $this->deferredUntil($scheduled);
        if ($deferredUntil) {
            $scheduled->forceFill([
                'status' => ScheduledVoiceCall::STATUS_DEFERRED,
                'next_attempt_at' => $deferredUntil,
                'metadata' => array_merge($scheduled->metadata ?? [], ['quiet_hours_deferred_at' => now()->toIso8601String()]),
            ])->save();
            return;
        }

        try {
            $scheduled->increment('attempts');
            $scheduled->forceFill([
                'status' => ScheduledVoiceCall::STATUS_DIALING,
                'last_attempt_at' => now(),
                'last_error' => null,
            ])->save();

            $voiceCall = $calls->dial([
                'to' => $scheduled->target_phone,
                'from' => $scheduled->from_phone,
                'related_shoot_id' => $scheduled->related_shoot_id,
                'dynamic_variables' => array_merge($scheduled->metadata ?? [], [
                    'scheduled_voice_call_id' => $scheduled->id,
                    'scheduled_call_reason' => $scheduled->reason,
                    'automation_type' => $scheduled->automation_type,
                ]),
            ], $scheduled->created_by_user_id ?: $this->fallbackUserId());

            $scheduled->forceFill([
                'status' => ScheduledVoiceCall::STATUS_COMPLETED,
                'result_voice_call_id' => $voiceCall->id,
                'completed_at' => now(),
                'summary' => $scheduled->summary ?: 'Outbound call placed.',
            ])->save();
        } catch (Throwable $exception) {
            $exhausted = ((int) $scheduled->attempts) >= ((int) $scheduled->max_attempts);
            $scheduled->forceFill([
                'status' => $exhausted ? ScheduledVoiceCall::STATUS_EXHAUSTED : ScheduledVoiceCall::STATUS_FAILED,
                'next_attempt_at' => $exhausted ? null : now()->addHour(),
                'last_error' => $exception->getMessage(),
            ])->save();
        }
    }

    private function deferredUntil(ScheduledVoiceCall $scheduled): ?CarbonImmutable
    {
        $quiet = $scheduled->quiet_hours ?? [];
        if (!($quiet['enabled'] ?? false)) {
            return null;
        }

        $timezone = (string) ($quiet['timezone'] ?? config('app.timezone', 'UTC'));
        $start = (string) ($quiet['start'] ?? '20:00');
        $end = (string) ($quiet['end'] ?? '08:00');
        $now = CarbonImmutable::now($timezone);
        $startAt = CarbonImmutable::parse($now->toDateString() . ' ' . $start, $timezone);
        $endAt = CarbonImmutable::parse($now->toDateString() . ' ' . $end, $timezone);

        $inside = $startAt->lessThan($endAt)
            ? $now->betweenIncluded($startAt, $endAt)
            : ($now->greaterThanOrEqualTo($startAt) || $now->lessThan($endAt));

        if (!$inside) {
            return null;
        }

        if ($startAt->greaterThan($endAt) && $now->greaterThanOrEqualTo($startAt)) {
            $endAt = $endAt->addDay();
        }

        return $endAt->timezone(config('app.timezone', 'UTC'));
    }

    private function fallbackUserId(): int
    {
        return (int) User::query()
            ->whereIn('role', ['superadmin', 'admin'])
            ->orderBy('id')
            ->value('id');
    }
}
