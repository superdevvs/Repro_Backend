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

    public function test_exhausted_reservation_races_leave_the_original_job_available_for_the_next_poll(): void
    {
        $queue = $this->app['queue']->connection('automation_queue_test');
        $id = $queue->push(new DispatchScheduledMessages);
        $races = 0;
        $raceEnabled = true;
        DB::listen(function (QueryExecuted $query) use (&$races, &$raceEnabled) {
            if ($raceEnabled && $query->connectionName === 'automation_queue_test' && str_starts_with(strtolower($query->sql), 'select')) {
                $races++;
                DB::connection('automation_queue_racer')->table('jobs')->decrement('available_at');
            }
        });
        try {
            $queue->pop('mail');
            $this->fail('The competing writer should exhaust bounded reservation retries.');
        } catch (QueryException $exception) {
            $this->assertTrue(LockedWrite::isLockContention($exception));
        } finally {
            $raceEnabled = false;
        }

        $this->assertSame(LockedWrite::DEFAULT_ATTEMPTS, $races);
        $record = DB::connection('automation_queue_test')->table('jobs')->find($id);
        $this->assertNotNull($record);
        $this->assertNull($record->reserved_at);
        $this->assertSame(0, $record->attempts);
        Event::assertNotDispatched(JobFailed::class);
        $this->assertSame((string) $id, (string) $queue->pop('mail')->getJobId());
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
