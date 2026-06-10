<?php

namespace App\Exceptions\Scanning;

use RuntimeException;
use Throwable;

/**
 * Domain exception thrown when the ClamAV daemon (clamd) cannot be reached or
 * the in-progress scan stream is interrupted.
 *
 * The Scan_Job treats this as a transient failure: the ShootFile remains in
 * Quarantine and the job is retried with backoff rather than recording a
 * verdict (Req 15.2). The original socket/stream error is preserved as the
 * previous throwable for logging and diagnostics. The constructor is
 * compatible with a named-argument call such as
 * `new ClamAvUnavailable($msg, previous: $e)`.
 */
class ClamAvUnavailable extends RuntimeException
{
    public function __construct(string $message = 'ClamAV is unavailable', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
