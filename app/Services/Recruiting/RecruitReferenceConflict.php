<?php

namespace App\Services\Recruiting;

use RuntimeException;

/** A website application replay that does not match what its reference first carried. */
final class RecruitReferenceConflict extends RuntimeException
{
    public function __construct(public readonly int $applicantId)
    {
        parent::__construct('The application reference was already used with different content.');
    }
}
