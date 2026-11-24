<?php

namespace App\Services\Crm;

use RuntimeException;

/** Thrown when a save would create a duplicate contact. The controller turns it into the right response for the viewer. */
final class CrmDuplicateException extends RuntimeException
{
    public function __construct(public readonly CrmDuplicateMatch $match, public readonly bool $adminNotified)
    {
        parent::__construct('This contact is already in the CRM.');
    }
}
