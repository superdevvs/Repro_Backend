<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Times each outermost database transaction and logs the slow ones.
 *
 * duration_ms     BEGIN to the start of COMMIT (or to the rollback).
 * first_write_ms  BEGIN to the start of the first write statement.
 * writer_held_ms  first write to the start of COMMIT. With SQLite's deferred
 *                 BEGIN this is how long other writers had to wait.
 * origin          the code line that opened the transaction.
 *
 * No SQL text or bound value is kept.
 */
final class TransactionTimingLog
{
    private const OWN_FILES = [
        'app/Support/TransactionTimingLog.php',
        'app/Providers/TransactionTimingServiceProvider.php',
    ];

    /** @var array<string, array{started: int, ending: ?int, first_write: ?int, queries: int, writes: int, origin: ?string}> */
    private array $open = [];

    public function began(TransactionBeginning $event): void
    {
        if ($event->connection->transactionLevel() !== 1) {
            return;
        }

        $this->open[$event->connection->getName()] = [
            'started' => hrtime(true),
            'ending' => null,
            'first_write' => null,
            'queries' => 0,
            'writes' => 0,
            'origin' => $this->origin(),
        ];
    }

    public function queried(QueryExecuted $event): void
    {
        if (! isset($this->open[$event->connectionName]) || $event->connection->transactionLevel() < 1) {
            return;
        }

        $state = &$this->open[$event->connectionName];
        $state['queries']++;
        if (self::isWrite($event->sql)) {
            $state['writes']++;
            // QueryExecuted arrives after the statement; step back to its start.
            $state['first_write'] ??= hrtime(true) - (int) round($event->time * 1_000_000);
        }
    }

    public function committing(TransactionCommitting $event): void
    {
        $name = $event->connection->getName();
        if (isset($this->open[$name]) && $event->connection->transactionLevel() === 1) {
            $this->open[$name]['ending'] = hrtime(true);
        }
    }

    public function committed(TransactionCommitted $event): void
    {
        $this->finish($event->connection, 'commit');
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        $this->finish($event->connection, 'rollback');
    }

    private function finish(Connection $connection, string $outcome): void
    {
        $name = $connection->getName();
        if ($connection->transactionLevel() !== 0 || ! isset($this->open[$name])) {
            return;
        }

        $state = $this->open[$name];
        unset($this->open[$name]);

        $ending = $state['ending'] ?? hrtime(true);
        $duration = self::milliseconds($ending - $state['started']);
        if ($duration < (int) config('transaction_timing.threshold_ms', 250)) {
            return;
        }

        $context = ['duration_ms' => $duration];
        if ($state['first_write'] !== null) {
            $firstWrite = max($state['started'], $state['first_write']);
            $context['first_write_ms'] = self::milliseconds($firstWrite - $state['started']);
            $context['writer_held_ms'] = self::milliseconds(max(0, $ending - $firstWrite));
        }
        $context += [
            'queries' => $state['queries'],
            'writes' => $state['writes'],
            'connection' => $name,
            'outcome' => $outcome,
        ];
        if ($state['origin'] !== null) {
            $context['origin'] = $state['origin'];
        }

        try {
            Log::channel((string) config('transaction_timing.channel', 'transactions'))->info('slow_transaction', $context);
        } catch (Throwable $e) {
            // Timing must never break the request or job that was measured.
            error_log('Transaction timing log could not be written: '.$e::class);
        }
    }

    /** The first application line on the stack, else the first framework caller outside the database layer. */
    private function origin(): ?string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $fallback = null;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if (! str_starts_with($file, $base)) {
                continue;
            }
            $relative = substr($file, strlen($base));
            if (in_array($relative, self::OWN_FILES, true)) {
                continue;
            }
            $location = $relative.':'.(int) ($frame['line'] ?? 0);
            if (preg_match('#\A(?:app|bootstrap|database|routes|tests)/#', $relative)) {
                return $location;
            }
            if ($fallback === null && str_starts_with($relative, 'vendor/')
                && ! preg_match('#\Avendor/laravel/framework/src/Illuminate/(?:Database|Events|Support|Container)/#', $relative)) {
                $fallback = $location;
            }
        }

        return $fallback;
    }

    private static function isWrite(string $sql): bool
    {
        return preg_match('/\A\s*(?:insert|update|delete|replace|upsert|create|alter|drop|truncate)\b/i', $sql) === 1;
    }

    private static function milliseconds(int $nanoseconds): float
    {
        return round($nanoseconds / 1_000_000, 1);
    }
}
