<?php

namespace App\Services\Scheduling;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Operational diagnostics must never replace a scheduling decision or a saved result. */
final class SchedulingDiagnostics
{
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function notice(string $event, array $context = []): void
    {
        self::write('notice', $event, $context);
    }

    private static function write(string $level, string $event, array $context): void
    {
        try {
            self::prepareDirectory();
            Log::channel('scheduling')->log($level, $event, $context);
        } catch (Throwable $exception) {
            try {
                // Do not copy the original exception message, log path, or provider data.
                Log::error('Scheduling diagnostics could not be written.', [
                    'diagnostic_event' => $event, 'exception_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // A second logging failure is still diagnostic-only.
            }
        }
    }

    private static function prepareDirectory(): void
    {
        $expected = storage_path('logs/scheduling');
        $path = config('logging.channels.scheduling.path');
        // Only provision the dedicated application directory; respect custom channels.
        if (! is_string($path) || dirname($path) !== $expected || is_dir($expected)) return;
        if (! @mkdir($expected, 0770)) {
            if (is_dir($expected)) return;
            throw new \RuntimeException('Scheduling diagnostic directory could not be created.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! @chgrp($expected, 'www-data')) {
            throw new \RuntimeException('Scheduling diagnostic directory needs the shared runtime group.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($expected, 02770)) {
            throw new \RuntimeException('Scheduling diagnostic directory permissions could not be set.');
        }
    }
}
