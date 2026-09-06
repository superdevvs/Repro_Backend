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
        if ($record->message === 'Authentication rate limit exceeded.'
            && in_array($record->context['scope'] ?? null, [
                'login-ip', 'login-account', 'forgot-ip', 'forgot-account',
                'reset-ip', 'reset-account', 'resend-ip', 'resend-account',
            ], true)) {
            $safe['scope'] = $record->context['scope'];
        }
        $message = in_array($record->message, ['API request failed.', 'API operation failed.', 'Square payment failed', 'Authentication rate limit exceeded.'], true)
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
