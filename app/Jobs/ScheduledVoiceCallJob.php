<?php

namespace App\Jobs;

use App\Models\ScheduledVoiceCall;
use App\Models\User;
use App\Models\VoiceAutomationRun;
use App\Models\VoiceCall;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use App\Services\Voice\VoiceCallService;
use App\Services\Voice\VoiceTimezone;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ScheduledVoiceCallJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $scheduledVoiceCallId) {}

    public function handle(VoiceCallService $calls, ScheduledVoiceCallService $scheduledCalls): void
    {
        $scheduled = $this->claim($scheduledCalls);
        if (! $scheduled) {
            return;
        }

        // The claim is committed before any provider I/O. A duplicate worker sees
        // dialing and cannot place another call, even while this request is slow.
        try {
            $voiceCall = $calls->startOutbound([
                'to' => $scheduled->target_phone,
                'from' => $scheduled->from_phone,
                'related_shoot_id' => $scheduled->related_shoot_id,
                'assistant_mode' => 'robbie_ai',
                'source' => 'scheduled_voice_call',
                'dynamic_variables' => array_merge($scheduled->metadata ?? [], [
                    'scheduled_voice_call_id' => $scheduled->id,
                    'scheduled_call_attempt_token' => $scheduled->metadata['dial_attempt_token'],
                    'scheduled_call_reason' => $scheduled->reason,
                    'automation_type' => $scheduled->automation_type,
                ]),
            ], $scheduled->created_by_user_id ?: $this->fallbackUserId());

        } catch (Throwable $exception) {
            $attemptCall = VoiceCall::query()
                ->where('metadata->dynamic_variables->scheduled_call_attempt_token', $scheduled->metadata['dial_attempt_token'])
                ->latest('id')->first();
            // A timeout may mean the carrier accepted the call. Keep that attempt
            // in flight for its webhook rather than automatically dialing again.
            $knownRejection = preg_match('/^Telnyx dial failed \(4\d{2}\):/', $exception->getMessage()) === 1;
            $uncertain = $attemptCall && (! $knownRejection || $attemptCall->call_control_id);
            $exhausted = ((int) $scheduled->attempts) >= ((int) $scheduled->max_attempts);
            LockedWrite::run(fn () => $this->currentAttempt($scheduled)->update([
                'status' => $uncertain ? ScheduledVoiceCall::STATUS_DIALING
                    : ($exhausted ? ScheduledVoiceCall::STATUS_EXHAUSTED : ScheduledVoiceCall::STATUS_FAILED),
                'result_voice_call_id' => $attemptCall?->id,
                'next_attempt_at' => $uncertain || $exhausted ? null : now()->addMinutes($scheduledCalls->retryDelayMinutes($scheduled)),
                'last_error' => $exception->getMessage(),
            ]), 'voice-callback-dial-error');

            return;
        }

        // Never turn a successful dial into a retry if the result write fails.
        // The original claim remains in flight and webhooks can match its token.
        LockedWrite::run(fn () => $this->currentAttempt($scheduled)->update([
            'result_voice_call_id' => $voiceCall->id,
        ]), 'voice-callback-dial-accepted');
        $scheduledCalls->syncResult($voiceCall->fresh());
    }

    private function claim(ScheduledVoiceCallService $scheduledCalls): ?ScheduledVoiceCall
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($scheduledCalls): ?ScheduledVoiceCall {
            $scheduled = ScheduledVoiceCall::query()->lockForUpdate()->find($this->scheduledVoiceCallId);
            if (! $scheduled || ! in_array($scheduled->status, [ScheduledVoiceCall::STATUS_SCHEDULED, ScheduledVoiceCall::STATUS_DEFERRED, ScheduledVoiceCall::STATUS_FAILED], true)) {
                return null;
            }
            if (! $scheduled->next_attempt_at || $scheduled->next_attempt_at->isFuture() || $scheduled->scheduled_at?->isFuture()) {
                return null;
            }
            if ((int) $scheduled->attempts >= (int) $scheduled->max_attempts) {
                $scheduled->forceFill(['status' => ScheduledVoiceCall::STATUS_EXHAUSTED, 'next_attempt_at' => null])->save();

                return null;
            }
            if ($scheduled->automation_type && ! $scheduledCalls->scheduledAutomationEnabled($scheduled)) {
                $scheduled->forceFill([
                    'status' => ScheduledVoiceCall::STATUS_DEFERRED,
                    'next_attempt_at' => now()->addMinutes(15),
                    'last_error' => 'Automation is disabled.',
                ])->save();

                return null;
            }

            if ($reason = $scheduledCalls->sourceIneligibility($scheduled)) {
                $scheduled->forceFill([
                    'status' => ScheduledVoiceCall::STATUS_CANCELLED,
                    'next_attempt_at' => null,
                    'last_error' => $reason,
                ])->save();
                VoiceCall::query()->whereKey($scheduled->original_voice_call_id)
                    ->where('scheduled_voice_call_id', $scheduled->id)->update(['callback_status' => ScheduledVoiceCall::STATUS_CANCELLED]);
                if ($runId = $scheduled->metadata['automation_run_id'] ?? null) {
                    VoiceAutomationRun::query()->whereKey($runId)->update(['reason' => $reason]);
                }

                return null;
            }

            $deferredUntil = $this->deferredUntil($scheduledCalls->quietHours());
            $localDeferred = $this->deferredUntil($scheduled->quiet_hours ?? []);
            if ($localDeferred && (! $deferredUntil || $localDeferred->greaterThan($deferredUntil))) {
                $deferredUntil = $localDeferred;
            }
            if ($deferredUntil) {
                $scheduled->forceFill([
                    'status' => ScheduledVoiceCall::STATUS_DEFERRED,
                    'next_attempt_at' => $deferredUntil,
                    'metadata' => array_merge($scheduled->metadata ?? [], ['quiet_hours_deferred_at' => now()->toIso8601String()]),
                ])->save();

                return null;
            }

            $scheduled->forceFill([
                'status' => ScheduledVoiceCall::STATUS_DIALING,
                'attempts' => (int) $scheduled->attempts + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'result_voice_call_id' => null,
                'completed_at' => null,
                'last_error' => null,
                'metadata' => array_merge($scheduled->metadata ?? [], ['dial_attempt_token' => Str::uuid()->toString()]),
            ])->save();

            return $scheduled;
        }), 'voice-callback-claim');
    }

    private function currentAttempt(ScheduledVoiceCall $scheduled): \Illuminate\Database\Eloquent\Builder
    {
        return ScheduledVoiceCall::query()->whereKey($scheduled->id)
            ->where('status', ScheduledVoiceCall::STATUS_DIALING)
            ->where('metadata->dial_attempt_token', $scheduled->metadata['dial_attempt_token']);
    }

    private function deferredUntil(array $quiet): ?CarbonImmutable
    {
        if (! ($quiet['enabled'] ?? false)) {
            return null;
        }

        $timezone = (string) VoiceTimezone::normalize($quiet['timezone'] ?? config('app.timezone', 'UTC'));
        $start = (string) ($quiet['start'] ?? '20:00');
        $end = (string) ($quiet['end'] ?? '08:00');
        // Match the business-schedule contract: equal start/end means no window.
        if ($start === $end) {
            return null;
        }
        $now = CarbonImmutable::now($timezone);
        $startAt = CarbonImmutable::parse($now->toDateString().' '.$start, $timezone);
        $endAt = CarbonImmutable::parse($now->toDateString().' '.$end, $timezone);

        $inside = $startAt->lessThan($endAt)
            ? ($now->greaterThanOrEqualTo($startAt) && $now->lessThan($endAt))
            : ($now->greaterThanOrEqualTo($startAt) || $now->lessThan($endAt));

        if (! $inside) {
            return null;
        }

        if ($startAt->greaterThan($endAt) && $now->greaterThanOrEqualTo($startAt)) {
            $endAt = $endAt->addDay();
        }

        return $endAt->timezone(VoiceTimezone::normalize(config('app.timezone', 'UTC')));
    }

    private function fallbackUserId(): int
    {
        return (int) User::query()
            ->whereIn('role', ['superadmin', 'admin'])
            ->orderBy('id')
            ->value('id');
    }
}
