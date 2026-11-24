<?php

namespace Tests\MySql\Contracts;

use App\Database\WriterTokenTimeout;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\MySql\Support\FixtureWriterTokenConnection;

require_once dirname(__DIR__).'/support/FixtureWriterTokenConnection.php';

/** Explicit disposable fixture; not the production application connection. */
final class LaravelWriterTokenContract extends TestCase
{
    private PDO $admin;

    private FixtureWriterTokenConnection $db;

    private string $directory;

    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('isolated-v1', getenv('MYSQL_WRITER_TOKEN_FIXTURE'));
        self::assertSame('/fixture-socket/mysql.sock', getenv('DB_SOCKET'));
        self::assertSame('repro_mysql_test', getenv('DB_DATABASE'));
        self::assertSame('repro_test', getenv('DB_USERNAME'));
        $this->admin = new PDO('mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test',
            'repro_token_fixture', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame(getenv('MYSQL_WRITER_TOKEN_UUID'), $this->admin->query('SELECT @@server_uuid')->fetchColumn());
        self::assertSame('8.4.13', $this->admin->query('SELECT VERSION()')->fetchColumn());
        self::assertSame('repro_token_fixture@localhost', $this->admin->query('SELECT CURRENT_USER()')->fetchColumn());
        $this->admin->exec('DROP TABLE IF EXISTS repro_mysql_control.writer_token');
        $this->admin->exec('CREATE TABLE repro_mysql_control.writer_token (id TINYINT UNSIGNED PRIMARY KEY CHECK(id=1),
            application_database VARBINARY(64) NOT NULL, contract_version INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->admin->exec("INSERT INTO repro_mysql_control.writer_token VALUES (1, 'repro_mysql_test', 1)");
        $this->admin->exec('DROP TABLE IF EXISTS _writer_token_probe');
        $this->admin->exec('CREATE TABLE _writer_token_probe (id INT AUTO_INCREMENT PRIMARY KEY,
            counter INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->admin->exec('INSERT INTO _writer_token_probe VALUES (1,0),(2,0)');
        self::assertSame(1, (int) $this->admin->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='repro_mysql_test'")->fetchColumn());
        $this->db = FixtureWriterTokenConnection::fromEnvironment();
        $this->directory = sys_get_temp_dir().'/repro-laravel-token-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
        if (isset($this->db) && $this->db->transactionLevel() > 0) {
            $this->db->rollBack(0);
        }
        if (isset($this->admin) && $this->admin->inTransaction()) {
            $this->admin->rollBack();
        }
        if (isset($this->db)) {
            $this->db->unsetEventDispatcher();
            $this->db->disconnect();
            unset($this->db);
        }
        unset($this->admin);
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function counter(int $id = 1): int
    {
        return (int) $this->admin->query('SELECT counter FROM _writer_token_probe WHERE id='.$id)->fetchColumn();
    }

    private function assertClosed(bool $discarded = false): void
    {
        $state = $this->db->fixtureState();
        self::assertSame(0, $state['level']);
        self::assertSame(0, $state['pending']);
        self::assertSame(0, $state['committed']);
        self::assertSame($discarded, $state['discarded']);
        if ($discarded) {
            self::assertNull($this->db->getRawPdo());
        } else {
            self::assertFalse($this->db->getPdo()->inTransaction());
        }
    }

    private function worker(string $mode): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/support/laravel-writer-token-worker.php', $mode, $this->directory]);
        $process->setTimeout(26);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function awaitMarker(string $name): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($this->directory.'/'.$name) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists($this->directory.'/'.$name);
    }

    private function processResult(Process $process): array
    {
        $process->wait();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function autonomousWrites(): array
    {
        return [
            'builder insertGetId' => [FixtureWriterTokenConnection::PROBE_INSERT,
                static fn ($db) => $db->table('_writer_token_probe')->insertGetId(['counter' => 8]), 3],
            'raw affectingStatement' => [FixtureWriterTokenConnection::PROBE_RESERVATION,
                static fn ($db) => $db->affectingStatement(FixtureWriterTokenConnection::PROBE_RESERVATION, [8, 8, 9]), 1],
        ];
    }

    #[DataProvider('autonomousWrites')]
    public function test_autonomous_write_waits_before_execution_and_commits_once(string $sql, \Closure $write, int $expected): void
    {
        $holder = $this->worker('hold');
        $this->awaitMarker('written');
        $seen = [];
        $this->db->getEventDispatcher()->listen(QueryExecuted::class, function ($event) use ($sql, &$seen) {
            if ($event->sql === $sql) {
                $seen[] = [$event->connection->getPdo()->inTransaction(), $this->counter(3)];
            }
        });

        $started = microtime(true);
        self::assertSame(0, (int) $this->db->table('_writer_token_probe')->where('id', 1)->value('counter'));
        self::assertLessThan(4.5, microtime(true) - $started);
        self::assertSame(0, $this->db->fixtureState()['acquisitions']);

        $started = microtime(true);
        try {
            $write($this->db);
            self::fail('The held token must prevent either autonomous write from executing.');
        } catch (WriterTokenTimeout) {
            self::assertSame([], $seen);
        }
        self::assertGreaterThanOrEqual(4.5, microtime(true) - $started);
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        self::assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM _writer_token_probe WHERE id > 2')->fetchColumn());
        $this->assertClosed();

        file_put_contents($this->directory.'/release', '1');
        self::assertSame(1, $this->processResult($holder)['value']);
        self::assertSame($expected, $write($this->db));
        self::assertSame([[false, 8]], $seen);
        self::assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM _writer_token_probe WHERE id > 2')->fetchColumn());
        self::assertSame(2, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed();
    }

    public function test_probe_insert_inside_explicit_transaction_preserves_outer_rollback(): void
    {
        $this->db->beginTransaction();
        self::assertSame(3, $this->db->table('_writer_token_probe')->insertGetId(['counter' => 8]));
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        self::assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM _writer_token_probe WHERE id > 2')->fetchColumn());
        $this->db->rollBack();
        self::assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM _writer_token_probe WHERE id > 2')->fetchColumn());
        $this->assertClosed();
    }

    public function test_rejected_autonomous_statement_rolls_back_before_reuse(): void
    {
        try {
            $this->db->table('_writer_token_probe')->insertGetId(['counter' => -1]);
            self::fail('An invalid counter must fail as a MySQL query error.');
        } catch (QueryException $error) {
            self::assertSame(1264, $error->errorInfo[1]);
        }

        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        self::assertSame(2, (int) $this->admin->query('SELECT COUNT(*) FROM _writer_token_probe')->fetchColumn());
        $this->assertClosed();
        // A different physical PDO can acquire immediately after failed SQL.
        self::assertSame(1, $this->processResult($this->worker('increment'))['value']);
    }

    public function test_autonomous_listener_failure_surfaces_after_one_durable_commit(): void
    {
        $calls = 0;
        $this->db->getEventDispatcher()->listen(QueryExecuted::class, static function ($event) use (&$calls) {
            if ($event->sql === FixtureWriterTokenConnection::PROBE_INSERT) {
                $calls++;
                throw new RuntimeException('Synthetic query observer failure.');
            }
        });

        try {
            $this->db->table('_writer_token_probe')->insertGetId(['counter' => 8]);
            self::fail('The listener failure must be visible to the caller.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic query observer failure.', $error->getMessage());
        }
        self::assertSame(1, $calls);
        self::assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM _writer_token_probe WHERE id > 2')->fetchColumn());
        self::assertSame(8, $this->counter(3));
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed();
    }

    public function test_manual_nested_savepoint_keeps_token_and_discards_inner_callbacks_until_outer_commit(): void
    {
        $callbacks = [];
        $this->db->beginTransaction();
        $this->db->afterCommit(function () use (&$callbacks) {
            $callbacks[] = 'outer';
        });
        $this->db->table('_writer_token_probe')->where('id', 1)->update(['counter' => 1]);
        $this->db->beginTransaction();
        $this->db->afterCommit(function () use (&$callbacks) {
            $callbacks[] = 'rolled-back-inner';
        });
        $this->db->afterRollBack(function () use (&$callbacks) {
            $callbacks[] = 'inner-rollback';
        });
        $this->db->table('_writer_token_probe')->where('id', 1)->update(['counter' => 99]);
        $this->db->rollBack();
        self::assertSame(1, $this->db->transactionLevel());
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        self::assertSame(['inner-rollback'], $callbacks);
        $second = $this->worker('increment');
        $this->awaitMarker('ready');
        usleep(200_000);
        self::assertFileDoesNotExist($this->directory.'/acquired');
        self::assertSame(0, $this->counter());
        $this->db->commit();
        self::assertSame(['inner-rollback', 'outer'], $callbacks);
        self::assertSame(2, $this->processResult($second)['value']);
        self::assertSame(2, $this->counter());
        $this->assertClosed();
    }

    public function test_closure_nested_commit_defers_callbacks_and_outer_rollback_discards_them(): void
    {
        $callbacks = [];
        try {
            $this->db->transaction(function ($db) use (&$callbacks) {
                $db->afterCommit(function () use (&$callbacks) {
                    $callbacks[] = 'outer';
                });
                $value = $db->transaction(function ($db) use (&$callbacks) {
                    $db->afterCommit(function () use (&$callbacks) {
                        $callbacks[] = 'inner';
                    });
                    $db->table('_writer_token_probe')->where('id', 1)->update(['counter' => 8]);

                    return 42;
                }, 3);
                self::assertSame(42, $value);
                self::assertSame([], $callbacks);
                self::assertSame(1, $db->transactionLevel());
                throw new RuntimeException('Reject outer work.');
            }, 3);
            self::fail('Outer failure must escape.');
        } catch (RuntimeException $error) {
            self::assertSame('Reject outer work.', $error->getMessage());
        }
        self::assertSame([], $callbacks);
        self::assertSame(0, $this->counter());
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed();
        $this->db->transaction(function ($db) use (&$callbacks) {
            $db->transaction(function ($db) use (&$callbacks) {
                $db->afterCommit(function () use (&$callbacks) {
                    $callbacks[] = 'new-inner';
                });
                $db->table('_writer_token_probe')->where('id', 1)->update(['counter' => 2]);
            });
            self::assertSame([], $callbacks);
        });
        self::assertSame(['new-inner'], $callbacks);
        self::assertSame(2, $this->counter());
        $this->assertClosed();
    }

    public function test_three_typed_acquisition_timeouts_never_enter_business_and_leave_connection_reusable(): void
    {
        $holder = $this->worker('hold');
        $this->awaitMarker('written');
        $calls = 0;
        $started = microtime(true);
        try {
            $this->db->transaction(function () use (&$calls) {
                $calls++;
            }, 3);
            self::fail('A held token must exhaust bounded acquisition attempts.');
        } catch (WriterTokenTimeout) {
            self::assertSame(0, $calls);
        }
        self::assertGreaterThanOrEqual(14.5, microtime(true) - $started);
        self::assertLessThan(20, microtime(true) - $started);
        self::assertSame(3, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed();
        file_put_contents($this->directory.'/release', '1');
        self::assertSame(1, $this->processResult($holder)['value']);
        $this->db->transaction(function ($db) use (&$calls) {
            $calls++;
            $db->table('_writer_token_probe')->where('id', 1)->increment('counter');
        }, 3);
        self::assertSame(1, $calls);
        self::assertSame(2, $this->counter());
        self::assertSame(4, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed();
    }

    #[DataProvider('commitStyles')]
    public function test_swallowed_native_business_lock_timeout_cannot_commit_or_replay(string $style): void
    {
        $this->admin->beginTransaction();
        $this->admin->exec('UPDATE _writer_token_probe SET counter = 1 WHERE id = 2');
        $calls = 0;
        $body = function ($db) use (&$calls) {
            $calls++;
            $db->table('_writer_token_probe')->where('id', 1)->update(['counter' => 1]);
            try {
                $db->table('_writer_token_probe')->where('id', 2)->update(['counter' => 2]);
                self::fail('The independently held business row must time out.');
            } catch (QueryException $error) {
                self::assertSame(1205, (int) $error->errorInfo[1]);
            }
            try {
                $db->selectOne('SELECT 1');
                self::fail('A swallowed post-token error must poison further statements.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('requires an outer rollback', $error->getMessage());
            }
        };
        try {
            if ($style === 'manual') {
                $this->db->beginTransaction();
                $body($this->db);
                $this->db->commit();
            } else {
                $this->db->transaction($body, 3);
            }
            self::fail('A poisoned transaction cannot commit.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('requires an outer rollback', $error->getMessage());
        } finally {
            $this->db->rollBack(0);
            $this->admin->rollBack();
        }
        self::assertSame(1, $calls);
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        self::assertSame(0, $this->counter());
        self::assertFalse($this->db->fixtureState()['poisoned']);
        $this->assertClosed();
    }

    public static function commitStyles(): array
    {
        return [['manual'], ['closure']];
    }

    #[DataProvider('timeoutLocations')]
    public function test_user_or_after_commit_timeout_is_never_an_acquisition_retry(string $where): void
    {
        $calls = 0;
        try {
            $this->db->transaction(function ($db) use (&$calls, $where) {
                $calls++;
                $db->table('_writer_token_probe')->where('id', 1)->increment('counter');
                if ($where === 'callback') {
                    throw new WriterTokenTimeout('Unrelated callback failure.');
                }
                $db->afterCommit(fn () => throw new WriterTokenTimeout('After commit failure.'));
            }, 3);
            self::fail('The callback failure must escape.');
        } catch (WriterTokenTimeout) {
            self::assertSame(1, $calls);
        }
        self::assertSame($where === 'after commit' ? 1 : 0, $this->counter());
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed();
    }

    public static function timeoutLocations(): array
    {
        return [['callback'], ['after commit']];
    }

    public function test_transaction_beginning_listener_failure_cleans_up_framework_and_pdo_state(): void
    {
        $this->db->getEventDispatcher()->listen(TransactionBeginning::class, fn () => throw new RuntimeException('Begin listener failed.'));
        try {
            $this->db->transaction(fn () => self::fail('Business must not run.'), 3);
            self::fail('Begin event must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Begin listener failed.', $error->getMessage());
        }
        $this->assertClosed();
        self::assertSame(0, $this->counter());
    }

    public function test_missing_token_is_fatal_without_reconnect_retry_or_stale_callbacks(): void
    {
        $this->admin->exec('DELETE FROM repro_mysql_control.writer_token');
        $reconnects = 0;
        $this->db->setReconnector(function () use (&$reconnects) {
            $reconnects++;
        });
        try {
            $this->db->transaction(fn () => self::fail('Business must not run.'), 3);
            self::fail('Missing token must fail closed.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('row is missing', $error->getMessage());
        }
        self::assertSame(0, $reconnects);
        self::assertSame(1, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed(true);
        $this->expectException(LogicException::class);
        $this->db->selectOne('SELECT 1');
    }

    #[DataProvider('disconnectLocations')]
    public function test_native_disconnect_at_begin_or_commit_never_replays_business(string $where): void
    {
        $control = new PDO('mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame(getenv('MYSQL_WRITER_TOKEN_UUID'), $control->query('SELECT @@server_uuid')->fetchColumn());
        $id = (int) $this->db->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        if ($where === 'begin') {
            $control->exec('KILL CONNECTION '.$id);
        }
        $calls = 0;
        $reconnects = 0;
        $this->db->setReconnector(function () use (&$reconnects) {
            $reconnects++;
        });
        try {
            $this->db->transaction(function ($db) use (&$calls, $control, $id, $where) {
                $calls++;
                $db->table('_writer_token_probe')->where('id', 1)->increment('counter');
                $db->afterCommit(fn () => self::fail('A failed commit must not dispatch.'));
                if ($where === 'query') {
                    // Kill after the fixture's run guard but before Laravel
                    // prepares the real query: exercise its exception handler.
                    $db->beforeExecuting(fn () => $control->exec('KILL CONNECTION '.$id));
                    $db->table('_writer_token_probe')->where('id', 2)->increment('counter');
                } else {
                    $control->exec('KILL CONNECTION '.$id);
                }
            }, 3);
            self::fail('A lost connection must fail closed.');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(WriterTokenTimeout::class, $error);
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
        }
        self::assertSame($where === 'begin' ? 0 : 1, $calls);
        self::assertSame(0, $reconnects);
        self::assertSame(0, $this->counter());
        $this->assertClosed(true);
    }

    public static function disconnectLocations(): array
    {
        return [['begin'], ['query'], ['commit']];
    }

    public function test_raw_transaction_end_is_detected_before_a_laravel_write_can_autocommit(): void
    {
        $this->db->beginTransaction();
        $this->db->getPdo()->rollBack();
        try {
            $this->db->table('_writer_token_probe')->where('id', 1)->increment('counter');
            self::fail('A mismatched PDO and Laravel depth must not become an autocommit write.');
        } catch (LogicException $error) {
            self::assertStringContainsString('physical transaction ended outside Laravel', $error->getMessage());
        }
        self::assertSame(0, $this->counter());
        $this->assertClosed(true);
    }

    public function test_pre_begin_callback_and_ordinary_environment_cannot_activate_partial_guarding(): void
    {
        $this->db->beforeStartingTransaction(fn () => self::fail('Pre-BEGIN application work must not run.'));
        try {
            $this->db->beginTransaction();
            self::fail('Unsupported lifecycle hook must be refused.');
        } catch (LogicException $error) {
            self::assertStringContainsString('outside this fixture scope', $error->getMessage());
        }
        self::assertSame(0, $this->db->fixtureState()['acquisitions']);
        $this->assertClosed();
        putenv('MYSQL_WRITER_TOKEN_FIXTURE');
        try {
            FixtureWriterTokenConnection::fromEnvironment();
            self::fail('No ordinary deployment environment selects this fixture connection.');
        } catch (LogicException $error) {
            self::assertStringContainsString('explicitly provisioned', $error->getMessage());
        } finally {
            putenv('MYSQL_WRITER_TOKEN_FIXTURE=isolated-v1');
        }
    }

    #[DataProvider('commitStyles')]
    public function test_lost_commit_acknowledgement_does_not_claim_rollback_or_replay(string $style): void
    {
        $calls = 0;
        $callbacks = [];
        $this->db->getEventDispatcher()->listen(TransactionCommitting::class, function ($event) {
            // Native COMMIT succeeds, but its acknowledgement is lost before
            // Laravel updates its counters. This is an explicit boundary fault.
            $event->connection->getPdo()->commit();
            throw new \PDOException('Synthetic lost acknowledgement after a real COMMIT.');
        });
        $body = function ($db) use (&$calls, &$callbacks) {
            $calls++;
            $db->afterCommit(function () use (&$callbacks) {
                $callbacks[] = 'commit';
            });
            $db->afterRollBack(function () use (&$callbacks) {
                $callbacks[] = 'rollback';
            });
            $db->table('_writer_token_probe')->where('id', 1)->increment('counter');
        };
        try {
            if ($style === 'manual') {
                $this->db->beginTransaction();
                $body($this->db);
                $this->db->commit();
            } else {
                $this->db->transaction($body, 3);
            }
            self::fail('An uncertain acknowledgement cannot report success.');
        } catch (\PDOException $error) {
            self::assertSame('Synthetic lost acknowledgement after a real COMMIT.', $error->getMessage());
        }
        self::assertSame(1, $calls);
        self::assertSame(1, $this->counter());
        self::assertSame([], $callbacks);
        $this->assertClosed(true);
    }

    public function test_throwing_rollback_callback_executes_once_and_cannot_leak_into_the_next_transaction(): void
    {
        $callbacks = 0;
        try {
            $this->db->transaction(function ($db) use (&$callbacks) {
                $db->afterRollBack(function () use (&$callbacks) {
                    $callbacks++;
                    throw new RuntimeException('Rollback callback failed.');
                });
                $db->table('_writer_token_probe')->where('id', 1)->increment('counter');
                throw new RuntimeException('Reject business work.');
            }, 3);
            self::fail('The rollback callback failure must escape.');
        } catch (RuntimeException $error) {
            self::assertSame('Rollback callback failed.', $error->getMessage());
        }
        self::assertSame(1, $callbacks);
        self::assertSame(0, $this->counter());
        $this->assertClosed(true);
    }
}
