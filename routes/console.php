<?php

use App\Jobs\SendSystemEmailDispatchJob;
use App\Models\ShootUploadAttempt;
use App\Models\SystemEmailDispatch;
use App\Services\SystemOverviewTelemetryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('system-overview:prune', function (SystemOverviewTelemetryService $telemetry) {
    $result = $telemetry->prune();
    $this->info('System overview telemetry pruned.');
    foreach ($result as $table => $count) {
        $this->line(sprintf('%s: %d', $table, $count));
    }
})->purpose('Prune system overview telemetry older than 24 hours');

Artisan::command('system-emails:recover {--limit=100}', function () {
    $limit = max(1, min((int) $this->option('limit'), 500));

    SystemEmailDispatch::query()
        ->where('status', 'pending')
        ->where('created_at', '<=', now()->subMinute())
        ->oldest('id')
        ->limit($limit)
        ->pluck('id')
        ->each(fn (int $id) => SendSystemEmailDispatchJob::dispatch($id));

    SystemEmailDispatch::query()
        ->where('status', 'failed')
        ->where('attempt_count', '<', 5)
        ->where('updated_at', '<=', now()->subMinutes(2))
        ->oldest('id')
        ->limit($limit)
        ->pluck('id')
        ->each(fn (int $id) => SendSystemEmailDispatchJob::dispatch($id));

    $uncertain = SystemEmailDispatch::query()
        ->where('status', 'processing')
        ->where('updated_at', '<=', now()->subMinutes(5))
        ->pluck('id')
        ->all();

    if ($uncertain !== []) {
        Log::critical('System email dispatches require provider reconciliation before resend.', [
            'dispatch_ids' => $uncertain,
        ]);
    }

    $overdue = SystemEmailDispatch::query()
        ->whereIn('status', ['pending', 'failed'])
        ->where('created_at', '<=', now()->subMinutes(5))
        ->count();

    if ($overdue > 0) {
        Log::critical('System email outbox has dispatches older than five minutes.', [
            'overdue_count' => $overdue,
        ]);
    }

    $this->info('System email outbox recovery queued.');
})->purpose('Recover known-safe pending/failed protected email dispatches');

Artisan::command('shoot-uploads:audit-pending {--minutes=5}', function () {
    $minutes = max(1, min((int) $this->option('minutes'), 1440));
    $stale = ShootUploadAttempt::query()
        ->where('status', ShootUploadAttempt::STATUS_PENDING)
        ->where('updated_at', '<=', now()->subMinutes($minutes))
        ->oldest('id')
        ->get(['id', 'shoot_id', 'actor_id', 'idempotency_key', 'correlation_id', 'updated_at']);

    if ($stale->isNotEmpty()) {
        Log::critical('Upload attempts require reconciliation before retry.', [
            'threshold_minutes' => $minutes,
            'attempt_count' => $stale->count(),
            'attempts' => $stale->map(fn (ShootUploadAttempt $attempt) => [
                'id' => $attempt->id,
                'shoot_id' => $attempt->shoot_id,
                'actor_id' => $attempt->actor_id,
                'idempotency_key' => $attempt->idempotency_key,
                'correlation_id' => $attempt->correlation_id,
                'updated_at' => $attempt->updated_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    $this->info(sprintf('Upload attempt audit complete: %d stale pending attempt(s).', $stale->count()));
})->purpose('Alert on pending upload attempts that require safe reconciliation');
