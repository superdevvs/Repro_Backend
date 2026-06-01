<?php

namespace App\Exceptions\Messaging;

use RuntimeException;
use Throwable;

/**
 * Domain exception thrown when an SMS send fails.
 *
 * Carries a client-safe message (suitable for returning to API consumers) while
 * preserving the original provider/database error as the previous throwable for
 * logging and diagnostics. The constructor signature is compatible with a PHP
 * named-argument call such as `new SmsSendException($msg, previous: $e)`.
 */
class SmsSendException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
