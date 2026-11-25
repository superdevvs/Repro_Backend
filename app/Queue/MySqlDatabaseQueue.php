<?php

namespace App\Queue;

use App\Database\MySqlConnection;
use Closure;
use Illuminate\Queue\DatabaseQueue;

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
    public function pop($queue = null)
    {
        return $this->bookkeeping(fn () => parent::pop($queue));
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
