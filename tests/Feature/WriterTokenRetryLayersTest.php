<?php

namespace Tests\Feature;

use App\Database\WriterTokenTimeout;
use App\Http\Middleware\RetryDatabaseThrottleRequests;
use App\Support\LockedWrite;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\FakeWriterTokenConnection;
use Tests\TestCase;
use Throwable;

/**
 * LockedWrite and the rate limiter retry only a WriterTokenTimeout raised while
 * taking the token, before any business callback runs. Runs on both engines.
 */
class WriterTokenRetryLayersTest extends TestCase
{
    private ?FakeWriterTokenConnection $fake = null;

    private function useFakeWriterConnection(int $timeoutsBeforeAcquired = 0, int $level = 0): FakeWriterTokenConnection
    {
        $this->fake = new FakeWriterTokenConnection($timeoutsBeforeAcquired, $level);
        config(['database.connections.writer-fake' => ['driver' => 'writer-fake', 'name' => 'writer-fake']]);
        DB::extend('writer-fake', fn () => $this->fake);
        DB::setDefaultConnection('writer-fake');

        return $this->fake;
    }

    public function test_locked_write_takes_the_token_first_and_runs_the_callback_once(): void
    {
        $fake = $this->useFakeWriterConnection(timeoutsBeforeAcquired: 2);
        $calls = 0;

        $result = LockedWrite::run(function () use (&$calls, $fake) {
            $calls++;
            $this->assertTrue($fake->holdsToken, 'The callback must run only after the token is held.');

            return 'saved';
        }, 'test');

        $this->assertSame('saved', $result);
        $this->assertSame(1, $calls);
        $this->assertSame([LockedWrite::DEFAULT_ATTEMPTS], $fake->transactionAttempts);
        $this->assertSame(3, $fake->acquisitions);
    }

    public function test_locked_write_gives_up_after_its_attempts_without_entering_the_callback(): void
    {
        $fake = $this->useFakeWriterConnection(timeoutsBeforeAcquired: 10);
        Log::spy();
        $calls = 0;
        try {
            LockedWrite::run(function () use (&$calls) {
                $calls++;
            }, 'test');
            $this->fail('Exhausted acquisition must surface.');
        } catch (WriterTokenTimeout) {
        }
        $this->assertSame(0, $calls);
        $this->assertSame(LockedWrite::DEFAULT_ATTEMPTS, $fake->acquisitions);
        Log::shouldHaveReceived('error')->once();
    }

    public static function failuresAfterTheToken(): array
    {
        return [
            'business timeout' => [fn () => new WriterTokenTimeout('Business code reused the type.')],
            'native lock wait' => [fn () => self::mysqlError(1205, 'Lock wait timeout exceeded; try restarting transaction')],
            'native deadlock' => [fn () => self::mysqlError(1213, 'Deadlock found when trying to get lock; try restarting transaction')],
        ];
    }

    #[DataProvider('failuresAfterTheToken')]
    public function test_locked_write_never_replays_a_callback_that_failed_after_the_token(Closure $failure): void
    {
        $this->useFakeWriterConnection();
        $calls = 0;
        $error = $failure();
        try {
            LockedWrite::run(function () use (&$calls, $error) {
                $calls++;
                throw $error;
            }, 'test');
            $this->fail('The failure must escape.');
        } catch (Throwable $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame(1, $calls);
    }

    public function test_locked_write_inside_an_open_transaction_runs_once_without_another_acquisition(): void
    {
        $fake = $this->useFakeWriterConnection(level: 1);
        $calls = 0;

        $this->assertSame('inner', LockedWrite::run(function () use (&$calls) {
            $calls++;

            return 'inner';
        }));

        $this->assertSame(1, $calls);
        $this->assertSame([], $fake->transactionAttempts);
        $this->assertSame(0, $fake->acquisitions);
    }

    public static function limiterOperations(): array
    {
        return [['tooManyAttempts'], ['hit']];
    }

    #[DataProvider('limiterOperations')]
    public function test_limiter_retries_a_writer_token_timeout_and_runs_the_controller_once(string $operation): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $attempts = 0;
        $limiter->shouldReceive($operation)->twice()->andReturnUsing(function () use (&$attempts, $operation) {
            if (++$attempts === 1) {
                throw new WriterTokenTimeout('The MySQL writer token is busy.');
            }

            return $operation === 'hit' ? 1 : false;
        });
        $limiter->shouldReceive($operation === 'hit' ? 'tooManyAttempts' : 'hit')->once()->andReturn(false);
        $limiter->shouldReceive('retriesLeft')->once()->andReturn(299);
        $calls = 0;

        $response = (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), function () use (&$calls) {
            $calls++;

            return response()->json(['saved' => true], 201);
        }, 300, 1);

        $this->assertSame(1, $calls);
        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_limiter_exhausted_by_writer_token_timeouts_returns_503_before_the_controller(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('tooManyAttempts')->times(LockedWrite::DEFAULT_ATTEMPTS)
            ->andThrow(new WriterTokenTimeout('The MySQL writer token is busy.'));
        $calls = 0;
        try {
            (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), function () use (&$calls) {
                $calls++;
            }, 300, 1);
            $this->fail('Expected limiter exhaustion.');
        } catch (ServiceUnavailableHttpException $exception) {
            $this->assertSame('1', (string) $exception->getHeaders()['Retry-After']);
        }
        $this->assertSame(0, $calls);
    }

    public function test_limiter_never_retries_a_native_mysql_lock_error(): void
    {
        config(['database.connections.mysql.driver' => 'mysql']);
        $error = self::mysqlError(1205, 'Lock wait timeout exceeded; try restarting transaction');
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('tooManyAttempts')->once()->andThrow($error);
        try {
            (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), fn () => $this->fail('Must not run'), 300, 1);
            $this->fail('Expected the original exception.');
        } catch (QueryException $actual) {
            $this->assertSame($error, $actual);
        }
    }

    private static function mysqlError(int $code, string $message): QueryException
    {
        $previous = new PDOException('SQLSTATE[HY000]: General error: '.$code.' '.$message);
        $previous->errorInfo = ['HY000', $code, $message];

        return new QueryException('mysql', 'update `t` set `a` = ?', [1], $previous);
    }

    private function request(): Request
    {
        $request = Request::create('/api/writer-token-probe', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->setRouteResolver(fn () => (new Route('POST', '/api/writer-token-probe', []))->bind($request));

        return $request;
    }
}
