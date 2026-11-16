<?php
namespace App\Services\IguideLab;

/**
 * The stored survey source cannot be read or no longer matches the survey's identity (spec 5.2). Internal: never
 * rendered to a client. The survey fails for free with error_code source_unavailable; no attempt row exists.
 */
final class LabTourSourceException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode = 'source_unavailable', ?\Throwable $previous = null)
    {
        parent::__construct($reasonCode, 0, $previous);
    }
}
