<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Support\RenameUserJob;
use Tests\TestCase;

/**
 * A long-lived worker must not carry a transaction from one job into the next.
 * The check compares each job's transaction depth with the depth it started at,
 * so a sync job inside its caller's transaction is left alone. Both engines.
 */
class LeftoverTransactionCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function job(): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ExampleLeakyJob');
        $job->shouldReceive('getJobId')->andReturn('42');
        $job->shouldReceive('payload')->andReturn([]);

        return $job;
    }

    private function useMode(string $mode): void
    {
        config(['database.repro_leftover_transactions' => $mode]);
    }

    public function test_a_job_that_leaves_a_transaction_open_is_rolled_back_to_its_starting_depth(): void
    {
        $this->useMode('rollback');
        Log::spy();
        $start = DB::transactionLevel();
        $job = $this->job();

        event(new JobProcessing('database', $job));
        DB::beginTransaction();
        DB::beginTransaction();
        event(new JobProcessed('database', $job));

        $this->assertSame($start, DB::transactionLevel());
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => str_contains($message, 'left a database transaction open')
            && $context['job'] === 'App\\Jobs\\ExampleLeakyJob' && $context['levels_left_open'] === 2 && $context['action'] === 'rollback');
    }

    public function test_the_rollback_happens_before_a_failed_job_is_released(): void
    {
        $this->useMode('rollback');
        $start = DB::transactionLevel();
        $job = $this->job();

        event(new JobProcessing('database', $job));
        DB::beginTransaction();
        event(new JobExceptionOccurred('database', $job, new RuntimeException('Job failed.')));

        $this->assertSame($start, DB::transactionLevel());
    }

    public function test_a_job_that_leaks_a_transaction_and_fails_for_good_stays_deleted(): void
    {
        $this->useMode('rollback');
        $queue = app('queue')->connection('database');
        $queue->push(new RenameUserJob(1, 'Never'));
        $job = $queue->pop();
        $this->assertInstanceOf(DatabaseJob::class, $job);
        $start = DB::transactionLevel();

        event(new JobProcessing('database', $job));
        DB::beginTransaction();
        // The worker on a last try: delete the job, run its failed handler, then fire JobFailed.
        $job->fail(new RuntimeException('Failed for good.'));

        $this->assertSame($start, DB::transactionLevel());
        $this->assertSame(0, DB::table('jobs')->count(),
            'The rollback must not bring back a job Laravel failed and deleted: the worker would fail it again.');
    }

    public function test_log_mode_reports_without_rolling_back(): void
    {
        $this->useMode('log');
        Log::spy();
        $start = DB::transactionLevel();
        $job = $this->job();

        event(new JobProcessing('database', $job));
        DB::beginTransaction();
        event(new JobProcessed('database', $job));

        $this->assertSame($start + 1, DB::transactionLevel());
        DB::rollBack();
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_sync_job_inside_its_callers_transaction_is_left_alone(): void
    {
        $this->useMode('rollback');
        Log::spy();
        $user = User::factory()->create(['name' => 'Before']);

        DB::transaction(function () use ($user) {
            Queue::connection('sync')->push(new RenameUserJob($user->id, 'Inside'));
            $this->assertSame('Inside', $user->refresh()->name);
            $this->assertGreaterThan(0, DB::transactionLevel());
            $user->refresh()->update(['name' => 'After']);
        });

        $this->assertSame('After', $user->refresh()->name);
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_worker_loop_starts_every_job_at_depth_zero(): void
    {
        $this->useMode('rollback');
        Log::spy();
        $start = DB::transactionLevel();
        DB::beginTransaction();

        event(new Looping('database', 'default'));

        // A daemon loop never runs inside a caller's transaction: everything open is a leak.
        $this->assertSame(0, DB::transactionLevel());
        Log::shouldHaveReceived('error')->once();
        if ($start > 0) {
            // Re-open the test wrapper the loop just closed, so teardown can roll it back.
            DB::beginTransaction();
        }
    }

    public function test_an_in_process_scheduled_task_that_leaves_a_transaction_open_is_rolled_back(): void
    {
        $this->useMode('rollback');
        Log::spy();
        $start = DB::transactionLevel();
        $task = new ScheduledEvent(Mockery::mock(EventMutex::class), 'php artisan inspire');

        event(new ScheduledTaskStarting($task));
        DB::beginTransaction();
        event(new ScheduledTaskFailed($task, new RuntimeException('Task failed.')));
        $this->assertSame($start, DB::transactionLevel());

        event(new ScheduledTaskStarting($task));
        DB::beginTransaction();
        event(new ScheduledTaskFinished($task, 0.1));
        $this->assertSame($start, DB::transactionLevel());
        Log::shouldHaveReceived('error')->twice();
    }

    public function test_default_mode_rolls_back_on_mysql_and_only_logs_on_sqlite(): void
    {
        $load = function (string $engine): array {
            $saved = getenv('DB_CONNECTION');
            putenv('DB_CONNECTION='.$engine);
            $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = $engine;
            try {
                return require config_path('database.php');
            } finally {
                putenv('DB_CONNECTION='.$saved);
                $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = $saved;
            }
        };

        $this->assertSame('rollback', $load('mysql')['repro_leftover_transactions']);
        $this->assertSame('log', $load('sqlite')['repro_leftover_transactions']);
    }
}
