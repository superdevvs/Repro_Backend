<?php

namespace Tests\MySql\Support;

use App\Database\MySqlConnector;
use App\Database\MySqlQueryGrammar;
use App\Database\MySqlWriterToken;
use App\Database\WriterTokenTimeout;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/** Explicit native fixture only. No production resolver, binding or configuration selects this class. */
final class FixtureWriterTokenConnection extends MySqlConnection
{
    public const PROBE_INSERT = 'insert into `_writer_token_probe` (`counter`) values (?)';

    public const PROBE_RESERVATION = 'INSERT INTO _writer_token_probe (counter) '
        .'SELECT ? WHERE COALESCE((SELECT SUM(counter) FROM _writer_token_probe), 0) + ? <= ?';

    private bool $discarded = false;

    private bool $poisoned = false;

    private ?WriterTokenTimeout $acquisitionTimeout = null;

    private int $acquisitions = 0;

    private DatabaseTransactionsManager $fixtureTransactions;

    private function __construct(PDO $pdo, array $config)
    {
        parent::__construct($pdo, 'repro_mysql_test', '', $config);
        $this->fixtureTransactions = new DatabaseTransactionsManager;
        $this->setTransactionManager($this->fixtureTransactions);
        $this->setEventDispatcher(new Dispatcher(new Container));
    }

    public static function fromEnvironment(): self
    {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
            || getenv('MYSQL_WRITER_TOKEN_FIXTURE') !== 'isolated-v1'
            || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'repro_mysql_test'
            || getenv('DB_USERNAME') !== 'repro_test' || getenv('DB_SOCKET') !== '/fixture-socket/mysql.sock') {
            throw new LogicException('This connection exists only in the explicitly provisioned writer-token fixture.');
        }
        $config = ['name' => 'writer-token-fixture', 'driver' => 'mysql', 'database' => 'repro_mysql_test',
            'username' => 'repro_test', 'password' => getenv('DB_PASSWORD'), 'unix_socket' => '/fixture-socket/mysql.sock',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin', 'strict' => true,
            'isolation_level' => 'READ COMMITTED', 'repro_require_server_identity' => true,
            'repro_expected_server_uuid' => getenv('MYSQL_WRITER_TOKEN_UUID')];
        $pdo = (new MySqlConnector)->connect($config);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->exec('SET SESSION lock_wait_timeout = 5');

