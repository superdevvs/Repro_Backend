<?php

namespace Tests\MySql\Contracts;

use App\Database\MySqlWriterToken;
use App\Database\WriterTokenTimeout;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Explicit fixture gate; deliberately not auto-discovered before control-schema CI provisioning. */
final class MySqlWriterTokenContract extends TestCase
{
    private PDO $admin;

    private PDO $worker;

    private string $uuid;

    private string $directory;

    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('isolated-v1', getenv('MYSQL_WRITER_TOKEN_FIXTURE'));
        self::assertSame('/fixture-socket/mysql.sock', getenv('DB_SOCKET'));
        self::assertSame('repro_mysql_test', getenv('DB_DATABASE'));
        self::assertSame('repro_test', getenv('DB_USERNAME'));
        $this->uuid = getenv('MYSQL_WRITER_TOKEN_UUID');
        $this->admin = $this->connection('repro_token_fixture');
        $identity = $this->admin->query('SELECT @@server_uuid AS uuid, VERSION() AS version, CURRENT_USER() AS account')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->uuid, $identity['uuid']);
        self::assertSame('8.4.13', $identity['version']);
        self::assertSame('repro_token_fixture@localhost', $identity['account']);
        $this->admin->exec('DROP TABLE IF EXISTS repro_mysql_control.writer_token');
        $this->admin->exec('CREATE TABLE repro_mysql_control.writer_token (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id = 1),
            application_database VARBINARY(64) NOT NULL, contract_version INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->admin->exec("INSERT INTO repro_mysql_control.writer_token VALUES (1, 'repro_mysql_test', 1)");
        $this->admin->exec('CREATE TABLE IF NOT EXISTS _writer_token_probe (id INT PRIMARY KEY, counter INT NOT NULL) ENGINE=InnoDB');
        $this->admin->exec('DELETE FROM _writer_token_probe');
        $this->admin->exec('INSERT INTO _writer_token_probe VALUES (1, 0)');
        $this->worker = $this->connection('repro_test');
        $this->directory = sys_get_temp_dir().'/repro-writer-token-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
        if (isset($this->worker) && $this->worker->inTransaction()) {
            $this->worker->rollBack();
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function connection(string $user): PDO
    {
        $pdo = new PDO('mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test;charset=utf8mb4',
            $user, getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->exec('SET SESSION lock_wait_timeout = 5');

        return $pdo;
    }

    private function startWorker(string $mode, string $slot = 'a'): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/support/writer-token-worker.php', $mode, $this->directory, $slot]);
        $process->setTimeout(15);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function awaitMarker(string $marker): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($this->directory.'/'.$marker) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists($this->directory.'/'.$marker);
    }

    private function processResult(Process $process): array
    {
        $process->wait();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    #[DataProvider('endings')]
    public function test_same_transaction_retains_cross_schema_token_through_savepoint_until_outer_end(string $ending): void
    {
        (new MySqlWriterToken)->beginAndAcquire($this->worker, $this->uuid, 'repro_mysql_test');
        $this->worker->exec('UPDATE _writer_token_probe SET counter = 1 WHERE id = 1');
        $this->worker->exec('SAVEPOINT nested_probe');
        $this->worker->exec('UPDATE _writer_token_probe SET counter = 99 WHERE id = 1');
        $this->worker->exec('ROLLBACK TO SAVEPOINT nested_probe');
        $second = $this->startWorker('increment');
        $this->awaitMarker('a.ready');
        usleep(200_000);
        self::assertFileDoesNotExist($this->directory.'/a.acquired');
        self::assertTrue($second->isRunning());
        $reader = $this->connection('repro_token_reader');
        $started = microtime(true);
        self::assertSame(0, (int) $reader->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
        self::assertLessThan(1, microtime(true) - $started);
        $this->worker->{$ending}();
        self::assertSame(['status' => 'committed', 'active' => false], $this->processResult($second));
        self::assertSame($ending === 'commit' ? 2 : 1,
            (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
        self::assertSame(1, (int) $this->admin->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'repro_mysql_test'")->fetchColumn());
    }

    public static function endings(): array
    {
        return [['commit'], ['rollBack']];
    }

    public function test_timeout_is_typed_rolls_back_and_does_not_trigger_framework_deadlock_retries(): void
    {
        (new MySqlWriterToken)->beginAndAcquire($this->worker, $this->uuid, 'repro_mysql_test');
        $result = $this->processResult($this->startWorker('timeout'));
        self::assertSame('timeout', $result['status']);
        self::assertFalse($result['active']);
        self::assertFalse($result['framework_retryable']);
        self::assertGreaterThanOrEqual(4.5, $result['seconds']);
        self::assertLessThan(8, $result['seconds']);
        self::assertSame(0, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
        $this->worker->rollBack();
        self::assertSame(['status' => 'committed', 'active' => false], $this->processResult($this->startWorker('increment', 'b')));
    }

    public function test_killed_holder_releases_token_and_rolls_back_its_business_write(): void
    {
        $holder = $this->startWorker('hold');
        $this->awaitMarker('a.written');
        $waiter = $this->startWorker('increment', 'b');
        $this->awaitMarker('b.ready');
        usleep(200_000);
        self::assertFileDoesNotExist($this->directory.'/b.acquired');
        $holder->signal(9);
        $holder->wait();
        self::assertSame(['status' => 'committed', 'active' => false], $this->processResult($waiter));
        self::assertSame(1, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
    }

    public function test_minimal_column_update_grant_allows_lock_but_not_control_identity_or_schema_changes(): void
    {
        (new MySqlWriterToken)->beginAndAcquire($this->worker, $this->uuid, 'repro_mysql_test');
        $this->worker->commit();
        foreach ([
            'DELETE FROM repro_mysql_control.writer_token',
            "INSERT INTO repro_mysql_control.writer_token VALUES (1, 'other', 1)",
            "UPDATE repro_mysql_control.writer_token SET application_database = 'other' WHERE id = 1",
            'DROP TABLE repro_mysql_control.writer_token',
        ] as $sql) {
            try {
                $this->worker->exec($sql);
                self::fail('The runtime principal must not be able to mutate control identity or layout.');
            } catch (PDOException $error) {
                self::assertContains((int) $error->errorInfo[1], [1142, 1143]);
            }
        }
        try {
            $this->worker->exec('UPDATE repro_mysql_control.writer_token SET id = 2 WHERE id = 1');
            self::fail('The singleton check must reject moving the token.');
        } catch (PDOException $error) {
            self::assertSame(3819, (int) $error->errorInfo[1]);
        }
        self::assertSame('repro_mysql_test', $this->admin->query('SELECT application_database FROM repro_mysql_control.writer_token WHERE id = 1')->fetchColumn());
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_state_fails_closed_without_an_active_transaction_or_business_effect(string $state): void
    {
        $expectedUuid = $this->uuid;
        $expectedDatabase = 'repro_mysql_test';
        switch ($state) {
            case 'wrong uuid': $expectedUuid = '00000000-0000-0000-0000-000000000000';
                break;
            case 'wrong database': $expectedDatabase = 'repro_other_test';
                break;
            case 'missing row': $this->admin->exec('DELETE FROM repro_mysql_control.writer_token');
                break;
            case 'wrong row binding': $this->admin->exec("UPDATE repro_mysql_control.writer_token SET application_database = 'repro_other_test'");
                break;
            case 'wrong version': $this->admin->exec('UPDATE repro_mysql_control.writer_token SET contract_version = 2');
                break;
            case 'missing table': $this->admin->exec('DROP TABLE repro_mysql_control.writer_token');
                break;
            case 'nontransactional table': $this->admin->exec('ALTER TABLE repro_mysql_control.writer_token ENGINE=MyISAM');
                break;
            case 'readonly account': $this->worker = $this->connection('repro_token_reader');
                break;
            case 'readonly session': $this->worker->exec('SET SESSION TRANSACTION READ ONLY');
                break;
            case 'wrong isolation': $this->worker->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                break;
            case 'wrong timeout': $this->worker->exec('SET SESSION innodb_lock_wait_timeout = 50');
                break;
            case 'wrong metadata timeout': $this->worker->exec('SET SESSION lock_wait_timeout = 50');
                break;
        }
        try {
            (new MySqlWriterToken)->beginAndAcquire($this->worker, $expectedUuid, $expectedDatabase);
            self::fail('The primitive must refuse an unsafe acquisition.');
        } catch (RuntimeException $error) {
            self::assertNotInstanceOf(WriterTokenTimeout::class, $error);
        }
        self::assertFalse($this->worker->inTransaction());
        self::assertSame(0, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
    }

    public static function invalidStates(): array
    {
        return array_map(fn ($state) => [$state], ['wrong uuid', 'wrong database', 'missing row', 'wrong row binding',
            'wrong version', 'missing table', 'nontransactional table', 'readonly account', 'readonly session',
            'wrong isolation', 'wrong timeout', 'wrong metadata timeout']);
    }

    public function test_begin_boundary_overrides_pending_transaction_characteristics(): void
    {
        $this->worker->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        (new MySqlWriterToken)->beginAndAcquire($this->worker, $this->uuid, 'repro_mysql_test');
        self::assertSame(0, (int) $this->worker->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
        // Deliberately bypass the unregistered primitive to prove the actual
        // transaction takes a fresh READ COMMITTED snapshot on its second read.
        $this->admin->exec('UPDATE _writer_token_probe SET counter = 7 WHERE id = 1');
        self::assertSame(7, (int) $this->worker->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
        $this->worker->exec('UPDATE _writer_token_probe SET counter = 8 WHERE id = 1');
        $this->worker->rollBack();
        self::assertSame(7, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
    }

    public function test_existing_transaction_is_refused_without_rolling_back_its_owners_work(): void
    {
        $this->worker->beginTransaction();
        $this->worker->exec('UPDATE _writer_token_probe SET counter = 3 WHERE id = 1');
        try {
            (new MySqlWriterToken)->beginAndAcquire($this->worker, $this->uuid, 'repro_mysql_test');
            self::fail('Nested ownership must be refused.');
        } catch (RuntimeException $error) {
            self::assertSame('Writer token cannot begin inside an existing transaction.', $error->getMessage());
        }
        self::assertTrue($this->worker->inTransaction());
        self::assertSame(3, (int) $this->worker->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
        $this->worker->rollBack();
        self::assertSame(0, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
    }

    public function test_implicit_transaction_mode_is_refused_without_business_work(): void
    {
        $this->worker->exec('SET SESSION autocommit = 0');
        self::assertSame(0, (int) $this->worker->query('SELECT @@SESSION.autocommit')->fetchColumn());
        try {
            (new MySqlWriterToken)->beginAndAcquire($this->worker, $this->uuid, 'repro_mysql_test');
            self::fail('An implicit transaction mode cannot establish exclusive BEGIN ownership.');
        } catch (RuntimeException $error) {
            self::assertNotInstanceOf(WriterTokenTimeout::class, $error);
        }
        // A rejected unknown-mode PDO is discarded, never reused by the primitive.
        self::assertSame(0, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
    }

    private function fixtureControl(): PDO
    {
        // The explicitly isolated, disposable runner initializes this local
        // daemon without a root password. Never use this helper against a host.
        $control = new PDO('mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test', 'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame($this->uuid, $control->query('SELECT @@server_uuid')->fetchColumn());
        self::assertSame('root@localhost', $control->query('SELECT CURRENT_USER()')->fetchColumn());

        return $control;
    }

    public function test_super_read_only_server_is_refused_before_beginning(): void
    {
        $control = $this->fixtureControl();
        try {
            $control->exec('SET GLOBAL super_read_only = ON');
            try {
                (new MySqlWriterToken)->beginAndAcquire($this->worker, $this->uuid, 'repro_mysql_test');
                self::fail('A server frozen for recovery cannot admit a writer.');
            } catch (RuntimeException $error) {
                self::assertSame('Writer token target identity or transaction settings differ.', $error->getMessage());
            }
            self::assertFalse($this->worker->inTransaction());
            self::assertSame(0, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
        } finally {
            $control->exec('SET GLOBAL super_read_only = OFF');
            $control->exec('SET GLOBAL read_only = OFF');
        }
    }

    #[DataProvider('disconnectPhases')]
    public function test_native_disconnect_during_setup_or_begin_is_not_retried(string $phase): void
    {
        $control = $this->fixtureControl();
        $pdo = new class('mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test', 'repro_test', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO
        {
            public ?\Closure $disconnect = null;

            public string $phase;

            public int $faults = 0;

            public function exec(string $statement): int|false
            {
                if (str_starts_with($statement, 'SET TRANSACTION ') && $this->disconnect && $this->phase === 'setup') {
                    $this->faults++;
                    ($this->disconnect)();
                }

                return parent::exec($statement);
            }

            public function beginTransaction(): bool
            {
                if ($this->disconnect && $this->phase === 'begin') {
                    $this->faults++;
                    ($this->disconnect)();
                }

                return parent::beginTransaction();
            }
        };
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->exec('SET SESSION lock_wait_timeout = 5');
        $id = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $pdo->phase = $phase;
        $pdo->disconnect = fn () => $control->exec('KILL CONNECTION '.$id);
        try {
            (new MySqlWriterToken)->beginAndAcquire($pdo, $this->uuid, 'repro_mysql_test');
            self::fail('A disconnected transaction boundary cannot admit work.');
        } catch (RuntimeException $error) {
            self::assertNotInstanceOf(WriterTokenTimeout::class, $error);
            self::assertStringContainsString('discard this database connection', $error->getMessage());
        }
        self::assertSame(1, $pdo->faults);
        unset($pdo);
        self::assertSame(0, (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id = 1')->fetchColumn());
    }

    public static function disconnectPhases(): array
    {
        return [['setup'], ['begin']];
    }
}
