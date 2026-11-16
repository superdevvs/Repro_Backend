<?php

namespace Tests\Feature;

use App\Jobs\DispatchScheduledMessages;
use App\Queue\SqliteDatabaseQueue;
use App\Support\LockedWrite;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SqliteDatabaseQueueConcurrencyTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'automation-queue-');
        $database = ['driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '', 'journal_mode' => 'WAL', 'busy_timeout' => 1000];
        config([
            'database.connections.automation_queue_test' => $database,
            'database.connections.automation_queue_racer' => $database,
            'queue.connections.automation_queue_test' => ['driver' => 'database', 'connection' => 'automation_queue_test', 'table' => 'jobs', 'queue' => 'mail', 'retry_after' => 120, 'after_commit' => false],
        ]);
        Schema::connection('automation_queue_test')->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        DB::connection('automation_queue_racer')->getPdo();
        Event::fake([JobFailed::class]);
    }

    protected function tearDown(): void
    {
        DB::purge('automation_queue_racer');
        DB::purge('automation_queue_test');
        foreach ([$this->databaseFile, $this->databaseFile.'-wal', $this->databaseFile.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_reservation_retries_a_real_stale_wal_snapshot_without_failing_or_losing_the_job(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $this->assertInstanceOf(SqliteDatabaseQueue::class, $queue);
        $id = $queue->push(new DispatchScheduledMessages);
        $races = 0;
        DB::listen(function (QueryExecuted $query) use (&$races) {
            if ($races === 0 && $query->connectionName === 'automation_queue_test' && str_starts_with(strtolower($query->sql), 'select')) {
                $races++;
                DB::connection('automation_queue_racer')->table('jobs')->decrement('available_at');
            }
        });

        $job = $queue->pop('mail');

        $this->assertSame(1, $races);
        $this->assertSame((string) $id, (string) $job->getJobId());
        $this->assertSame(1, $job->attempts());
        $this->assertNull($queue->pop('mail'));
        Event::assertNotDispatched(JobFailed::class);
        $job->delete();
        $this->assertSame(0, DB::connection('automation_queue_test')->table('jobs')->count());
    }

    /**
     * A writer committing after every read used to exhaust the bounded reservation retries (the
     * claim read, then wrote inside a stale WAL snapshot). The claim is now one atomic statement,
     * so the same pressure no longer stops a worker from taking the job.
     */
    public function test_a_writer_committing_after_every_read_no_longer_blocks_the_claim(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $id = $queue->push(new DispatchScheduledMessages);
        $this->raceAfterEveryRead();

        $job = $queue->pop('mail');

        $this->assertSame((string) $id, (string) $job->getJobId());
        $this->assertSame(1, $job->attempts());
        $record = DB::connection('automation_queue_test')->table('jobs')->find($id);
        $this->assertNotNull($record->reserved_at);
        $this->assertSame(1, $record->attempts);
        Event::assertNotDispatched(JobFailed::class);
    }

    /** Before: the finished job's row stayed reserved and the job ran again after retry_after. */
    public function test_finishing_a_job_survives_a_writer_committing_between_read_and_write(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $queue->push(new DispatchScheduledMessages);
        $job = $queue->pop('mail');
        $this->raceAfterEveryRead();

        $job->delete();

        $this->assertSame(0, DB::connection('automation_queue_test')->table('jobs')->count());
    }

    public function test_releasing_a_job_survives_a_writer_committing_between_read_and_write(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $queue->push(new DispatchScheduledMessages);
        $job = $queue->pop('mail');
        $this->raceAfterEveryRead();

        $job->release(30);

        $record = DB::connection('automation_queue_test')->table('jobs')->sole();
        $this->assertNull($record->reserved_at);
        $this->assertSame(1, $record->attempts);
        $this->assertGreaterThanOrEqual(time() + 29, $record->available_at);
        Event::assertNotDispatched(JobFailed::class);
    }

    /** Idle workers poll constantly; an empty poll must not wait for, or take, SQLite's writer lock. */
    public function test_an_idle_poll_never_waits_for_the_writer_lock(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $racer = DB::connection('automation_queue_racer')->getPdo();
        $racer->exec('BEGIN IMMEDIATE');
        try {
            $started = microtime(true);
            $this->assertNull($queue->pop('mail'));
            $this->assertLessThan(0.5, microtime(true) - $started, 'The idle poll waited on the busy timeout.');
        } finally {
            $racer->exec('ROLLBACK');
        }
    }

    public function test_claims_follow_queue_order_delays_and_expired_reservations(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $first = $queue->push(new DispatchScheduledMessages);
        $second = $queue->push(new DispatchScheduledMessages);
        $other = $queue->push(new DispatchScheduledMessages, '', 'other');
        $delayed = $queue->later(60, new DispatchScheduledMessages);

        $this->assertSame((string) $first, (string) $queue->pop('mail')->getJobId());
        $this->assertSame((string) $second, (string) $queue->pop('mail')->getJobId());
        $this->assertNull($queue->pop('mail'), 'A delayed job is not claimed early and another queue is not touched.');
        $this->assertSame((string) $other, (string) $queue->pop('other')->getJobId());

        $this->travel(121)->seconds();
        $reclaimed = $queue->pop('mail');
        $this->assertSame((string) $first, (string) $reclaimed->getJobId(), 'An expired reservation is reclaimed first.');
        $this->assertSame(2, $reclaimed->attempts());
        $this->assertSame((string) $second, (string) $queue->pop('mail')->getJobId());
        $this->assertSame((string) $delayed, (string) $queue->pop('mail')->getJobId());
    }

    private function raceAfterEveryRead(): void
    {
        DB::listen(function (QueryExecuted $query) {
            if ($query->connectionName === 'automation_queue_test' && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                DB::connection('automation_queue_racer')->table('jobs')->increment('created_at');
            }
        });
    }

    public function test_release_preserves_a_single_job_and_its_queue(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $queue->push(new DispatchScheduledMessages);
        $job = $queue->pop('mail');
        $job->release(30);
        $record = DB::connection('automation_queue_test')->table('jobs')->sole();
        $this->assertSame('mail', $record->queue);
        $this->assertNull($record->reserved_at);
        $this->assertSame(1, $record->attempts);
        $this->assertNull($queue->pop('mail'));
        Event::assertNotDispatched(JobFailed::class);
    }
}