        return new self($pdo, $config);
    }

    protected function getDefaultQueryGrammar()
    {
        return new MySqlQueryGrammar($this);
    }

    public function getPdo()
    {
        if ($this->discarded) {
            throw new LogicException('The fixture connection was discarded and cannot be reused.');
        }

        return parent::getPdo();
    }

    public function beginTransaction()
    {
        $this->assertClean();
        if ($this->beforeStartingTransaction !== [] || $this->pretending()) {
            throw new LogicException('Pre-BEGIN callbacks and pretend transactions are outside this fixture scope.');
        }
        $level = $this->transactionLevel();
        try {
            parent::beginTransaction();
        } catch (Throwable $error) {
            // A transaction-beginning event may throw after Laravel registered
            // its transaction. Restore exactly the previous savepoint/depth.
            if (! $this->discarded && $this->transactionLevel() > $level) {
                $this->rollBack($level);
            } elseif (! $this->discarded && ! $error instanceof WriterTokenTimeout) {
                $this->discard();
            }
            throw $error;
        }
    }

    protected function executeBeginTransactionStatement()
    {
        $this->acquisitions++;
        $this->acquisitionTimeout = null;
        try {
            (new MySqlWriterToken)->beginAndAcquire($this->getPdo(),
                $this->getConfig('repro_expected_server_uuid'), $this->getDatabaseName());
        } catch (WriterTokenTimeout $error) {
            $this->acquisitionTimeout = $error;
            throw $error;
        } catch (Throwable $error) {
            $this->discard();
            throw $error;
        }
    }

    protected function handleBeginTransactionException(Throwable $error)
    {
        // The framework normally reconnects here. An uncertain BEGIN is fatal.
        throw $error;
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        if (! is_int($attempts) || $attempts < 1 || $attempts > 3) {
            throw new LogicException('The fixture permits one to three bounded acquisition attempts.');
        }
        $outer = $this->transactionLevel() === 0;
        for ($attempt = 1; ; $attempt++) {
            $entered = false;
            $this->acquisitionTimeout = null;
            try {
                // Parent must never retry the business callback or COMMIT.
                return parent::transaction(function ($connection) use ($callback, &$entered) {
                    $entered = true;
                    $result = $callback($connection);
                    $this->assertClean(); // Parent commits directly through PDO.

                    return $result;
                }, 1);
            } catch (WriterTokenTimeout $error) {
                if (! $outer || $entered || $error !== $this->acquisitionTimeout || $attempt >= $attempts
                    || $this->transactionLevel() !== 0 || $this->getPdo()->inTransaction()) {
                    throw $error;
                }
            }
        }
    }

    public function commit()
    {
        $this->assertClean();
        try {
            parent::commit();
        } catch (Throwable $error) {
            $this->discard();
            throw $error;
        }
    }

    protected function handleCommitTransactionException(Throwable $error, $currentAttempt, $maxAttempts)
    {
        $this->discard();
        throw $error;
    }

    public function rollBack($toLevel = null)
    {
        try {
            parent::rollBack($toLevel);
        } catch (Throwable $error) {
            $this->discard();
            throw $error;
        }
        if ($this->transactionLevel() === 0) {
            $this->poisoned = false;
        }
    }

    protected function run($query, $bindings, Closure $callback)
    {
        $this->assertClean();

        return parent::run($query, $bindings, $callback);
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        // Exact fixture SQL only. Runtime SQL classification is a separate contract.
        if ($this->transactionLevel() === 0 && ! $this->pretending()
            && in_array($query, [self::PROBE_INSERT, self::PROBE_RESERVATION], true)) {
            return $this->runAutonomousProbe($query, $bindings, $callback);
        }

        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (Throwable $error) {
            if ($this->transactionLevel() > 0 && $this->causedByConcurrencyError($error)) {
                $this->poisoned = true;
            }
            if ($this->causedByLostConnection($error)) {
                $this->discard();
            }
            throw $error;
        }
    }

    private function runAutonomousProbe(string $query, array $bindings, Closure $callback): mixed
    {
        $this->executeBeginTransactionStatement();
        $pdo = $this->getPdo();
        try {
            // MySqlConnection's insert callback reads lastInsertId before COMMIT.
            $result = parent::runQueryCallback($query, $bindings, $callback);
        } catch (Throwable $error) {
            try {
                if (! $pdo->inTransaction() || ! $pdo->rollBack() || $pdo->inTransaction()) {
                    throw new RuntimeException('The autonomous writer did not confirm rollback.');
                }
            } catch (Throwable) {
                $this->discard();
                throw new RuntimeException('Autonomous write failed without a confirmed rollback.', 0, $error);
            }
            throw $error;
        }

        try {
            if (! $pdo->inTransaction() || ! $pdo->commit() || $pdo->inTransaction()) {
                throw new RuntimeException('The autonomous writer did not confirm commit.');
            }
        } catch (Throwable $error) {
            $this->discard();
            throw $error;
        }

        // Illuminate runs QueryExecuted listeners after this callback returns.
        return $result;
    }

    protected function handleQueryException(QueryException $error, $query, $bindings, Closure $callback)
    {
        // Discard resets Laravel's depth. Its default handler would then treat
        // a failed in-transaction query as autocommit and reconnect/replay it.
        throw $error;
    }

    private function assertClean(): void
    {
        if ($this->discarded) {
            throw new LogicException('The fixture connection was discarded and cannot be reused.');
        }
        if ($this->poisoned) {
            throw new RuntimeException('The failed writer transaction requires an outer rollback.');
        }
        if ($this->transactionLevel() > 0 && ! $this->getPdo()->inTransaction()) {
            $this->discard();
            throw new LogicException('The physical transaction ended outside Laravel; this connection was discarded.');
        }
    }

    private function discard(): void
    {
        $this->discarded = true;
        $pdo = $this->getRawPdo();
        try {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // No further work is permitted on this PDO, even if cleanup failed.
        }
        // Outcome may be unknown (COMMIT acknowledgement lost), and a rollback
        // callback may itself have thrown. Abandon this fixture's private
        // manager without dispatching or repeating either set of callbacks.
        $this->fixtureTransactions = new DatabaseTransactionsManager;
        $this->setTransactionManager($this->fixtureTransactions);
        parent::disconnect();
    }

    public function fixtureState(): array
    {
        return ['level' => $this->transactionLevel(), 'discarded' => $this->discarded,
            'poisoned' => $this->poisoned, 'acquisitions' => $this->acquisitions,
            'pending' => $this->fixtureTransactions->getPendingTransactions()->count(),
            'committed' => $this->fixtureTransactions->getCommittedTransactions()->count()];
    }
}
