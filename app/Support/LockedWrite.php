<?php

namespace App\Support;

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
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    public static function run(callable $write, ?string $context = null, int $attempts = self::DEFAULT_ATTEMPTS)
    {
        $attempts = max(1, $attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $write();
            } catch (Throwable $e) {
                if ($attempt >= $attempts || !self::isLockContention($e)) {
                    if (self::isLockContention($e)) {
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
     * Is this specifically SQLite refusing a write because of another writer?
     *
     * Matched narrowly: anything broader would retry genuine failures such as a
     * constraint violation, which must surface on the first attempt.
     */
    public static function isLockContention(Throwable $e): bool
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
