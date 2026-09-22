<?php

namespace App\Logging;

use App\Services\RequestCorrelation;
use Illuminate\Http\Request;
use Monolog\LogRecord;

/** Production diagnostics contain location and operational facts, never request/provider text. */
class PrivacyLogProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $safe = [];
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $safe['exception'] = $exception::class;
            $safe += $this->location($exception->getFile(), $exception->getLine());
        } elseif (is_string($exception) && class_exists($exception, false) && is_a($exception, \Throwable::class, true)) {
            $safe['exception'] = $exception;
        }
        $sourceFile = $record->context['file'] ?? null;
        if (!isset($safe['file']) && isset($safe['exception']) && is_string($sourceFile)
            && preg_match('/\A(?:app|bootstrap|routes|vendor)\/[A-Za-z0-9_\/.+-]+\.php\z/', $sourceFile)
            && !str_contains($sourceFile, '..') && is_file(base_path($sourceFile))
            && is_int($record->context['line'] ?? null)) {
            $safe += $this->location(base_path($sourceFile), $record->context['line']);
        }
        if (!isset($safe['file'])) {
            // Arguments are explicitly excluded; never serialize a stack frame.
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
                $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
                if (str_contains($file, '/app/') && !str_contains($file, '/app/Logging/') && !str_ends_with($file, '/Services/ApiErrorResponder.php')) {
                    $safe += $this->location($file, (int) ($frame['line'] ?? 0));
                    break;
                }
            }
        }
        foreach (['status', 'status_code', 'http_status', 'attempt', 'attempts', 'retry_count', 'duration_ms', 'user_id', 'shoot_id', 'run_id', 'job_id'] as $key) {
            $value = $record->context[$key] ?? null;
            if (is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) $safe[$key] = $value;
        }
        if (app()->bound('request') && request() instanceof Request && request()->is('api/*')) {
            $safe['request_id'] = RequestCorrelation::id(request());
        }
        if (in_array($record->message, ['API request failed.', 'API operation failed.', 'Rate limiter database operation failed.'], true)) {
            $state = $record->context['sqlstate'] ?? null;
            $driverCode = $record->context['database_driver_code'] ?? null;
            if (is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/', $state)) $safe['sqlstate'] = $state;
            if (is_int($driverCode) && $driverCode >= 0 && $driverCode <= 65535) $safe['database_driver_code'] = $driverCode;
            if ($record->message === 'Rate limiter database operation failed.'
                && in_array($record->context['limiter_operation'] ?? null, ['check', 'hit', 'hit_after', 'remaining', 'retry_after'], true)) {
                $safe['limiter_operation'] = $record->context['limiter_operation'];
            }
        }
        if ($record->message === 'Authentication rate limit exceeded.'
            && in_array($record->context['scope'] ?? null, [
                'login-ip', 'login-account', 'forgot-ip', 'forgot-account',
                'reset-ip', 'reset-account', 'resend-ip', 'resend-account',
            ], true)) {
            $safe['scope'] = $record->context['scope'];
        }
        if ($record->message === 'Stripe webhook ownership decision.') {
            if (in_array($record->context['reason'] ?? null, [
                'foreign', 'ambiguous', 'conflict', 'reconciliation_failed',
                'attempt_reference_mismatch', 'unmapped_refund',
            ], true)) $safe['reason'] = $record->context['reason'];
            if (in_array($record->context['event_type'] ?? null, [
                'checkout.session.completed', 'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed', 'checkout.session.expired',
                'refund.created', 'refund.updated', 'refund.failed',
            ], true)) $safe['event_type'] = $record->context['event_type'];
            foreach (['event_hash', 'object_hash', 'intent_hash'] as $key) {
                $value = $record->context[$key] ?? null;
                if (is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value)) $safe[$key] = $value;
            }
        }
        $message = in_array($record->message, ['API request failed.', 'API operation failed.', 'Square payment failed', 'Authentication rate limit exceeded.', 'Stripe webhook ownership decision.', 'Rate limiter database operation failed.'], true)
            ? $record->message : 'Application '.strtolower($record->level->getName()).' event.';

        return $record->with(message: $message, context: $safe, extra: []);
    }

    private function location(string $file, int $line): array
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        return ['file' => str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file), 'line' => $line];
    }
}
