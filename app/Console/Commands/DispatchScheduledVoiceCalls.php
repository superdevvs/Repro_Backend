<?php

namespace App\Console\Commands;

use App\Jobs\ScheduledVoiceCallJob;
use App\Models\ScheduledVoiceCall;
use App\Services\TelnyxAi\ScheduledVoiceCallService;
use Illuminate\Console\Command;

class DispatchScheduledVoiceCalls extends Command
{
    protected $signature = 'voice:dispatch-scheduled-calls {--limit=50 : Maximum due calls to dispatch}';

    protected $description = 'Create proactive voice call rows and dispatch due scheduled voice calls.';

    public function handle(ScheduledVoiceCallService $scheduledCalls): int
    {
        $created = $scheduledCalls->createDueProactiveCalls();
        $limit = max(1, (int) $this->option('limit'));

        $due = ScheduledVoiceCall::query()
            ->whereIn('status', [
                ScheduledVoiceCall::STATUS_SCHEDULED,
                ScheduledVoiceCall::STATUS_DEFERRED,
                ScheduledVoiceCall::STATUS_FAILED,
            ])
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        $due->each(fn (ScheduledVoiceCall $scheduledCall) => ScheduledVoiceCallJob::dispatch($scheduledCall->id));

        $this->info(sprintf(
            'Dispatched %d due scheduled voice call(s). Created proactive rows: %s.',
            $due->count(),
            json_encode($created)
        ));

        return self::SUCCESS;
    }
}
