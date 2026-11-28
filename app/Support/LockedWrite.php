<?php

namespace App\Support;

use App\Database\WriterTokenConnection;
use App\Database\WriterTokenTimeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry a write that lost a race with another SQLite writer.
 *
 * The database is SQLite in WAL mode with busy_timeout=5000, and that is not
 * enough on its own. busy_timeout only helps a writer that is waiting for a
 * lock to clear. It does nothing for SQLITE_BUSY_SNAPSHOT, which is what a
 * connection gets when it began reading, another connection committed, and it
 * then tries to write inside that stale read snapshot. SQLite returns that
 * immediately rather than waiting, so the driver timeout never comes into play
 * and the caller sees a bare "database is locked".
 *
 * That is exactly how cubicasa:resync-pending was failing: it iterated shoots
 * over an open cursor while the queue worker committed to the same file, so
 * roughly one run in five aborted partway through.
 *
 * This is deliberately a short bounded retry, not a way to make the error go
 * away. If the contention does not clear the original exception is rethrown so
 * the caller still fails visibly.
 *
 * On MySQL with the writer policy there is no stale-snapshot refusal to retry.
 * The callback runs once, at its own transaction depth. Each writer-token wait
 * inside it may retry where it stands, before its BEGIN or statement does
 * anything. A failure after the token is held, including a MySQL lock timeout
 * or deadlock, is never retried, so the callback is never replayed for it.
 */
final class LockedWrite
{
    /** Attempts include the first try, so 4 means one attempt plus three retries. */
    public const DEFAULT_ATTEMPTS = 4;

    /** Base backoff in microseconds; doubles per attempt and carries jitter. */
    private const BASE_DELAY_US = 40_000;

    /** Never sleep longer than this between attempts. */
    private const MAX_DELAY_US = 400_000;

    /**
     * Run $write, retrying only while the failure is a SQLite lock contention.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    public static function run(callable $write, ?string $context = null, int $attempts = self::DEFAULT_ATTEMPTS)
    {
        $attempts = max(1, $attempts);
        $connection = DB::connection();
        $writerToken = $connection instanceof WriterTokenConnection && $connection->writerPolicyEnabled();

        for ($attempt = 1; ; $attempt++) {
            try {
                return $writerToken ? self::runWithWriterToken($connection, $write, $context, $attempts) : $write();
            } catch (Throwable $e) {
                // Only SQLite's own refusal replays the callback. A MySQL token
                // wait was already retried in place and is never replayed here.
                if ($attempt >= $attempts || ! self::isSqliteLockContention($e)) {
                    if (self::isSqliteLockContention($e)) {
                        Log::error('Write still blocked by another database writer after retrying; giving up.', [
                            'context' => $context,
                            'attempts' => $attempt,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    throw $e;
                }

                $delay = min(self::MAX_DELAY_US, (int) (self::BASE_DELAY_US * (2 ** ($attempt - 1))));
                // Jitter so concurrent losers do not retry in lockstep.
                $delay = random_int((int) ($delay / 2), $delay);

                Log::warning('Write blocked by another database writer; retrying.', [
                    'context' => $context,
                    'attempt' => $attempt,
                    'retry_in_ms' => (int) round($delay / 1000),
                ]);

                usleep($delay);
            }
        }
    }

    /**
     * Run the callback once. Each MySQL writer-token wait inside it may retry
     * where it stands, before its BEGIN or statement does anything.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    private static function runWithWriterToken(WriterTokenConnection $connection, callable $write, ?string $context, int $attempts)
    {
        try {
            return $connection->withAcquisitionAttempts($attempts, static fn () => $write());
        } catch (WriterTokenTimeout $e) {
            if ($connection->isAcquisitionTimeout($e)) {
                Log::error('Write still blocked by another database writer after retrying; giving up.', [
                    'context' => $context,
                    'attempts' => $attempts,
                ]);
            }

            throw $e;
        }
    }

    /**
     * Was this write refused because another writer was busy? Callers answer
     * "busy, retry" for it. That is SQLite's "database is locked", or on MySQL
     * a WriterTokenTimeout: the wait for the writer token ran out before the
     * statement or transaction did anything.
     *
     * Matched narrowly: anything broader would retry genuine failures such as a
     * constraint violation, or a MySQL lock error after the token was held.
     */
    public static function isLockContention(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof WriterTokenTimeout || self::describesLock($current)) {
                return true;
            }
        }

        return false;
    }

    /** SQLite's own lock refusal, the only failure that replays a LockedWrite callback. */
    private static function isSqliteLockContention(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (self::describesLock($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SQLITE_BUSY (5) and SQLITE_LOCKED (6) as surfaced by PDO. The message is
     * the discriminator: both arrive as SQLSTATE HY000, which on its own also
     * covers unrelated general errors that must not be retried.
     */
    private static function describesLock(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked');
    }
}
