<?php
namespace App\Services\IguideLab;

use App\Support\LockedWrite;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Queue boundary of the tour layer (spec conventions "Push", 9.8, 9.9).
 *
 * Both tour connections use after_commit, so a dispatch inside a transaction only registers a callback that runs
 * after the commit and outside LockedWrite's retry. Lab write transactions therefore return what to push, and the
 * caller pushes here at the baseline transaction level once LockedWrite::run() has returned.
 */
final class LabTourQueue
{
    /** Tables whose rows carry monitor_token, monitor_seq and monitor_heartbeat_at. */
    public const MONITOR_TABLES = ['iguide_lab_tour_analyses', 'iguide_lab_tour_runs'];

    /**
     * One autocommit jobs-table insert, retried only on SQLite lock contention, which is safe because a locked insert
     * did not happen (DatabaseQueue.php:246-253). Any other failure is rethrown to the caller.
     */
    public static function push(object $job): void
    {
        LockedWrite::run(fn () => app(Dispatcher::class)->dispatch($job), 'iguide-lab.tour-push');
    }

    /**
     * Read only: is a job of this class for this id waiting or reserved on the queue? The serialized id and the class
     * name appear verbatim in the payload. A connection that is not a database queue never has a pending row.
     */
    public static function pendingJobExists(string $connection, string $queue, string $jobClassBasename, string $id): bool
    {
        $config = config('queue.connections.'.$connection);
        if (! is_array($config) || ($config['driver'] ?? null) !== 'database' || $jobClassBasename === '' || $id === '') return false;

        return DB::connection($config['connection'] ?? null)->table($config['table'] ?? 'jobs')
            ->where('queue', $queue)
            ->where('payload', 'like', '%'.$jobClassBasename.'%')
            ->where('payload', 'like', '%'.$id.'%')
            ->exists();
    }

    /**
     * Monitor claim (spec 9.8): one conditional bookkeeping UPDATE; exactly one affected row means proceed.
     * - New chain ($seq null): takes a stopped or stale monitor, or one it already owns (a retry of its first job).
     * - Successor ($seq = s): needs the same token and monitor_seq = s - 1, or monitor_seq = s on a retry.
     *
     * @return ?int the claimed seq, null = do not proceed
     */
    public static function claimMonitor(string $table, string $id, string $token, ?int $seq, bool $isRetry): ?int
    {
        if (! in_array($table, self::MONITOR_TABLES, true)) throw new \InvalidArgumentException('Not a tour monitor table.');
        if ($token === '') return null;
        $now = now();
        $stale = $now->copy()->subSeconds(LabTourConfig::heartbeatStaleSeconds());
        $affected = LockedWrite::run(function () use ($table, $id, $token, $seq, $isRetry, $now, $stale) {
            $query = DB::table($table)->where('id', $id);
            if ($seq === null) {
                return $query->where(fn ($q) => $q->whereNull('monitor_heartbeat_at')->orWhere('monitor_heartbeat_at', '<', $stale)->orWhere('monitor_token', $token))
                    ->update(['monitor_token' => $token, 'monitor_seq' => 1, 'monitor_heartbeat_at' => $now]);
            }

            return $query->where('monitor_token', $token)
                ->where(fn ($q) => $isRetry ? $q->where('monitor_seq', $seq - 1)->orWhere('monitor_seq', $seq) : $q->where('monitor_seq', $seq - 1))
                ->update(['monitor_seq' => $seq, 'monitor_heartbeat_at' => $now]);
        }, 'iguide-lab.tour-monitor-claim');

        return $affected === 1 ? ($seq ?? 1) : null;
    }
}
