<?php

namespace Tests\MySqlWriters;

use App\Database\WriterTokenTimeout;
use App\Http\Middleware\RetryDatabaseThrottleRequests;
use App\Queue\MySqlDatabaseQueue;
use App\Support\LockedWrite;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\MySqlWriters\Concerns\WriterFixture;
use Tests\MySqlWriters\Jobs\ProbeJob;
use Tests\TestCase;

/**
 * Which writers skip the token, and why none of them can let business writes
 * overlap or deadlock against their own caller. Real committed rows; the token
 * holder is a separate session or process.
 */
final class WriterExemptionsTest extends TestCase
{
    use WriterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWriterFixture();
        $this->admin->exec('DELETE FROM jobs');
        $this->admin->exec('DELETE FROM cache');
        $this->admin->exec('DELETE FROM cache_locks');
    }

    protected function tearDown(): void
    {
        if (DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack(0);
        }
        $this->tearDownWriterFixture();
        parent::tearDown();
    }

    private function jobs(): int
    {
        return (int) $this->admin->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    }

    public function test_queue_claim_and_delete_skip_the_token_while_enqueue_waits_for_it(): void
    {
        $queue = app('queue')->connection('database');
        $this->assertInstanceOf(MySqlDatabaseQueue::class, $queue);
        $queue->push(new ProbeJob(1));
        $this->assertSame(1, $this->jobs());

        $this->holdTokenOnAdmin();
        $started = microtime(true);
        $job = $queue->pop();
        $this->assertNotNull($job);
        $job->delete();
        $this->assertLessThan(2, microtime(true) - $started, 'Twelve workers must not queue behind the token to take or finish jobs.');
        $this->assertSame(0, $this->jobs());

        try {
            $queue->push(new ProbeJob(1));
            $this->fail('A new job is a business write and waits for the token.');
        } catch (WriterTokenTimeout) {
        }
        $this->assertSame(0, $this->jobs());
        $this->releaseAdminToken();
    }

    public function test_a_job_dispatched_inside_a_transaction_rolls_back_with_it(): void
    {
        $error = $this->failureOf(fn () => DB::transaction(function () {
            app('queue')->connection('database')->push(new ProbeJob(1));
            DB::table('_writer_policy_probe')->where('id', 1)->increment('counter');
            $this->assertSame(0, $this->jobs(), 'The enqueue is invisible until the business commit.');
            throw new RuntimeException('Reject the business work.');
        }));

        $this->assertSame('Reject the business work.', $error?->getMessage());
        $this->assertSame(0, $this->jobs());
        $this->assertSame(0, $this->counter());
    }

    public function test_queue_bookkeeping_may_write_only_the_jobs_table(): void
    {
        $error = $this->failureOf(fn () => DB::connection('mysql')->withoutWriterToken(['jobs'],
            fn ($db) => $db->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 9])));

        $this->assertInstanceOf(LogicException::class, $error, 'Bookkeeping without the token must not reach business tables.');
        $this->assertStringContainsString('may only write jobs', $error->getMessage());
        $this->assertSame(0, $this->counter());
    }

    public function test_a_cache_lock_never_waits_for_its_own_callers_token(): void
    {
        DB::transaction(function () {
            $this->assertTrue($this->tokenIsHeld());
            $lock = Cache::store('database')->lock('writer-policy-probe', 30);
            $started = microtime(true);
            $this->assertTrue($lock->get());
            $this->assertLessThan(1, microtime(true) - $started);
            // Committed on its own session at once, not with the business transaction.
            $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM cache_locks')->fetchColumn());
            $this->assertFalse(Cache::store('database')->lock('writer-policy-probe', 30)->get());
            $this->assertTrue($lock->release());
        });
        $this->assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM cache_locks')->fetchColumn());
    }

    public function test_a_cache_lock_progresses_while_another_process_holds_the_token(): void
    {
        $holder = $this->worker('hold');
        $this->awaitMarker('acquired');
        $lock = Cache::store('database')->lock('writer-policy-probe', 30);
        $started = microtime(true);
        $this->assertTrue($lock->get());
        $this->assertTrue($lock->release());
        $this->assertLessThan(1, microtime(true) - $started);
        $this->mark('release');
        $this->assertSame('held', $this->workerResult($holder)['result']);
    }

    public function test_the_cache_lock_session_may_write_only_cache_locks(): void
    {
        foreach ([
            'business tables' => fn () => DB::connection('mysql_cache_locks')->table('_writer_policy_probe')->where('id', 1)->update(['counter' => 9]),
            'cache values' => fn () => DB::connection('mysql_cache_locks')->table('cache')->insert(['key' => 'x', 'value' => 'y', 'expiration' => 1]),
        ] as $what => $write) {
            $error = $this->failureOf($write);
            $this->assertInstanceOf(LogicException::class, $error, "The token-free lock session must not write {$what}.");
            $this->assertStringContainsString('may only write cache_locks', $error->getMessage());
        }
        $this->assertSame(0, $this->counter());
        $this->assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM cache')->fetchColumn());
    }

    public function test_cache_values_are_writes_and_wait_for_the_token(): void
    {
        $this->holdTokenOnAdmin();
        $this->assertNull(Cache::store('database')->get('writer-policy-value'));
        try {
            Cache::store('database')->put('writer-policy-value', 'v', 60);
            $this->fail('A cache value write waits like any other write.');
        } catch (WriterTokenTimeout) {
        }
        $this->releaseAdminToken();
        Cache::store('database')->put('writer-policy-value', 'v', 60);
        $this->assertSame('v', Cache::store('database')->get('writer-policy-value'));
    }

    public function test_locked_write_costs_one_retry_when_the_holder_releases_during_the_wait(): void
    {
        $holder = $this->worker('hold', ['6']);
        $this->awaitMarker('acquired');
        $calls = 0;
        $started = microtime(true);

        // Row 2: the holder never touches it, so only the token can make this wait.
        LockedWrite::run(function () use (&$calls) {
            $calls++;
            DB::table('_writer_policy_probe')->where('id', 2)->increment('counter');
        }, 'writer-policy-test');

        $this->assertSame(1, $calls);
        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $started);
        $this->assertSame('held', $this->workerResult($holder)['result']);
        $this->assertSame([1, 1], [$this->counter(1), $this->counter(2)]);
    }

    public function test_locked_write_never_retries_a_lock_timeout_after_the_token(): void
    {
        $this->admin->beginTransaction();
        $this->admin->exec('UPDATE _writer_policy_probe SET counter = 5 WHERE id = 2');
        $calls = 0;
        try {
            LockedWrite::run(function () use (&$calls) {
                $calls++;
                DB::table('_writer_policy_probe')->where('id', 1)->increment('counter');
                DB::table('_writer_policy_probe')->where('id', 2)->increment('counter');
            }, 'writer-policy-test');
            $this->fail('The native lock timeout must surface.');
        } catch (QueryException $error) {
            $this->assertSame(1205, (int) $error->errorInfo[1]);
        } finally {
            $this->admin->rollBack();
        }
        $this->assertSame(1, $calls);
        // LockedWrite adds no transaction: the first write committed on its own, as on SQLite.
        $this->assertSame(1, $this->counter(1));
        $this->assertFalse($this->tokenIsHeld());
    }

    public function test_rate_limiter_waits_for_the_token_retries_once_and_runs_the_controller_once(): void
    {
        $holder = $this->worker('hold', ['6']);
        $this->awaitMarker('acquired');
        $middleware = new RetryDatabaseThrottleRequests(new RateLimiter(Cache::store('database')));
        $request = Request::create('/api/writer-policy-probe', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->setRouteResolver(fn () => (new Route('POST', '/api/writer-policy-probe', []))->bind($request));
        $calls = 0;
        $started = microtime(true);

        $response = $middleware->handle($request, function () use (&$calls) {
            $calls++;

            return response('ok');
        }, 5, 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('4', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertSame(1, $calls);
        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $started);
        $this->assertSame('held', $this->workerResult($holder)['result']);
    }
}
