<?php

namespace Tests\MySql;

use App\Jobs\DispatchScheduledMessages;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class DatabaseQueueContractTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_independent_mysql_polls_reserve_a_job_only_once_without_executing_it(): void
    {
        $name = 'migration-probe-'.Str::uuid();
        $queue = $this->app['queue']->connection('database');
        $this->assertSame(DatabaseQueue::class, $queue::class);
        $id = $queue->push(new DispatchScheduledMessages, '', $name);
        $directory = sys_get_temp_dir().'/migration-queue-'.Str::uuid();
        mkdir($directory, 0700);
        $processes = [];
        try {
            foreach (['a', 'b'] as $slot) {
                $process = new Process([PHP_BINARY, __DIR__.'/support/queue-reservation-worker.php', $name, $directory, $slot]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/a.ready') || ! is_file($directory.'/b.ready')) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($directory.'/a.ready');
            $this->assertFileExists($directory.'/b.ready');
            file_put_contents($directory.'/go', '1');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $reserved = array_values(array_filter($results, fn ($result) => $result['id'] !== null));
            $this->assertCount(1, $reserved);
            $this->assertSame((string) $id, $reserved[0]['id']);
            $record = DB::table('jobs')->where('queue', $name)->sole();
            $this->assertSame(1, (int) $record->attempts);
            $this->assertNotNull($record->reserved_at);
            $this->assertNull($queue->pop($name));
            $queue->deleteReserved($name, $id);
            $this->assertSame(0, DB::table('jobs')->where('queue', $name)->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_studio_release_preserves_queue_payload_and_attempts_until_the_delay_expires(): void
    {
        $this->travelTo(now()->startOfSecond());
        try {
            $name = 'migration-probe-'.Str::uuid();
            $queue = $this->app['queue']->connection('studio');
            $queue->push(new DispatchScheduledMessages, '', $name);
            $job = $queue->pop($name);
            $payload = $job->getRawBody();
            $job->release(30);
            $record = DB::table('jobs')->where('queue', $name)->sole();
            $this->assertNull($record->reserved_at);
            $this->assertSame(1, (int) $record->attempts);
            $this->assertSame($payload, $record->payload);
            $this->assertNull($queue->pop($name));
            $this->travel(31)->seconds();
            $retry = $queue->pop($name);
            $this->assertNotNull($retry);
            $this->assertSame(2, $retry->attempts());
            $this->assertSame($payload, $retry->getRawBody());
            $retry->delete();
            $this->assertSame(0, DB::table('jobs')->where('queue', $name)->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_studio_enqueues_only_after_commit_and_discards_rolled_back_dispatches(): void
    {
        $name = 'migration-probe-'.Str::uuid();
        $queue = $this->app['queue']->connection('studio');
        DB::beginTransaction();
        $queue->push(new DispatchScheduledMessages, '', $name);
        $this->assertSame(0, DB::table('jobs')->where('queue', $name)->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('jobs')->where('queue', $name)->count());
        DB::beginTransaction();
        $queue->push(new DispatchScheduledMessages, '', $name);
        $this->assertSame(0, DB::table('jobs')->where('queue', $name)->count());
        DB::commit();
        $this->assertSame(1, DB::table('jobs')->where('queue', $name)->count());
        $queue->pop($name)->delete();
    }

    public function test_failed_job_provider_keeps_and_removes_a_synthetic_payload(): void
    {
        $name = 'migration-probe-'.Str::uuid();
        $queue = $this->app['queue']->connection('database');
        $queue->push(new DispatchScheduledMessages, '', $name);
        $job = $queue->pop($name);
        $payload = $job->getRawBody();
        $job->delete();
        $failer = $this->app['queue.failer'];
        $uuid = $failer->log('database', $name, $payload, new \RuntimeException('Local migration failure-record probe.'));
        $stored = $failer->find($uuid);
        $this->assertSame($name, $stored->queue);
        $this->assertSame($payload, $stored->payload);
        $this->assertTrue($failer->forget($uuid));
        $this->assertNull($failer->find($uuid));
    }
}
