<?php

namespace App\Queue;

use App\Support\LockedWrite;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Database queue bookkeeping that holds up on a busy SQLite file in WAL mode.
 *
 * Laravel claims, finishes and releases jobs with a read followed by a write inside a deferred
 * transaction. When any other connection commits between that read and that write, SQLite refuses
 * the write at once (SQLITE_BUSY_SNAPSHOT, "database is locked") and busy_timeout cannot help. Under
 * load the bounded retries ran out: finished jobs stayed reserved and ran again after retry_after,
 * and claims failed. Here every queue write is either one statement or starts with BEGIN IMMEDIATE,
 * so it waits on busy_timeout like any other writer instead of failing on a stale snapshot.
 * LockedWrite still covers the rare case where that wait itself runs out.
 */
class SqliteDatabaseQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        // Idle workers poll constantly; a plain read first, so an empty poll never takes the writer lock.
        if (! $this->hasClaimableJob($queue)) {
            return null;
        }

        return LockedWrite::run(fn () => $this->claim($queue), 'queue.sqlite.reserve');
    }

    public function deleteReserved($queue, $id)
    {
        return LockedWrite::run(fn () => $this->database->table($this->table)->where('id', $id)->delete(), 'queue.sqlite.delete');
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        return LockedWrite::run(fn () => $this->writeImmediately(function () use ($queue, $job, $delay) {
            $this->database->table($this->table)->where('id', $job->getJobId())->delete();

            return $this->release($queue, $job->getJobRecord(), $delay);
        }), 'queue.sqlite.release');
    }

    private function hasClaimableJob(string $queue): bool
    {
        return $this->database->table($this->table)
            ->where('queue', $queue)
            ->where(function ($query) {
                $this->isAvailable($query);
                $this->isReservedButExpired($query);
            })
            ->exists();
    }

    /** Claim the oldest claimable job in one atomic statement; two workers can never take the same row. */
    private function claim(string $queue): ?DatabaseJob
    {
        $table = $this->database->getQueryGrammar()->wrapTable($this->table);
        $now = $this->currentTime();
        $row = $this->database->selectOne(
            "update {$table} set \"reserved_at\" = ?, \"attempts\" = \"attempts\" + 1 where \"id\" = ("
            ."select \"id\" from {$table} where \"queue\" = ? and ((\"reserved_at\" is null and \"available_at\" <= ?) or \"reserved_at\" <= ?) "
            .'order by "id" asc limit 1) returning *',
            [$now, $queue, $now, Carbon::now()->subSeconds($this->retryAfter)->getTimestamp()],
            false,
        );

        return $row
            ? new DatabaseJob($this->container, $this, new DatabaseJobRecord((object) $row), $this->connectionName, $queue)
            : null;
    }

    /** BEGIN IMMEDIATE takes the writer lock before any read, so the writes cannot meet a stale snapshot. */
    private function writeImmediately(callable $write): mixed
    {
        if ($this->database->transactionLevel() > 0) {
            return $write(); // Part of the caller's own transaction.
        }

        // PHP 8.3's PDO cannot begin an IMMEDIATE transaction and does not track this one, so nothing
        // inside may open another transaction; the release body is one delete and one insert.
        $pdo = $this->database->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $write();
            $pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Nothing left to roll back.
            }

            throw $exception;
        }
    }
}
