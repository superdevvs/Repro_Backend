<?php

namespace App\Database;

use App\Services\Scheduling\GoogleRoutesBudget;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * MySQL connection that keeps SQLite's one-writer-at-a-time rule.
 *
 * With 'repro_writer_policy' on, every transaction takes the writer token
 * (repro_mysql_control.writer_token) straight after BEGIN, and every INSERT,
 * UPDATE, DELETE or REPLACE at level 0 runs as BEGIN, token, statement, COMMIT.
 * Only a WriterTokenTimeout raised before the business callback runs is
 * retried. Errors after the token is held are never retried: the connection
 * refuses further statements until rollback reaches level 0. Reads never wait.
 * See docs/mysql-known-differences.md for the behaviour this changes.
 */
final class MySqlConnection extends \Illuminate\Database\MySqlConnection implements WriterTokenConnection
{
    private const POISONED = 'The failed writer transaction requires an outer rollback before this connection is used again.';

    /** Set after a lock error or a lost session inside a transaction. */
    private bool $poisoned = false;

    /** The session was lost inside a transaction; Laravel's depth is kept until rollback. */
    private bool $pdoLost = false;

    /** @var list<string>|null Tables writable while queue bookkeeping skips the token. */
    private ?array $exemptTables = null;

    private ?WriterTokenTimeout $acquisitionTimeout = null;

    private ?int $tokenAcquiredAt = null;

    private ?int $tokenHeldNs = null;

    private ?string $sessionServerUuid = null;

    protected function getDefaultQueryGrammar()
    {
        return new MySqlQueryGrammar($this);
    }

    public function writerPolicyEnabled(): bool
    {
        return $this->getConfig('repro_writer_policy') === true;
    }

    /**
     * Run queue bookkeeping without the token, limited to the given tables.
     * Inside an open transaction the token is already held and nothing changes.
     */
    public function withoutWriterToken(array $tables, Closure $callback): mixed
    {
        if (! $this->writerPolicyEnabled() || $this->transactions > 0 || $this->exemptTables !== null) {
            return $callback($this);
        }
        $this->exemptTables = array_values($tables);
        try {
            return $callback($this);
        } finally {
            $this->exemptTables = null;
        }
    }

    /** True when the exception is this connection's own acquisition timeout, raised before any callback ran. */
    public function isAcquisitionTimeout(Throwable $error): bool
    {
        return $error instanceof WriterTokenTimeout && $error === $this->acquisitionTimeout;
    }

    public function beginTransaction()
    {
        if (! $this->writerPolicyEnabled() || $this->pretending()) {
            parent::beginTransaction();

            return;
        }
        $this->assertUsable();
        $level = $this->transactions;
        try {
            parent::beginTransaction();
        } catch (Throwable $error) {
            // A beginning listener may fail after Laravel registered the level.
            if ($this->transactions > $level) {
                $this->rollBack($level);
            }
            throw $error;
        }
    }

    protected function executeBeginTransactionStatement()
    {
        if (! $this->writerPolicyEnabled() || $this->exemptTables !== null || $this->pretending()) {
            parent::executeBeginTransactionStatement();

            return;
        }
        $this->acquireWriterToken();
    }

    protected function handleBeginTransactionException(Throwable $e)
    {
        if ($this->writerPolicyEnabled()) {
            // Never reconnect and BEGIN again: the caller decides whether to retry.
            throw $e;
        }

        parent::handleBeginTransactionException($e);
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        if (! $this->writerPolicyEnabled() || $this->pretending()) {
            return parent::transaction($callback, $attempts);
        }
        $attempts = max(1, (int) $attempts);
        $outer = $this->transactions === 0;
        for ($attempt = 1; ; $attempt++) {
            $entered = false;
            $this->acquisitionTimeout = null;
            try {
                // The parent gets one attempt: it must never replay the callback or COMMIT.
                return parent::transaction(function ($connection) use ($callback, &$entered) {
                    $entered = true;
                    $result = $callback($connection);
                    $this->assertUsable();

                    return $result;
                }, 1);
            } catch (WriterTokenTimeout $timeout) {
                if (! $outer || $entered || $timeout !== $this->acquisitionTimeout || $attempt >= $attempts
                    || $this->transactions !== 0) {
                    throw $timeout;
                }
                $this->report('warning', 'The MySQL writer token is busy; retrying before the transaction starts.', ['attempt' => $attempt]);
            }
        }
    }

