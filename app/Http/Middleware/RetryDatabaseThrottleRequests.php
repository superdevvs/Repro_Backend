<?php

namespace App\Http\Middleware;

use App\Services\ApiErrorResponder;
use App\Support\LockedWrite;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Keep transient SQLite limiter failures outside the application callback. */
class RetryDatabaseThrottleRequests extends ThrottleRequests
{
    protected function handleRequest($request, Closure $next, array $limits)
    {
        foreach ($limits as $limit) {
            if ($this->limiterOperation('check', fn () => $this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts))) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }

            if (! $limit->afterCallback) {
                $this->limiterOperation('hit', fn () => $this->limiter->hit($limit->key, $limit->decaySeconds));
            }
        }

        // Never put the controller or named limiter callbacks inside a retry.
        $response = $next($request);

        foreach ($limits as $limit) {
            $shouldHit = $limit->afterCallback && ($limit->afterCallback)($response);
            try {
                if ($shouldHit) {
                    $this->limiterOperation('hit_after', fn () => $this->limiter->hit($limit->key, $limit->decaySeconds));
                }
                $remaining = $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts);
            } catch (ServiceUnavailableHttpException) {
                // The application already completed: do not invite replay of a mutation.
                // Admission was checked before execution; report no spare quota when uncertain.
                $remaining = 0;
            }

            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $remaining
            );
        }

        return $response;
    }

    protected function calculateRemainingAttempts($key, $maxAttempts, $retryAfter = null)
    {
        return $this->limiterOperation('remaining', fn () => parent::calculateRemainingAttempts($key, $maxAttempts, $retryAfter));
    }

    protected function getTimeUntilNextRetry($key)
    {
        try {
            return $this->limiterOperation('retry_after', fn () => parent::getTimeUntilNextRetry($key));
        } catch (ServiceUnavailableHttpException) {
            // The limit was already reached; keep its 429 even if its reset time is unavailable.
            return 1;
        }
    }

    private function limiterOperation(string $operation, Closure $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $exception) {
                $transient = $this->isSqliteContention($exception);
                if (! $transient || $attempt >= LockedWrite::DEFAULT_ATTEMPTS) {
                    try {
                        Log::error('Rate limiter database operation failed.', ApiErrorResponder::diagnosticContext($exception) + [
                            'limiter_operation' => $operation,
                            'attempts' => $attempt,
                        ]);
                    } catch (\Throwable) {
                        // Diagnostics must not replace the original failure.
                    }

                    if (! $transient) {
                        throw $exception;
                    }

                    throw new ServiceUnavailableHttpException(1, 'Rate limiter temporarily unavailable.', $exception);
                }

                // Same bounded jittered backoff as LockedWrite; never log query text.
                $delay = 40_000 * (2 ** ($attempt - 1));
                usleep(random_int((int) ($delay / 2), $delay));
            }
        }
    }

    private function isSqliteContention(QueryException $exception): bool
    {
        $connection = $exception->getConnectionName();
        $code = $exception->errorInfo[1] ?? null;

        return config('database.connections.'.$connection.'.driver') === 'sqlite'
            && is_numeric($code)
            && in_array(((int) $code) & 255, [5, 6], true)
            && LockedWrite::isLockContention($exception->getPrevious() ?? $exception);
    }
}
