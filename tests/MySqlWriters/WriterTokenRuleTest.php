<?php

namespace Tests\MySqlWriters;

use App\Database\MySqlConnection;
use App\Database\WriterTokenTimeout;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\MySqlWriters\Concerns\WriterFixture;
use Tests\TestCase;

/** The plan's writer-token rules, through the real container-resolved 'mysql' connection. */
final class WriterTokenRuleTest extends TestCase
{
    use WriterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWriterFixture();
    }

    protected function tearDown(): void
    {
        $db = DB::connection('mysql');
        if ($db->transactionLevel() > 0) {
            $db->rollBack(0);
        }
        $this->tearDownWriterFixture();
        parent::tearDown();
    }

    private function db(): MySqlConnection
    {
        return DB::connection('mysql');
    }

    public function test_fresh_application_connection_has_the_fixed_session_settings(): void
    {
        $settings = $this->db()->selectOne('SELECT @@SESSION.transaction_isolation AS isolation,
            @@SESSION.innodb_lock_wait_timeout AS row_wait, @@SESSION.lock_wait_timeout AS metadata_wait');

        $this->assertSame('READ-COMMITTED', $settings->isolation);
        $this->assertSame(5, (int) $settings->row_wait);
        $this->assertSame(5, (int) $settings->metadata_wait);
    }

    public function test_a_laravel_transaction_holds_the_token_from_begin_until_commit(): void
    {
        $this->assertFalse($this->tokenIsHeld());
        $this->db()->beginTransaction();
        $this->assertTrue($this->tokenIsHeld(), 'BEGIN must take the token before any statement runs.');
        $this->db()->commit();
        $this->assertFalse($this->tokenIsHeld());

        $seen = $this->db()->transaction(fn () => $this->tokenIsHeld());
        $this->assertTrue($seen);
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_a_plain_read_never_waits_for_the_token(): void
    {
        $this->holdTokenOnAdmin();
        $started = microtime(true);

        $this->assertSame(0, (int) $this->db()->table('_writer_policy_probe')->where('id', 1)->value('counter'));
        $this->assertSame(2, $this->db()->table('_writer_policy_probe')->count());

        $this->assertLessThan(2, microtime(true) - $started);
        $this->releaseAdminToken();
    }

    public static function autocommitWrites(): array
    {
        $probe = new class extends Model
        {
            protected $table = '_writer_policy_probe';

            protected $guarded = [];

            public $timestamps = false;
        };
        $model = static fn () => $probe::query();

        return [
            'builder insert' => [static fn ($db) => $db->table('_writer_policy_probe')->insert(['counter' => 7]), 3, 7],
            'builder insertGetId' => [static fn ($db) => $db->table('_writer_policy_probe')->insertGetId(['counter' => 7]), 3, 7],
            'builder insertOrIgnore' => [static fn ($db) => $db->table('_writer_policy_probe')->insertOrIgnore(['counter' => 7]), 3, 7],
            'builder upsert' => [static fn ($db) => $db->table('_writer_policy_probe')->upsert([['id' => 3, 'counter' => 7]], ['id'], ['counter']), 3, 7],
            'builder update' => [static fn ($db) => $db->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 7]), 1, 7],
            'builder increment' => [static fn ($db) => $db->table('_writer_policy_probe')->where('id', 1)->increment('counter', 7), 1, 7],
            'builder delete' => [static fn ($db) => $db->table('_writer_policy_probe')->where('id', 2)->delete(), 2, null],
            'builder updateOrInsert' => [static fn ($db) => $db->table('_writer_policy_probe')->updateOrInsert(['id' => 3], ['counter' => 7]), 3, 7],
            'eloquent create' => [static fn () => $model()->create(['counter' => 7]), 3, 7],
            'eloquent save' => [static function () use ($model) {
                $row = $model()->findOrFail(1);
                $row->counter = 7;

                return $row->save();
            }, 1, 7],
            'eloquent delete' => [static fn () => $model()->findOrFail(2)->delete(), 2, null],
            'eloquent firstOrCreate' => [static fn () => $model()->firstOrCreate(['note' => 'x'], ['counter' => 7]), 3, 7],
            'eloquent updateOrCreate' => [static fn () => $model()->updateOrCreate(['id' => 1], ['counter' => 7]), 1, 7],
            'raw affectingStatement' => [static fn ($db) => $db->affectingStatement('UPDATE _writer_policy_probe SET counter = ? WHERE id = 1', [7]), 1, 7],
            'raw statement' => [static fn ($db) => $db->statement('INSERT INTO _writer_policy_probe (counter) VALUES (?)', [7]), 3, 7],
            'raw unprepared' => [static fn ($db) => $db->unprepared('REPLACE INTO _writer_policy_probe (id, counter) VALUES (3, 7)'), 3, 7],
            'raw insert' => [static fn ($db) => $db->insert('INSERT INTO _writer_policy_probe (counter) VALUES (?)', [7]), 3, 7],
            'executable comment' => [static fn ($db) => $db->unprepared('/*!80000 UPDATE _writer_policy_probe SET counter = 7 WHERE id = 1 */'), 1, 7],
            'prepared statement' => [static function ($db) {
                $db->unprepared("PREPARE writer_policy_probe FROM 'UPDATE _writer_policy_probe SET counter = 7 WHERE id = 1'");

                return $db->unprepared('EXECUTE writer_policy_probe');
            }, 1, 7],
        ];
    }

    #[DataProvider('autocommitWrites')]
    public function test_each_autocommit_write_waits_for_the_token_and_commits_once_after_release(Closure $write, int $id, ?int $expected): void
    {
        $before = $this->admin->query('SELECT id, counter FROM _writer_policy_probe ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->holdTokenOnAdmin();

        $started = microtime(true);
        try {
            $write($this->db());
            $this->fail('A held token must stop the write before it runs.');
        } catch (WriterTokenTimeout) {
            // Only the typed acquisition timeout reaches the caller.
        }
        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $started);
        $this->assertSame($before, $this->admin->query('SELECT id, counter FROM _writer_policy_probe ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR));
        $this->assertSame(0, $this->db()->transactionLevel());
        $this->assertFalse($this->db()->getPdo()->inTransaction());

        $this->releaseAdminToken();
        $write($this->db());
        $after = $this->admin->query('SELECT id, counter FROM _writer_policy_probe ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame($expected, $after[$id] ?? null);
        $this->assertCount(count($before) + ($expected === null ? -1 : ($id === 3 ? 1 : 0)), $after);
        $this->assertFalse($this->tokenIsHeld());
        $this->assertFalse($this->db()->getPdo()->inTransaction());
    }

    public function test_a_write_inside_a_transaction_does_not_take_the_token_again(): void
    {
        $this->db()->transaction(function ($db) {
            $db->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 1]);
            $db->table('_writer_policy_probe')->insert(['counter' => 2]);
            $db->transaction(fn ($db) => $db->table('_writer_policy_probe')->where('id', 2)->update(['counter' => 3]));
            $this->assertSame(0, $this->counter(), 'Uncommitted work must stay invisible to other sessions.');
        });

        $this->assertSame([1, 3], [$this->counter(1), $this->counter(2)]);
        $this->assertSame(3, $this->probeRows());
    }

    public function test_ddl_inside_a_transaction_is_refused_before_it_can_commit_implicitly(): void
    {
        $this->db()->beginTransaction();
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 5]);
        try {
            $this->db()->statement('CREATE TABLE _writer_policy_ddl (id INT)');
            $this->fail('DDL inside a transaction must be refused.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('implicit', $error->getMessage());
        }
        try {
            $this->db()->statement('TRUNCATE TABLE _writer_policy_probe');
            $this->fail('TRUNCATE inside a transaction must be refused.');
        } catch (LogicException) {
        }
        $this->assertTrue($this->db()->getPdo()->inTransaction());
        $this->db()->rollBack();
        $this->assertSame(0, $this->counter());
        $this->assertSame(0, (int) $this->admin->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = 'repro_mysql_test' AND TABLE_NAME = '_writer_policy_ddl'")->fetchColumn());

        // Temporary tables do not commit implicitly and stay allowed.
        $this->db()->transaction(function ($db) {
            $db->statement('CREATE TEMPORARY TABLE _writer_policy_tmp (id INT)');
            $db->statement('DROP TEMPORARY TABLE _writer_policy_tmp');
        });
    }

    public static function implicitCommits(): array
    {
        return [
            'analyze' => ['ANALYZE TABLE _writer_policy_probe'],
            'optimize' => ['OPTIMIZE TABLE _writer_policy_probe'],
            'autocommit on' => ['SET autocommit = 1'],
        ];
    }

    #[DataProvider('implicitCommits')]
    public function test_other_statements_that_commit_implicitly_are_refused_inside_a_transaction(string $sql): void
    {
        $this->db()->beginTransaction();
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 5]);
        $error = $this->failureOf(fn () => $this->db()->statement($sql));

        $this->assertInstanceOf(LogicException::class, $error, 'It would commit the half-done transaction.');
        $this->assertStringContainsString('implicit', $error->getMessage());
        $this->assertTrue($this->db()->getPdo()->inTransaction());
        $this->db()->rollBack();
        $this->assertSame(0, $this->counter());
    }

    public function test_ddl_inside_only_the_test_wrapper_commits_it_exactly_as_before(): void
    {
        // RefreshDatabase's wrapper: the only open transaction, under the testing manager.
        $this->db()->setTransactionManager(new \Illuminate\Foundation\Testing\DatabaseTransactionsManager(['mysql']));
        $this->db()->beginTransaction();
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 1]);

        $this->db()->statement('CREATE TABLE _writer_policy_ddl (id INT)');

        // MySQL committed the wrapper implicitly, exactly as without the policy.
        // RefreshDatabase sees that at teardown and rebuilds the schema.
        $this->assertSame(1, $this->counter(1));
        $this->assertSame(1, $this->db()->transactionLevel());
        $this->assertFalse($this->db()->getPdo()->inTransaction());
        $this->assertFalse($this->tokenIsHeld());
        $this->db()->table('_writer_policy_probe')->where('id', 2)->update(['counter' => 2]);
        $this->assertSame(2, $this->counter(2));
        $this->db()->rollBack();
        $this->assertSame(0, $this->db()->transactionLevel());
        $this->assertSame([1, 2], [$this->counter(1), $this->counter(2)]);
        $this->admin->exec('DROP TABLE _writer_policy_ddl');

        // Afterwards the connection is an ordinary token connection again.
        $this->db()->transaction(function ($db) {
            $this->assertTrue($this->tokenIsHeld());
            $db->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 5]);
        });
        $this->assertSame(5, $this->counter(1));

        // A transaction the test or the application opened inside the wrapper still refuses DDL.
        $this->db()->beginTransaction();
        $this->db()->beginTransaction();
        $error = $this->failureOf(fn () => $this->db()->statement('CREATE TABLE _writer_policy_ddl (id INT)'));
        $this->assertInstanceOf(LogicException::class, $error);
        $this->db()->rollBack(0);
    }

    public function test_ddl_at_level_zero_is_never_wrapped(): void
    {
        $this->holdTokenOnAdmin();
        $this->db()->statement('CREATE TABLE IF NOT EXISTS _writer_policy_ddl (id INT)');
        $this->db()->statement('DROP TABLE _writer_policy_ddl');
        $this->releaseAdminToken();
        $this->addToAssertionCount(1);
    }

    public function test_locking_read_outside_a_transaction_is_refused(): void
    {
        try {
            $this->db()->table('_writer_policy_probe')->where('id', 1)->lockForUpdate()->first();
            $this->fail('A row lock outside a transaction protects nothing.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('outside a transaction', $error->getMessage());
        }

        $row = $this->db()->transaction(fn ($db) => $db->table('_writer_policy_probe')->where('id', 1)->lockForUpdate()->first());
        $this->assertSame(1, (int) $row->id);
    }

    public static function transactionControl(): array
    {
        return [['BEGIN'], ['COMMIT'], ['ROLLBACK'], ['START TRANSACTION'], ['SAVEPOINT x'], ['LOCK TABLES _writer_policy_probe WRITE'],
            ['/*!80000 COMMIT */']];
    }

    #[DataProvider('transactionControl')]
    public function test_raw_transaction_control_is_refused(string $sql): void
    {
        $this->expectException(LogicException::class);
        $this->db()->unprepared($sql);
    }

    public static function statementBatches(): array
    {
        return [
            'read then write' => ['SELECT 1; UPDATE _writer_policy_probe SET counter = 9 WHERE id = 1'],
            'write then write' => ['UPDATE _writer_policy_probe SET counter = 9 WHERE id = 2; UPDATE _writer_policy_probe SET counter = 9 WHERE id = 1'],
            'hidden behind two dashes' => ['SELECT --1; UPDATE _writer_policy_probe SET counter = 9 WHERE id = 1'],
        ];
    }

    #[DataProvider('statementBatches')]
    public function test_a_batch_of_statements_is_refused_before_any_of_it_runs(string $sql): void
    {
        $error = $this->failureOf(fn () => $this->db()->unprepared($sql));

        $this->assertInstanceOf(LogicException::class, $error, 'A batch must be refused, not classified by its first statement.');
        $this->assertStringContainsString('one statement at a time', $error->getMessage());
        $this->assertSame([0, 0], [$this->counter(1), $this->counter(2)]);
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_the_server_runs_one_statement_per_call_on_every_writer_session(): void
    {
        foreach (['mysql', 'mysql_cache_locks'] as $name) {
            // Raw PDO skips the classifier: the session itself must refuse a second statement.
            $error = $this->failureOf(fn () => DB::connection($name)->getPdo()
                ->exec('DO 0; UPDATE _writer_policy_probe SET counter = 9 WHERE id = 1'));
            $this->assertInstanceOf(\PDOException::class, $error, "The {$name} session must refuse a second statement.");
            $this->assertSame(1064, (int) $error->errorInfo[1], $error->getMessage());
        }
        $this->assertSame(0, $this->counter());
    }

    public function test_changed_session_isolation_fails_closed_at_the_next_write(): void
    {
        $this->db()->statement("SET SESSION transaction_isolation = 'REPEATABLE-READ'");
        $error = $this->failureOf(fn () => $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 9]));
        $this->assertInstanceOf(RuntimeException::class, $error, 'The token must refuse a session that left READ COMMITTED.');
        $this->assertNotInstanceOf(WriterTokenTimeout::class, $error);
        $this->assertStringContainsString('settings differ', $error->getMessage());
        $this->assertSame(0, $this->counter());
        // The refused session is discarded; a fresh one has the fixed settings again.
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 9]);
        $this->assertSame(9, $this->counter());
    }

    public function test_missing_token_row_stops_every_write_but_not_reads(): void
    {
        $this->admin->exec('DELETE FROM repro_mysql_control.writer_token');
        $calls = 0;
        foreach ([
            function () use (&$calls) {
                $this->db()->transaction(function () use (&$calls) {
                    $calls++;
                }, 3);
            },
            fn () => $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 4]),
            fn () => $this->db()->table('_writer_policy_probe')->insert(['counter' => 4]),
        ] as $write) {
            $error = $this->failureOf($write);
            $this->assertInstanceOf(RuntimeException::class, $error, 'A missing token row must stop the write.');
            $this->assertNotInstanceOf(WriterTokenTimeout::class, $error);
            $this->assertStringContainsString('row is missing', $error->getMessage());
        }
        $this->assertSame(0, $calls);
        $this->assertSame(0, $this->counter());
        $this->assertSame(2, $this->probeRows());
        $this->assertSame(2, $this->db()->table('_writer_policy_probe')->count());
    }

    #[DataProvider('commitStyles')]
    public function test_a_swallowed_lock_timeout_after_the_token_cannot_commit(string $style): void
    {
        // The admin session holds a business row (not the token), as a non-token session could.
        $this->admin->beginTransaction();
        $this->admin->exec('UPDATE _writer_policy_probe SET counter = 1 WHERE id = 2');
        $calls = 0;
        $body = function ($db) use (&$calls) {
            $calls++;
            $db->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 1]);
            $error = $this->failureOf(fn () => $db->table('_writer_policy_probe')->where('id', 2)->update(['counter' => 2]));
            $this->assertInstanceOf(QueryException::class, $error, 'The held business row must time out.');
            $this->assertSame(1205, (int) $error->errorInfo[1]);
            $error = $this->failureOf(fn () => $db->selectOne('SELECT 1'));
            $this->assertInstanceOf(RuntimeException::class, $error, 'A swallowed post-token error must refuse further statements.');
            $this->assertStringContainsString('requires an outer rollback', $error->getMessage());
        };
        try {
            $error = $this->failureOf(function () use ($style, $body) {
                if ($style === 'manual') {
                    $this->db()->beginTransaction();
                    $body($this->db());
                    $this->db()->commit();
                } else {
                    $this->db()->transaction($body, 3);
                }
            });
            $this->assertInstanceOf(RuntimeException::class, $error, 'A poisoned transaction cannot commit.');
            $this->assertStringContainsString('requires an outer rollback', $error->getMessage());
        } finally {
            $this->db()->rollBack(0);
            $this->admin->rollBack();
        }
        $this->assertSame(1, $calls, 'Errors after the token is held are never retried.');
        $this->assertSame(0, $this->counter());
        // The guard clears once rollback reaches level 0.
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 3]);
        $this->assertSame(3, $this->counter());
    }

    public function test_rollback_callbacks_after_a_lock_error_run_on_a_clean_connection(): void
    {
        // The admin session holds a business row, so the second write times out after the token.
        $this->admin->beginTransaction();
        $this->admin->exec('UPDATE _writer_policy_probe SET counter = 1 WHERE id = 2');
        $seen = [];
        try {
            // Not an arrow function: it would copy $seen before the callback writes to it.
            $error = $this->failureOf(function () use (&$seen) {
                $this->db()->transaction(function ($db) use (&$seen) {
                    $db->afterRollBack(function () use ($db, &$seen) {
                        // MySQL has already rolled back: a callback may query, as with Laravel's own rollback.
                        $seen[] = [$db->transactionLevel(), (int) $db->table('_writer_policy_probe')->where('id', 1)->value('counter')];
                    });
                    $db->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 7]);
                    $db->table('_writer_policy_probe')->where('id', 2)->update(['counter' => 2]);
                });
            });
        } finally {
            $this->admin->rollBack();
        }

        $this->assertInstanceOf(QueryException::class, $error, 'The caller sees the lock error, not a refusal raised by the callback.');
        $this->assertSame(1205, (int) $error->errorInfo[1]);
        $this->assertSame([[0, 0]], $seen);
        $this->assertSame(0, $this->db()->transactionLevel());
        $this->assertFalse($this->tokenIsHeld());
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 3]);
        $this->assertSame(3, $this->counter());
    }

    public static function sessionResets(): array
    {
        return [
            'lock error, then disconnect' => ['lock', 'disconnect'],
            'lost session, then reconnect' => ['lost', 'reconnect'],
        ];
    }

    #[DataProvider('sessionResets')]
    public function test_a_new_session_after_a_failed_transaction_is_usable_without_a_purge(string $failure, string $reset): void
    {
        $id = (int) $this->db()->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $committed = [];
        $this->db()->beginTransaction();
        $this->db()->afterCommit(function () use (&$committed) {
            $committed[] = 'failed transaction';
        });
        $this->db()->table('_writer_policy_probe')->where('id', 1)->increment('counter');
        if ($failure === 'lock') {
            $this->admin->beginTransaction();
            $this->admin->exec('UPDATE _writer_policy_probe SET counter = 1 WHERE id = 2');
            $error = $this->failureOf(fn () => $this->db()->table('_writer_policy_probe')->where('id', 2)->increment('counter'));
            $this->admin->rollBack();
        } else {
            self::rootPdo()->exec('KILL CONNECTION '.$id);
            $error = $this->failureOf(fn () => $this->db()->table('_writer_policy_probe')->where('id', 2)->increment('counter'));
        }
        $this->assertInstanceOf(QueryException::class, $error);
        $this->assertStringContainsString('requires an outer rollback', $this->failureOf(fn () => $this->db()->selectOne('SELECT 1'))?->getMessage() ?? '');

        // Laravel resets the depth with the session; the failed transaction went with it.
        $reset === 'reconnect' ? DB::reconnect('mysql') : DB::disconnect('mysql');

        $this->assertSame(0, $this->db()->transactionLevel());
        $this->db()->transaction(fn ($db) => $db->table('_writer_policy_probe')->where('id', 2)->increment('counter'));
        $this->assertSame([0, 1], [$this->counter(1), $this->counter(2)]);
        $this->assertSame([], $committed, 'Callbacks of the failed transaction never run.');
        $this->assertFalse($this->tokenIsHeld());
    }

    public static function commitStyles(): array
    {
        return ['manual' => ['manual'], 'closure' => ['closure']];
    }

    public function test_transaction_retries_only_its_own_acquisition_timeout_before_the_callback(): void
    {
        $this->holdTokenOnAdmin();
        $calls = 0;
        $started = microtime(true);
        try {
            $this->db()->transaction(function () use (&$calls) {
                $calls++;
            }, 3);
            $this->fail('Three acquisition timeouts must surface.');
        } catch (WriterTokenTimeout) {
            $this->assertSame(0, $calls);
        }
        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(14.5, $elapsed);
        $this->assertLessThan(20, $elapsed);
        $this->releaseAdminToken();

        $this->db()->transaction(function ($db) use (&$calls) {
            $calls++;
            $db->table('_writer_policy_probe')->where('id', 1)->increment('counter');
        }, 3);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->counter());
    }

    public function test_a_held_token_released_during_the_wait_costs_one_retry_and_one_callback(): void
    {
        $holder = $this->worker('hold', ['6']);
        $this->awaitMarker('acquired');
        $calls = 0;
        $started = microtime(true);
        // Row 2: the holder never touches it, so only the token can make this wait.
        $this->db()->transaction(function ($db) use (&$calls) {
            $calls++;
            $db->table('_writer_policy_probe')->where('id', 2)->increment('counter');
        }, 3);
        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $started);
        $this->assertSame('held', $this->workerResult($holder)['result']);
        $this->assertSame(1, $calls);
        $this->assertSame([1, 1], [$this->counter(1), $this->counter(2)]);
    }

    public static function timeoutLocations(): array
    {
        return ['inside the callback' => ['callback'], 'after commit' => ['after commit']];
    }

    #[DataProvider('timeoutLocations')]
    public function test_a_timeout_raised_by_business_code_is_never_an_acquisition_retry(string $where): void
    {
        $calls = 0;
        try {
            $this->db()->transaction(function ($db) use (&$calls, $where) {
                $calls++;
                $db->table('_writer_policy_probe')->where('id', 1)->increment('counter');
                if ($where === 'callback') {
                    throw new WriterTokenTimeout('Unrelated business failure.');
                }
                $db->afterCommit(fn () => throw new WriterTokenTimeout('After commit failure.'));
            }, 3);
            $this->fail('The failure must escape.');
        } catch (WriterTokenTimeout) {
            $this->assertSame(1, $calls);
        }
        $this->assertSame($where === 'after commit' ? 1 : 0, $this->counter());
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_nested_savepoints_keep_the_token_and_defer_callbacks_to_the_outer_commit(): void
    {
        $callbacks = [];
        $this->db()->beginTransaction();
        $this->db()->afterCommit(function () use (&$callbacks) {
            // Our own transaction, and so our hold on the token, has ended. (The
            // waiting peer may already hold the token by now.)
            $callbacks[] = ['outer', $this->db()->getPdo()->inTransaction()];
        });
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 1]);
        $this->db()->beginTransaction();
        $this->db()->afterCommit(function () use (&$callbacks) {
            $callbacks[] = ['rolled-back-inner', null];
        });
        $this->db()->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 99]);
        $this->db()->rollBack();
        $this->assertSame(1, $this->db()->transactionLevel());
        $this->assertTrue($this->tokenIsHeld());

        $peer = $this->worker('increment');
        $this->awaitMarker('ready');
        usleep(300_000);
        $this->assertSame(0, $this->counter());
        $this->db()->commit();

        // After-commit callbacks run once the token is free again.
        $this->assertSame([['outer', false]], $callbacks);
        $this->assertSame(2, $this->workerResult($peer)['result']);
    }

    public function test_lost_connection_at_begin_runs_no_business_and_the_next_call_reconnects(): void
    {
        $id = (int) $this->db()->selectOne('SELECT CONNECTION_ID() AS id')->id;
        self::rootPdo()->exec('KILL CONNECTION '.$id);
        $calls = 0;
        $reconnects = 0;
        $this->db()->setReconnector(function ($connection) use (&$reconnects) {
            $reconnects++;
            $connection->setPdo(app('db.factory')->make($connection->getConfig(), $connection->getName())->getRawPdo());
        });
        $error = $this->failureOf(function () use (&$calls) {
            $this->db()->transaction(function () use (&$calls) {
                $calls++;
            }, 3);
        });
        $this->assertNotNull($error, 'A lost connection at BEGIN must fail this transaction.');
        $this->assertNotInstanceOf(WriterTokenTimeout::class, $error);
        $this->assertSame(0, $calls);
        $this->assertSame(0, $reconnects, 'BEGIN must not reconnect and run the business callback.');
        $this->assertSame(0, $this->db()->transactionLevel());

        $this->db()->transaction(fn ($db) => $db->table('_writer_policy_probe')->where('id', 1)->increment('counter'));
        $this->assertSame(1, $this->counter());
        $this->assertSame(1, $reconnects);
    }

    public function test_lost_connection_inside_a_transaction_never_replays_and_refuses_until_rollback(): void
    {
        $id = (int) $this->db()->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $root = self::rootPdo();
        $calls = 0;
        $callbacks = [];
        $error = $this->failureOf(function () use (&$calls, &$callbacks, $root, $id) {
            $this->db()->transaction(function ($db) use (&$calls, &$callbacks, $root, $id) {
                $calls++;
                $db->afterCommit(function () use (&$callbacks) {
                    $callbacks[] = 'commit';
                });
                $db->table('_writer_policy_probe')->where('id', 1)->increment('counter');
                $root->exec('KILL CONNECTION '.$id);
                // Swallowed by business code: the connection must still refuse to continue.
                $this->assertInstanceOf(QueryException::class,
                    $this->failureOf(fn () => $db->table('_writer_policy_probe')->where('id', 2)->increment('counter')));
                $db->table('_writer_policy_probe')->insert(['counter' => 5]);
            }, 3);
        });
        $this->assertInstanceOf(RuntimeException::class, $error, 'A lost connection must fail the transaction.');
        $this->assertStringContainsString('requires an outer rollback', $error->getMessage());
        $this->assertSame(1, $calls);
        $this->assertSame([], $callbacks);
        $this->assertSame([0, 0, 2], [$this->counter(1), $this->counter(2), $this->probeRows()]);
        $this->assertSame(0, $this->db()->transactionLevel());

        $this->db()->table('_writer_policy_probe')->where('id', 1)->increment('counter');
        $this->assertSame(1, $this->counter());
    }

    #[DataProvider('commitStyles')]
    public function test_lost_commit_acknowledgement_is_reported_once_without_callbacks_or_replay(string $style): void
    {
        $calls = 0;
        $callbacks = [];
        $listener = function (TransactionCommitting $event) {
            // A real COMMIT whose acknowledgement is then lost.
            $this->assertTrue($event->connection->getPdo()->commit());
            throw new \PDOException('Synthetic lost acknowledgement after a real COMMIT.');
        };
        $this->db()->getEventDispatcher()->listen(TransactionCommitting::class, $listener);
        $body = function ($db) use (&$calls, &$callbacks) {
            $calls++;
            $db->afterCommit(function () use (&$callbacks) {
                $callbacks[] = 'commit';
            });
            $db->afterRollBack(function () use (&$callbacks) {
                $callbacks[] = 'rollback';
            });
            $db->table('_writer_policy_probe')->where('id', 1)->increment('counter');
        };
        $error = $this->failureOf(function () use ($style, $body) {
            if ($style === 'manual') {
                $this->db()->beginTransaction();
                $body($this->db());
                $this->db()->commit();
            } else {
                $this->db()->transaction($body, 3);
            }
        });
        $this->assertInstanceOf(\PDOException::class, $error, 'An uncertain commit cannot report success.');
        $this->assertSame('Synthetic lost acknowledgement after a real COMMIT.', $error->getMessage());
        $this->db()->getEventDispatcher()->forget(TransactionCommitting::class);

        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->counter());
        $this->assertSame([], $callbacks, 'An uncertain outcome must claim neither commit nor rollback.');
        $this->assertSame(0, $this->db()->transactionLevel());
        $this->assertSame(0, app('db.transactions')->getPendingTransactions()->count());
        $this->assertFalse($this->tokenIsHeld());

        $this->db()->transaction(fn ($db) => $db->table('_writer_policy_probe')->where('id', 1)->increment('counter'));
        $this->assertSame(2, $this->counter());
    }

    public function test_a_long_token_hold_is_reported(): void
    {
        config(['database.connections.mysql.repro_writer_long_hold_ms' => 200]);
        DB::purge('mysql');
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$logged) {
            $logged[] = [$event->level, $event->message, $event->context];
        });

        $this->db()->transaction(fn ($db) => $db->table('_writer_policy_probe')->where('id', 1)->increment('counter'));
        $this->db()->transaction(function ($db) {
            $db->table('_writer_policy_probe')->where('id', 1)->increment('counter');
            usleep(400_000);
        });

        $reports = array_values(array_filter($logged, fn ($entry) => str_contains($entry[1], 'writer token was held')));
        $this->assertCount(1, $reports);
        $this->assertSame('warning', $reports[0][0]);
        $this->assertGreaterThanOrEqual(400, $reports[0][2]['held_ms']);
        $this->assertSame('mysql', $reports[0][2]['connection']);
    }
}
