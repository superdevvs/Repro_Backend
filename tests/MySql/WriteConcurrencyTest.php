<?php

namespace Tests\MySql;

use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WriteConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private string $directory;
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('repro_mysql_test', getenv('DB_DATABASE'));
        self::assertSame('repro_test', getenv('DB_USERNAME'));
        $this->pdo = new PDO('mysql:host='.getenv('DB_HOST').';dbname=repro_mysql_test;charset=utf8mb4', 'repro_test', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE _mysql_migration_concurrency_probe (id BIGINT PRIMARY KEY, counter BIGINT NOT NULL) ENGINE=InnoDB');
        $this->pdo->exec('INSERT INTO _mysql_migration_concurrency_probe VALUES (1,0),(2,0)');
        $this->directory = sys_get_temp_dir().'/repro-mysql-concurrency-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS _mysql_migration_concurrency_probe');
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function worker(string $mode, int $id, int $loops = 1): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/support/write-worker.php', $mode, (string) $id, $this->directory, (string) $loops]);
        $process->setTimeout(12);
        $this->processes[] = $process;
        $process->start();

        return $process;
    }

    public function test_a_writer_can_commit_to_another_row_while_the_first_writer_holds_its_lock(): void
    {
        $first = $this->worker('hold', 1);
        $deadline = microtime(true) + 4;
        while (! is_file($this->directory.'/locked') && $first->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists($this->directory.'/locked');
        $second = $this->worker('increment', 2);
        $second->setTimeout(2);
        $second->wait();
        self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
        self::assertTrue($first->isRunning());
        file_put_contents($this->directory.'/release', '1');
        $first->wait();
        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        self::assertSame([1, 1], array_map('intval', $this->pdo->query('SELECT counter FROM _mysql_migration_concurrency_probe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function test_simultaneous_read_modify_write_transactions_do_not_lose_updates(): void
    {
        $first = $this->worker('increment', 1, 50);
        $second = $this->worker('increment', 1, 50);
        foreach ([$first, $second] as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        self::assertSame(100, (int) $this->pdo->query('SELECT counter FROM _mysql_migration_concurrency_probe WHERE id=1')->fetchColumn());
    }
}
