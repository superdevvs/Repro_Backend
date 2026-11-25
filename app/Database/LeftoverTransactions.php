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
        $events->listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class],
            fn ($event) => $this->finished($event->job, 'A queued job', ['job' => $event->job->resolveName()]));
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

    public function finished(object $unit, string $what, array $context = []): void
    {
        $id = spl_object_id($unit);
        if (! isset($this->starts[$id])) {
            return;
        }
        $start = $this->starts[$id];
        unset($this->starts[$id]);
        foreach ($this->db->getConnections() as $name => $connection) {
            $this->settle($name, $connection, $start[$name] ?? 0, $what, $context);
        }
    }

    public function settleAll(string $what): void
    {
        $this->starts = [];
        foreach ($this->db->getConnections() as $name => $connection) {
            $this->settle($name, $connection, 0, $what, []);
        }
    }

    private function settle(string $name, $connection, int $start, string $what, array $context): void
    {
        $level = $connection->transactionLevel();
        if ($level <= $start) {
            return;
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
            return;
        }
        try {
            $connection->rollBack($start);
        } catch (Throwable) {
            // Never reuse a session whose rollback was not confirmed.
            $this->db->purge($name);
        }
    }
}
