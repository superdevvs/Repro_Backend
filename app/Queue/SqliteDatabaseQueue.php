<?php

namespace App\Queue;

use App\Support\LockedWrite;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Throwable;

/** Keep a reservation lock race from discarding a valid, unexecuted job. */
class SqliteDatabaseQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        // Laravel's DatabaseQueue::pop fails a selected job for any exception,
        // including SQLITE_BUSY_SNAPSHOT during reservation. Retry the complete
        // short transaction after rollback; never retry a job's actual handler.
        return LockedWrite::run(function () use ($queue) {
            $jobRecord = null;
            try {
                return $this->database->transaction(function () use ($queue, &$jobRecord) {
                    if ($jobRecord = $this->getNextAvailableJob($queue)) {
                        return $this->marshalJob($queue, $jobRecord);
                    }

                    return null;
                });
            } catch (Throwable $exception) {
                // Preserve the framework's malformed-job handling. A lock error
                // leaves the original row available, including after retry exhaustion.
                if ($jobRecord && ! LockedWrite::isLockContention($exception)) {
                    try {
                        (new DatabaseJob($this->container, $this, $jobRecord, $this->connectionName, $queue))->fail($exception);
                    } catch (Throwable) {
                        // Surface the original failure, as the base queue does.
                    }
                }

                throw $exception;
            }
        }, 'queue.sqlite.reserve');
    }

    public function deleteReserved($queue, $id)
    {
        return LockedWrite::run(fn () => parent::deleteReserved($queue, $id), 'queue.sqlite.delete');
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        return LockedWrite::run(fn () => parent::deleteAndRelease($queue, $job, $delay), 'queue.sqlite.release');
    }
}
