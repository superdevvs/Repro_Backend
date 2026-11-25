<?php

namespace App\Database;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A long-lived worker must not carry a transaction from one job into the next:
 * on MySQL it would hold the writer token until the worker dies. Each job and
 * in-process scheduled task is compared with the depth it started at, so a
 * sync job inside its caller's transaction is left alone. A worker loop never
 * runs inside a transaction, so anything open there is a leak.
 *
 * database.repro_leftover_transactions: 'rollback' (default on MySQL) or 'log'.
 */
final class LeftoverTransactions
{
    /** @var array<int, array<string, int>> Starting depth per connection, keyed by job or task object. */
    private array $starts = [];

    public function __construct(private readonly DatabaseManager $db, private readonly Repository $config) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->started($event->job));
        $events->listen([JobProcessed::class, JobExceptionOccurred::class],
            fn ($event) => $this->finished($event->job, 'A queued job', ['job' => $event->job->resolveName()]));
        $events->listen(JobFailed::class, fn (JobFailed $event) => $this->failed($event->job));
        $events->listen(Looping::class, fn () => $this->settleAll('Something before this worker loop'));
        $events->listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $this->started($event->task));
        $events->listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class],
            fn ($event) => $this->finished($event->task, 'A scheduled task', ['task' => $event->task->getSummaryForDisplay()]));
    }

    public function started(object $unit): void
    {
        $depths = [];
        foreach ($this->db->getConnections() as $name => $connection) {
            $depths[$name] = $connection->transactionLevel();
        }
        $this->starts[spl_object_id($unit)] = $depths;
    }

    /** True when a transaction the unit left open was rolled back. */
    public function finished(object $unit, string $what, array $context = []): bool
    {
        $id = spl_object_id($unit);
        if (! isset($this->starts[$id])) {
            return false;
        }
        $start = $this->starts[$id];
        unset($this->starts[$id]);
        $rolledBack = false;
        foreach ($this->db->getConnections() as $name => $connection) {
            $rolledBack = $this->settle($name, $connection, $start[$name] ?? 0, $what, $context) || $rolledBack;
        }

        return $rolledBack;
    }

    /**
     * Laravel deletes a job it fails, then runs its failed handler, inside whatever
     * the job left open, and only then fires JobFailed. Rolling that back restores
     * the reserved row, so the worker would fail and record the job again after
     * retry_after. Delete it again once the transaction is gone.
     */
    private function failed(object $job): void
    {
        if (! $this->finished($job, 'A queued job', ['job' => $job->resolveName()])
            || ! $job instanceof DatabaseJob || ! $job->isDeleted()) {
            return;
        }
        try {
            $job->delete();
        } catch (Throwable $error) {
            try {
                Log::error('A failed job could not be deleted again after its leftover transaction was rolled back.', [
                    'job' => $job->resolveName(), 'error' => $error->getMessage(),
                ]);
            } catch (Throwable) {
                // Reporting must not stop the remaining JobFailed listeners.
            }
        }
    }

    public function settleAll(string $what): void
    {
        $this->starts = [];
        foreach ($this->db->getConnections() as $name => $connection) {
            $this->settle($name, $connection, 0, $what, []);
        }
    }

    private function settle(string $name, $connection, int $start, string $what, array $context): bool
    {
        $level = $connection->transactionLevel();
        if ($level <= $start) {
            return false;
        }
        $action = $this->config->get('database.repro_leftover_transactions') === 'rollback' ? 'rollback' : 'log';
        try {
            Log::error($what.' left a database transaction open.', $context + [
                'connection' => $name, 'levels_left_open' => $level - $start, 'action' => $action,
            ]);
        } catch (Throwable) {
            // Reporting must not stop the cleanup.
        }
        if ($action !== 'rollback') {
            return false;
        }
        try {
            $connection->rollBack($start);
        } catch (Throwable) {
            // Never reuse a session whose rollback was not confirmed.
            $this->db->purge($name);
        }

        return true;
    }
}
