<?php

namespace App\Exceptions;

use RuntimeException;

/** Only neutral, fixed messages belong in this exception; never provider bodies or URLs. */
class StudioProviderException extends RuntimeException
{
    public function __construct(string $message = 'The image service could not finish this operation. Please retry.', public readonly bool $ambiguous = false)
    {
        parent::__construct($message);
    }
}
