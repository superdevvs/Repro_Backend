<?php

namespace App\Queue;

use App\Database\MySqlConnection;
use Closure;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Throwable;

/**
 * Database queue on the MySQL writer connection.
 *
 * Enqueue is a business write: it takes the writer token and rolls back with
 * the transaction that dispatched it. Claiming, finishing and releasing a job
 * touch only the jobs table, so that bookkeeping skips the token on the same
 * connection and workers do not queue behind each other or behind a long
 * business transaction. Inside an open transaction nothing is skipped.
 */
final class MySqlDatabaseQueue extends DatabaseQueue
{
    /**
     * Laravel's pop, with only the claim transaction token-free. When a job
     * cannot be claimed, Laravel fails it: that runs the job's failed handler
     * and the JobFailed listeners, which record failed_jobs. Those are business
     * writes, so they run after the bookkeeping, with the token.
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        $jobRecord = null;

        try {
            // Not an arrow function: the claimed record must reach the catch below.
            return $this->bookkeeping(function () use ($queue, &$jobRecord) {
                return $this->database->transaction(function () use ($queue, &$jobRecord) {
                    if ($jobRecord = $this->getNextAvailableJob($queue)) {
                        return $this->marshalJob($queue, $jobRecord);
                    }
                });
            });
        } catch (Throwable $e) {
            // Potentially invalid job that we need to fail (laravel/framework#58978).
            if ($jobRecord) {
                try {
                    (new DatabaseJob(
                        $this->container, $this, $jobRecord, $this->connectionName, $queue
                    ))->fail($e);
                } catch (Throwable) {
                    // Ignore and throw the original exception, as Laravel does.
                }
            }

            throw $e;
        }
    }

    public function deleteReserved($queue, $id)
    {
        $this->bookkeeping(fn () => parent::deleteReserved($queue, $id));
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        $this->bookkeeping(fn () => parent::deleteAndRelease($queue, $job, $delay));
    }

    private function bookkeeping(Closure $work): mixed
    {
        return $this->database instanceof MySqlConnection
            ? $this->database->withoutWriterToken([$this->table], $work)
            : $work();
    }
}
