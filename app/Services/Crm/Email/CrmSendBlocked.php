<?php

namespace App\Services\Crm\Email;

use RuntimeException;

/** An email was not sent, with the reason a person can act on. */
final class CrmSendBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
