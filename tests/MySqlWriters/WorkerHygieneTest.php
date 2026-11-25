<?php

namespace Tests\MySqlWriters;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\MySqlWriters\Concerns\WriterFixture;
use Tests\MySqlWriters\Jobs\ProbeJob;
use Tests\TestCase;

/** Long-lived workers never keep the token after a job, and long holders are visible. */
final class WorkerHygieneTest extends TestCase
{
    use WriterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWriterFixture();
        $this->admin->exec('DELETE FROM jobs');
    }

    protected function tearDown(): void
    {
        $this->tearDownWriterFixture();
        parent::tearDown();
    }

    public function test_a_worker_rolls_back_a_transaction_a_job_left_open_and_carries_on(): void
    {
        $queue = app('queue')->connection('database');
        $queue->push(new ProbeJob(1, leaveTransactionOpen: true));
        $queue->push(new ProbeJob(2));

        $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'database', '--stop-when-empty',
            '--sleep=0', '--tries=3', '--timeout=60'], base_path(), ['QUEUE_CONNECTION' => 'database']);
        $worker->setTimeout(60);
        $worker->run();

        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
        $this->assertStringContainsString('left a database transaction open', $worker->getErrorOutput());
        // The leaked work was rolled back; the next job ran on a clean session and committed.
        $this->assertSame(0, $this->counter(1));
        $this->assertSame(1, $this->counter(2));
        // The leaking job is not lost: its delete rolled back too, so it stays reserved for a retry.
        $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL')->fetchColumn());
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_the_watchdog_reports_a_token_holder_older_than_the_limit(): void
    {
        config(['database.connections.mysql_monitor' => [
            'driver' => 'mysql', 'unix_socket' => '/fixture-socket/mysql.sock', 'database' => 'repro_mysql_test',
            'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin',
            'prefix' => '', 'strict' => true, 'repro_writer_policy' => false,
        ]]);
        $this->assertSame(0, Artisan::call('db:writer-token-holders', ['--connection' => 'mysql_monitor', '--older-than' => 1]));
        $this->assertSame([], json_decode(Artisan::output(), true)['holders']);

        $holder = $this->worker('hold');
        $this->awaitMarker('acquired');
        usleep(2_200_000);
        $this->assertSame(1, Artisan::call('db:writer-token-holders', ['--connection' => 'mysql_monitor', '--older-than' => 1]));
        $report = json_decode(Artisan::output(), true);
        $this->assertCount(1, $report['holders']);
        $this->assertGreaterThanOrEqual(2, $report['holders'][0]['age_seconds']);
        $this->assertSame('repro_test', $report['holders'][0]['user']);
        $this->assertIsInt($report['holders'][0]['thread_id']);

        // A holder younger than the limit is listed only when asked for everything.
        $this->assertSame(0, Artisan::call('db:writer-token-holders', ['--connection' => 'mysql_monitor', '--older-than' => 60]));
        $this->assertSame([], json_decode(Artisan::output(), true)['holders']);

        $this->mark('release');
        $this->assertSame('held', $this->workerResult($holder)['result']);
        $this->assertSame(0, Artisan::call('db:writer-token-holders', ['--connection' => 'mysql_monitor', '--older-than' => 1]));
        DB::purge('mysql_monitor');
    }
}
