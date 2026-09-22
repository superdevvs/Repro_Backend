<?php

namespace Tests\Feature;

use App\Http\Middleware\RetryDatabaseThrottleRequests;
use App\Logging\PrivacyLogProcessor;
use App\Services\ApiErrorResponder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Mockery;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class ThrottleDatabaseResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.driver' => 'sqlite']);
        Log::spy();
    }

    public function test_framework_throttle_binding_uses_resilient_middleware(): void
    {
        $this->assertInstanceOf(RetryDatabaseThrottleRequests::class, app(ThrottleRequests::class));
    }

    public static function admissionOperations(): array
    {
        return [['tooManyAttempts'], ['hit']];
    }

    public static function recoveryOperations(): array
    {
        return [
            ['tooManyAttempts', 5], ['hit', 5],
            ['tooManyAttempts', 6], ['hit', 6],
            ['tooManyAttempts', 517], ['hit', 517],
        ];
    }

    #[DataProvider('recoveryOperations')]
    public function test_transient_admission_failure_retries_only_limiter_and_runs_controller_once(string $operation, int $code): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $attempts = 0;
        $limiter->shouldReceive($operation)->twice()->andReturnUsing(function () use (&$attempts, $operation, $code) {
            if (++$attempts === 1) {
                throw $this->failure('sqlite', $code);
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
        $this->assertSame('299', $response->headers->get('X-RateLimit-Remaining'));
    }

    #[DataProvider('admissionOperations')]
    public function test_exhausted_admission_returns_retryable_503_without_running_controller(string $operation): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        if ($operation === 'hit') {
            $limiter->shouldReceive('tooManyAttempts')->once()->andReturn(false);
        }
        $limiter->shouldReceive($operation)->times(4)->andThrow($this->failure());
        $calls = 0;
        try {
            (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), function () use (&$calls) {
                $calls++;

                return response('should not run');
            }, 300, 1);
            $this->fail('Expected limiter exhaustion.');
        } catch (ServiceUnavailableHttpException $exception) {
            $response = app(ApiErrorResponder::class)->render($exception, $this->request());
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('1', $response->headers->get('Retry-After'));
            $this->assertSame('service_unavailable', $response->getData(true)['code']);
            $this->assertStringNotContainsString('PRIVATE_QUERY_CANARY', $response->getContent());
        }
        $this->assertSame(0, $calls);
    }

    public function test_post_controller_header_exhaustion_preserves_completed_mutation_response(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('tooManyAttempts')->once()->andReturn(false);
        $limiter->shouldReceive('hit')->once()->andReturn(1);
        $limiter->shouldReceive('retriesLeft')->times(4)->andThrow($this->failure());
        $calls = 0;
        $response = (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), function () use (&$calls) {
            $calls++;

            return response()->json(['saved' => true], 201);
        }, 300, 1);

        $this->assertSame(1, $calls);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['saved']);
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_named_after_callback_executes_once_when_post_response_hit_exhausts(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $afterCalls = 0;
        $limiter->shouldReceive('limiter')->with('after')->once()->andReturn(function () use (&$afterCalls) {
            return Limit::perMinute(300)->after(function () use (&$afterCalls) {
                $afterCalls++;

                return true;
            });
        });
        $limiter->shouldReceive('tooManyAttempts')->once()->andReturn(false);
        $limiter->shouldReceive('hit')->times(4)->andThrow($this->failure());
        $calls = 0;
        $response = (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), function () use (&$calls) {
            $calls++;

            return response('created', 201);
        }, 'after');
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, $calls);
        $this->assertSame(1, $afterCalls);
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_real_300_request_limit_retains_429_and_backoff(): void
    {
        $middleware = new RetryDatabaseThrottleRequests(new RateLimiter(new Repository(new ArrayStore)));
        $calls = 0;
        $next = function () use (&$calls) {
            $calls++;

            return response('ok');
        };
        for ($i = 0; $i < 300; $i++) {
            $this->assertSame(200, $middleware->handle($this->request(), $next, 300, 1)->getStatusCode());
        }
        try {
            $middleware->handle($this->request(), $next, 300, 1);
            $this->fail('Expected rate limit.');
        } catch (\Illuminate\Http\Exceptions\ThrottleRequestsException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertGreaterThan(0, $exception->getHeaders()['Retry-After']);
        }
        $this->assertSame(300, $calls);
    }

    public function test_named_limit_response_callback_and_429_survive_reset_time_failure(): void
    {
        $limiter = Mockery::mock(RateLimiter::class);
        $responseCalls = 0;
        $limiter->shouldReceive('limiter')->with('blocked')->once()->andReturn(function () use (&$responseCalls) {
            return Limit::perMinute(300)->response(function ($request, $headers) use (&$responseCalls) {
                $responseCalls++;

                return response('limited', 429, $headers);
            });
        });
        $limiter->shouldReceive('tooManyAttempts')->once()->andReturn(true);
        $limiter->shouldReceive('availableIn')->times(4)->andThrow($this->failure());
        try {
            (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), fn () => $this->fail('Must not run'), 'blocked');
            $this->fail('Expected named rate limit response.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame(429, $exception->getResponse()->getStatusCode());
            $this->assertSame('1', $exception->getResponse()->headers->get('Retry-After'));
        }
        $this->assertSame(1, $responseCalls);
    }

    public static function nonTransientFailures(): array
    {
        return [['sqlite', 19], ['pgsql', 5]];
    }

    #[DataProvider('nonTransientFailures')]
    public function test_non_transient_or_non_sqlite_exception_is_not_retried(string $connection, int $code): void
    {
        config(['database.connections.pgsql.driver' => 'pgsql']);
        $error = $this->failure($connection, $code);
        $limiter = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('tooManyAttempts')->once()->andThrow($error);
        try {
            (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), fn () => $this->fail('Must not run'), 300, 1);
            $this->fail('Expected original exception.');
        } catch (QueryException $actual) {
            $this->assertSame($error, $actual);
        }
    }

    public function test_controller_exception_is_not_retried_or_reclassified(): void
    {
        $error = $this->failure();
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $calls = 0;
        try {
            (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), function () use (&$calls, $error) {
                $calls++;
                throw $error;
            }, 300, 1);
            $this->fail('Expected controller exception.');
        } catch (QueryException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame(1, $calls);
    }

    public function test_partial_hit_retry_counts_conservatively_without_replaying_application(): void
    {
        $store = new ArrayStore;
        $repository = new Repository($store);
        $limiter = new class($repository, $this->failure()) extends RateLimiter
        {
            private bool $failed = false;

            public function __construct(Repository $cache, private QueryException $error)
            {
                parent::__construct($cache);
            }

            public function hit($key, $decaySeconds = 60)
            {
                $hits = parent::hit($key, $decaySeconds);
                if (! $this->failed) {
                    $this->failed = true;
                    throw $this->error;
                }

                return $hits;
            }
        };
        $calls = 0;
        $response = (new RetryDatabaseThrottleRequests($limiter))->handle($this->request(), function () use (&$calls) {
            $calls++;

            return response('ok');
        }, 300, 1);
        $this->assertSame(1, $calls);
        $this->assertSame('298', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_restricted_diagnostics_keep_only_database_codes_and_fixed_operation(): void
    {
        $context = ApiErrorResponder::diagnosticContext($this->failure()) + [
            'limiter_operation' => 'hit', 'sql' => 'PRIVATE_QUERY_CANARY', 'bindings' => ['private@example.com'],
        ];
        $record = (new PrivacyLogProcessor)(new LogRecord(new \DateTimeImmutable, 'production', Level::Error,
            'Rate limiter database operation failed.', $context));
        $this->assertSame('HY000', $record->context['sqlstate']);
        $this->assertSame(5, $record->context['database_driver_code']);
        $this->assertSame('hit', $record->context['limiter_operation']);
        $this->assertStringNotContainsString('PRIVATE_QUERY_CANARY', json_encode($record));
        $this->assertStringNotContainsString('private@example.com', json_encode($record));
        $context['sqlstate'] = 'PRIVATE_QUERY_CANARY';
        $context['database_driver_code'] = 'private@example.com';
        $context['limiter_operation'] = 'PRIVATE_QUERY_CANARY';
        $record = (new PrivacyLogProcessor)(new LogRecord(new \DateTimeImmutable, 'production', Level::Error,
            'Rate limiter database operation failed.', $context));
        $this->assertArrayNotHasKey('sqlstate', $record->context);
        $this->assertArrayNotHasKey('database_driver_code', $record->context);
        $this->assertArrayNotHasKey('limiter_operation', $record->context);
    }

    private function request(): Request
    {
        $request = Request::create('/api/weather', 'GET');
        $request->setRouteResolver(fn () => new Route('GET', 'api/weather', fn () => null));
        $request->setUserResolver(fn () => null);

        return $request;
    }

    private function failure(string $connection = 'sqlite', int $code = 5): QueryException
    {
        $previous = new \PDOException('database is locked: PRIVATE_QUERY_CANARY');
        $previous->errorInfo = ['HY000', $code, 'PRIVATE_QUERY_CANARY'];

        return new QueryException($connection, 'update cache set value = ?', ['PRIVATE_QUERY_CANARY'], $previous);
    }
}