    public function commit()
    {
        if (! $this->writerPolicyEnabled() || $this->pretending()) {
            parent::commit();

            return;
        }
        $this->assertUsable();
        $level = $this->transactions;
        try {
            parent::commit();
        } catch (Throwable $error) {
            // Laravel lowers the depth only after COMMIT succeeds. If it did not,
            // the outcome is unknown: discard the session and claim nothing.
            if ($level === 1 && $this->transactions === 1) {
                $this->discardSession();
            }
            throw $error;
        }
    }

    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        if (! $this->writerPolicyEnabled()) {
            parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);

            return;
        }
        // An unacknowledged COMMIT may have succeeded. Never retry, roll back or run callbacks.
        $this->discardSession();
        throw $e;
    }

    public function rollBack($toLevel = null)
    {
        if (! $this->writerPolicyEnabled() || $this->pretending()) {
            parent::rollBack($toLevel);

            return;
        }
        $toLevel = is_null($toLevel) ? $this->transactions - 1 : $toLevel;
        if ($toLevel < 0 || $toLevel >= $this->transactions) {
            return;
        }
        if ($this->poisoned || $this->pdoLost) {
            $this->rollBackFailedTransaction($toLevel);

            return;
        }
        try {
            parent::rollBack($toLevel);
        } catch (Throwable $error) {
            $this->discardSession();
            throw $error;
        }
    }

    protected function run($query, $bindings, Closure $callback)
    {
        if ($this->poisoned && $this->writerPolicyEnabled()) {
            throw new RuntimeException(self::POISONED);
        }

        return parent::run($query, $bindings, $callback);
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        if ($this->guardsRouteBudget($query) && ! $this->pretending()) {
            return $this->runRouteBudgetReservation($query, $bindings, $callback);
        }
        if ($this->pretending()) {
            return parent::runQueryCallback($query, $bindings, $callback);
        }

        $class = WriterStatement::classify($query);
        $this->assertWritableTable($query, $class);
        if (! $this->writerPolicyEnabled()) {
            return parent::runQueryCallback($query, $bindings, $callback);
        }

        $this->assertUsable();
        if ($class === WriterStatement::TRANSACTION_CONTROL) {
            throw new LogicException('Use Laravel transactions, not raw transaction or table-lock statements, on the MySQL writer connection.');
        }
        if ($class === WriterStatement::DDL && $this->transactions > 0) {
            throw new LogicException('DDL and TRUNCATE cannot run inside a MySQL transaction: they would commit it implicitly.');
        }
        if ($this->transactions === 0 && $this->exemptTables === null) {
            if ($class === WriterStatement::WRITE) {
                return $this->runAutocommitWrite($query, $bindings, $callback);
            }
            if ($class === WriterStatement::LOCKING_READ) {
                $this->refuseLockOutsideTransaction();
            }
        }

        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (Throwable $error) {
            if ($this->transactions > 0) {
                if ($this->causedByLostConnection($error->getPrevious() ?? $error) || $this->causedByLostConnection($error)) {
                    $this->loseSessionInsideTransaction();
                } elseif (in_array(self::driverCode($error), [1205, 1213], true)) {
                    $this->poisoned = true;
                }
            }
            throw $error;
        }
    }

    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        if ($this->guardsRouteBudget($query)) {
            throw $e;
        }

        return parent::handleQueryException($e, $query, $bindings, $callback);
    }

    public function setPdo($pdo)
    {
        $this->sessionServerUuid = null;

        return parent::setPdo($pdo);
    }

    protected function fireConnectionEvent($event)
    {
        if ($event === 'committing' && $this->transactions === 1) {
            $this->tokenHeldNs = $this->tokenAcquiredAt === null ? null : hrtime(true) - $this->tokenAcquiredAt;
        }
        if ($this->transactions === 0 && $this->tokenAcquiredAt !== null) {
            if ($event === 'committed') {
                $this->tokenReleased($this->tokenHeldNs);
            } elseif ($event === 'rollingBack') {
                $this->tokenReleased();
            }
        }

        return parent::fireConnectionEvent($event);
    }

    private function acquireWriterToken(): void
    {
        $this->acquisitionTimeout = null;
        try {
            (new MySqlWriterToken)->beginAndAcquire($this->getPdo(), $this->expectedServerUuid(), $this->getDatabaseName());
        } catch (WriterTokenTimeout $timeout) {
            // beginAndAcquire confirmed the rollback; this session stays usable.
            $this->acquisitionTimeout = $timeout;
            throw $timeout;
        } catch (Throwable $error) {
            $this->discardSession();
            throw $error;
        }
        $this->tokenAcquiredAt = hrtime(true);
        $this->tokenHeldNs = null;
    }

    private function expectedServerUuid(): string
    {
        $configured = $this->getConfig('repro_expected_server_uuid');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if ($this->getConfig('repro_require_server_identity') === true) {
            throw new RuntimeException('The MySQL writer token requires the approved server UUID.');
        }

        // Outside production the session's own server is the target. The token
        // row still binds the application database name.
        return $this->sessionServerUuid ??= (string) $this->getPdo()->query('SELECT @@server_uuid')->fetchColumn();
    }

    private function runAutocommitWrite(string $query, array $bindings, Closure $callback): mixed
    {
        $this->acquireWriterToken();
        $pdo = $this->getPdo();
        try {
            // MySQL's insert callback reads lastInsertId before this COMMIT.
            $result = parent::runQueryCallback($query, $bindings, $callback);
        } catch (Throwable $error) {
            try {
                if (! $pdo->inTransaction() || ! $pdo->rollBack() || $pdo->inTransaction()) {
                    throw new RuntimeException('The autocommit write did not confirm its rollback.');
                }
                $this->tokenReleased();
            } catch (Throwable) {
                // Nothing was committed. Laravel may reconnect and run the write again
                // only for a lost session, which MySQL has already rolled back.
                $this->discardSession();
            }
            throw $error;
        }

        try {
            if (! $pdo->commit() || $pdo->inTransaction()) {
                throw new RuntimeException('MySQL did not confirm the commit.');
            }
        } catch (Throwable $error) {
            $this->discardSession();
            throw new RuntimeException('The outcome of a MySQL autocommit write is unknown; it was not retried.', 0, $error);
        }
        $this->tokenReleased();

        // Laravel dispatches QueryExecuted after this returns, once the row is committed.
        return $result;
    }

    private function refuseLockOutsideTransaction(): void
    {
        $mode = $this->getConfig('repro_level0_lock_guard');
        if ($mode === 'throw') {
            throw new LogicException('A locking read outside a transaction protects nothing; run it inside DB::transaction().');
        }
        if ($mode === 'log') {
            $this->report('warning', 'MySQL locking read outside a transaction.', ['caller' => self::caller()]);
        }
    }

    private function assertUsable(): void
    {
        if ($this->poisoned) {
            throw new RuntimeException(self::POISONED);
        }
        if ($this->transactions > 0 && ! $this->pdoLost && $this->pdo instanceof PDO && ! $this->pdo->inTransaction()) {
            // A raw COMMIT/ROLLBACK or a server-side rollback ended the transaction.
            // Statements must not continue in autocommit at Laravel's old depth.
            $this->poisoned = true;
            throw new LogicException('The MySQL transaction ended outside Laravel; roll back to level 0 before continuing.');
        }
    }

    private function assertWritableTable(string $query, string $class): void
    {
        $tables = $this->exemptTables ?? $this->getConfig('repro_writer_exempt_tables');
        if (! is_array($tables) || $class === WriterStatement::READ || $class === WriterStatement::LOCKING_READ
            || $class === WriterStatement::SESSION) {
            return;
        }
        $target = $class === WriterStatement::WRITE ? WriterStatement::singleTargetTable($query) : null;
        if ($target === null || ! in_array($target, $tables, true)) {
            throw new LogicException('This MySQL session skips the writer token and may only write '.implode(', ', $tables).'.');
        }
    }

    private function rollBackFailedTransaction(int $toLevel): void
    {
        if ($toLevel > 0) {
            // Savepoints of a failed transaction are not trusted; only a full rollback clears the guard.
            $this->transactions = $toLevel;
            if (! $this->pdoLost) {
                $this->transactionsManager?->rollback($this->getName(), $toLevel);
            }
            $this->fireConnectionEvent('rollingBack');

            return;
        }
        if (! $this->pdoLost) {
            try {
                $pdo = $this->getPdo();
                if ($pdo->inTransaction() && (! $pdo->rollBack() || $pdo->inTransaction())) {
                    throw new RuntimeException('The failed MySQL transaction did not confirm its rollback.');
                }
            } catch (Throwable $error) {
                $this->discardSession();
                throw $error;
            }
            $this->transactionsManager?->rollback($this->getName(), 0);
        }
        $this->transactions = 0;
        $this->poisoned = false;
        $this->pdoLost = false;
        $this->fireConnectionEvent('rollingBack');
        $this->tokenReleased();
    }

    /** The session died inside a transaction: MySQL rolled it back. Keep the depth so the caller must roll back. */
    private function loseSessionInsideTransaction(): void
    {
        $this->abandonTransactionRecords();
        $this->closePdo();
        $this->pdoLost = true;
        $this->poisoned = true;
        $this->tokenAcquiredAt = null;
    }

    /** Drop the session after an uncertain outcome. Nothing is replayed and no callback runs. */
    private function discardSession(): void
    {
        $this->abandonTransactionRecords();
        $this->closePdo();
        $this->transactions = 0;
        $this->poisoned = false;
        $this->pdoLost = false;
        $this->tokenAcquiredAt = null;
        $this->tokenHeldNs = null;
    }

    private function closePdo(): void
    {
        $pdo = $this->pdo;
        try {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // The session is dropped below either way.
        }
        // Not setPdo(): that would reset Laravel's transaction depth.
        $this->pdo = null;
        $this->readPdo = null;
        $this->sessionServerUuid = null;
    }

    /** Remove this connection's pending and committed records without running their callbacks. */
    private function abandonTransactionRecords(): void
    {
        $manager = $this->transactionsManager;
        if ($manager === null) {
            return;
        }
        $name = $this->getName();
        (function () use ($name) {
            $this->currentTransaction[$name] = null;
            $this->pendingTransactions = $this->pendingTransactions->reject(fn ($transaction) => $transaction->connection === $name)->values();
            $this->committedTransactions = $this->committedTransactions->reject(fn ($transaction) => $transaction->connection === $name)->values();
        })->call($manager);
    }

    private function tokenReleased(?int $heldNs = null): void
    {
        if ($this->tokenAcquiredAt === null) {
            return;
        }
        $heldNs ??= hrtime(true) - $this->tokenAcquiredAt;
        $this->tokenAcquiredAt = null;
        $this->tokenHeldNs = null;
        $limit = (int) $this->getConfig('repro_writer_long_hold_ms');
        $heldMs = (int) round($heldNs / 1_000_000);
        if ($limit > 0 && $heldMs > $limit) {
            $this->report('warning', 'The MySQL writer token was held longer than the limit.', [
                'held_ms' => $heldMs, 'limit_ms' => $limit, 'caller' => self::caller(),
            ]);
        }
    }

    private function report(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, ['connection' => $this->getName()] + $context);
        } catch (Throwable) {
            // Diagnostics must never change the outcome of a write.
        }
    }

    /** The first application frame outside the database layer, for long-holder and lock reports. */
    private static function caller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && ! str_contains($file, '/vendor/') && ! str_contains($file, '/app/Database/')) {
                return basename(dirname($file)).'/'.basename($file).':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }

    private static function driverCode(Throwable $error): ?int
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $info = $current instanceof \PDOException || $current instanceof QueryException ? ($current->errorInfo ?? null) : null;
            if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
                return (int) $info[1];
            }
        }

        return null;
    }

    private function guardsRouteBudget($query): bool
    {
        // The general policy covers this statement; the pilot path runs only without it.
        return $query === GoogleRoutesBudget::RESERVATION_SQL
            && $this->getConfig('repro_route_budget_writer_token') === true
            && ! $this->writerPolicyEnabled();
    }

    private function runRouteBudgetReservation($query, $bindings, Closure $callback)
    {
        if ($this->transactionLevel() !== 0) {
            throw new LogicException('Reserve route usage outside the booking transaction.');
        }

        $uuid = $this->getConfig('repro_expected_server_uuid');
        if (! is_string($uuid) || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $uuid)
            || ! str_starts_with((string) $this->getConfig('unix_socket'), '/')) {
            throw new LogicException('The route budget writer token requires an approved MySQL UUID and local socket.');
        }

        $pdo = $this->getPdo();
        if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new LogicException('The route budget writer token requires a standalone, nonpersistent MySQL session.');
        }

        $wasModified = $this->hasModifiedRecords();
        $commitAttempted = false;
        try {
            $restoreSession = $this->prepareRouteBudgetSession($pdo);
            (new MySqlWriterToken)->beginAndAcquire($pdo, $uuid, $this->getDatabaseName());

            // Hold the business table's metadata lock until COMMIT so the reservation
            // cannot run against a nontransactional replacement of the budget table.
            $pdo->query('SELECT id FROM scheduling_route_usage ORDER BY id LIMIT 1')->closeCursor();
            $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'scheduling_route_usage' AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
            if ($engine !== 'InnoDB') {
                throw new RuntimeException('The route budget writer token requires the InnoDB budget table.');
            }

            $result = parent::runQueryCallback($query, $bindings, $callback);
            $commitAttempted = true;
            if (! $pdo->inTransaction() || ! $pdo->commit() || $pdo->inTransaction()) {
                throw new RuntimeException('MySQL did not confirm the route budget reservation commit.');
            }
        } catch (Throwable $failure) {
            $this->setRecordModificationState($wasModified);
            if (! $commitAttempted) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable) {
                    // Disconnect below even if rollback could not be confirmed.
                }
            }
            // Never send ROLLBACK on a session with an ambiguous COMMIT or retry a reservation.
            $this->disconnect();
            if ($commitAttempted) {
                throw new RuntimeException('The outcome of the MySQL route budget reservation is unknown.', 0, $failure);
            }

            throw $failure;
        }

        try {
            if ($restoreSession !== null && $pdo->exec($restoreSession) === false) {
                $this->disconnect();
            }
        } catch (Throwable) {
            // The reservation is durable. Do not reuse a session with uncertain defaults.
            $this->disconnect();
        }

        // Laravel dispatches QueryExecuted from run() after the confirmed commit.
        return $result;
    }

    private function prepareRouteBudgetSession(PDO $pdo): ?string
    {
        $settings = $pdo->query('SELECT @@SESSION.transaction_isolation, @@SESSION.innodb_lock_wait_timeout,
            @@SESSION.lock_wait_timeout')->fetch(PDO::FETCH_NUM);
        if ($settings === false || ! in_array($settings[0], ['READ-UNCOMMITTED', 'READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            throw new RuntimeException('The route budget writer token could not read the MySQL session settings.');
        }

        [$isolation, $rowTimeout, $metadataTimeout] = $settings;
        $rowTimeout = (int) $rowTimeout;
        $metadataTimeout = (int) $metadataTimeout;
        if ($isolation === 'READ-COMMITTED' && $rowTimeout === 5 && $metadataTimeout === 5) {
            return null;
        }

        $pdo->exec("SET SESSION transaction_isolation = 'READ-COMMITTED', SESSION innodb_lock_wait_timeout = 5, SESSION lock_wait_timeout = 5");

        return "SET SESSION transaction_isolation = '{$isolation}', SESSION innodb_lock_wait_timeout = {$rowTimeout}, SESSION lock_wait_timeout = {$metadataTimeout}";
    }
}
