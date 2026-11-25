<?php

namespace Tests\MySqlWriters\Concerns;

use App\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;

/**
 * Shared fixture for the multi-process writer suite: real committed rows, a
 * separate admin session, and worker processes that boot the application.
 */
trait WriterFixture
{
    protected PDO $admin;

    protected string $markers;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUpWriterFixture(): void
    {
        $this->admin = self::adminPdo();
        // Every test starts from one committed token row and a fresh probe table.
        $this->admin->exec('DELETE FROM repro_mysql_control.writer_token');
        $this->admin->exec("INSERT INTO repro_mysql_control.writer_token VALUES (1, 'repro_mysql_test', 1)");
        $this->admin->exec('DROP TABLE IF EXISTS _writer_policy_probe');
        $this->admin->exec('CREATE TABLE _writer_policy_probe (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            counter INT UNSIGNED NOT NULL DEFAULT 0, note VARCHAR(64) NULL UNIQUE) ENGINE=InnoDB');
        $this->admin->exec('INSERT INTO _writer_policy_probe (id, counter) VALUES (1, 0), (2, 0)');
        $this->markers = sys_get_temp_dir().'/repro-writer-policy-'.bin2hex(random_bytes(8));
        mkdir($this->markers, 0700);

        $connection = DB::connection('mysql');
        $this->assertInstanceOf(MySqlConnection::class, $connection);
        $this->assertTrue($connection->getConfig('repro_writer_policy'));
        $this->assertSame(0, $connection->transactionLevel());
    }

    protected function tearDownWriterFixture(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0, 9);
            }
        }
        // Close every application session first so nothing keeps a metadata lock.
        foreach (array_keys(app('db')->getConnections()) as $name) {
            DB::purge($name);
        }
        if (isset($this->admin)) {
            if ($this->admin->inTransaction()) {
                $this->admin->rollBack();
            }
            $this->admin->exec('DROP TABLE IF EXISTS _writer_policy_probe');
        }
        if (isset($this->markers) && is_dir($this->markers)) {
            foreach (glob($this->markers.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->markers);
        }
    }

    protected static function adminPdo(): PDO
    {
        $admin = new PDO('mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test;charset=utf8mb4',
            'repro_token_fixture', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('SET SESSION lock_wait_timeout = 20, SESSION innodb_lock_wait_timeout = 20');

        return $admin;
    }

    /** The disposable daemon's passwordless local root, used only to kill sessions and read lock tables. */
    protected static function rootPdo(): PDO
    {
        $root = new PDO('mysql:unix_socket=/fixture-socket/mysql.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame(getenv('REPRO_MYSQL_EXPECTED_SERVER_UUID'), $root->query('SELECT @@server_uuid')->fetchColumn());

        return $root;
    }

    /** Hold the token from a separate session, as another writer would. */
    protected function holdTokenOnAdmin(): void
    {
        $this->admin->beginTransaction();
        $this->admin->query('SELECT id FROM repro_mysql_control.writer_token WHERE id = 1 FOR UPDATE')->closeCursor();
    }

    protected function releaseAdminToken(): void
    {
        $this->admin->rollBack();
    }

    /** True when another session holds the token right now. */
    protected function tokenIsHeld(): bool
    {
        $probe = self::adminPdo();
        $probe->beginTransaction();
        try {
            $probe->query('SELECT id FROM repro_mysql_control.writer_token WHERE id = 1 FOR UPDATE NOWAIT')->closeCursor();

            return false;
        } catch (PDOException $error) {
            $this->assertSame(3572, (int) $error->errorInfo[1], $error->getMessage());

            return true;
        } finally {
            $probe->rollBack();
        }
    }

    protected function counter(int $id = 1): int
    {
        return (int) $this->admin->query('SELECT counter FROM _writer_policy_probe WHERE id = '.$id)->fetchColumn();
    }

    protected function probeRows(): int
    {
        return (int) $this->admin->query('SELECT COUNT(*) FROM _writer_policy_probe')->fetchColumn();
    }

    protected function worker(string $mode, array $arguments = [], int $timeout = 240): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/support/writer-worker.php', $mode, $this->markers, ...$arguments]);
        $process->setTimeout($timeout);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    /** Generous: a worker boots the whole application, which is slow on a loaded host. */
    protected function awaitMarker(string $name, float $seconds = 120): void
    {
        $deadline = microtime(true) + $seconds;
        while (! is_file($this->markers.'/'.$name) && microtime(true) < $deadline) {
            foreach ($this->workers as $worker) {
                if (! $worker->isRunning() && ! $worker->isSuccessful()) {
                    $this->fail('A worker stopped early: '.$worker->getErrorOutput().$worker->getOutput());
                }
            }
            usleep(10_000);
        }
        $this->assertFileExists($this->markers.'/'.$name);
    }

    /** The exception a call raises, without ever swallowing PHPUnit's own failures. */
    protected function failureOf(\Closure $call): ?\Throwable
    {
        try {
            $call();

            return null;
        } catch (\PHPUnit\Framework\Exception $failure) {
            throw $failure;
        } catch (\Throwable $error) {
            return $error;
        }
    }

    protected function mark(string $name): void
    {
        file_put_contents($this->markers.'/'.$name, '1');
    }

    protected function workerResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
