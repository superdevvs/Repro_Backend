<?php
namespace App\Services\IguideLab;

/**
 * Invalid tour survey configuration (spec 3.2). Internal: never rendered to a client. The message is the
 * REASON_CODE-style code itself, so it can be stored as a survey error_code without leaking configuration.
 */
final class LabTourConfigException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode = 'survey_misconfigured', ?\Throwable $previous = null)
    {
        parent::__construct($reasonCode, 0, $previous);
    }
}
