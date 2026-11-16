<?php
namespace App\Services\IguideLab;

/**
 * A stored survey answer could not be accepted (spec 5.8). reasonCode is the survey error_code (survey_unreadable,
 * survey_incomplete, survey_refused, survey_too_large); parseError is the internal detail stored in parse_error
 * (at most 120 characters, never public, never model text).
 */
final class LabTourSurveyParseException extends \RuntimeException
{
    public readonly string $parseError;

    public function __construct(public readonly string $reasonCode, string $parseError, ?\Throwable $previous = null)
    {
        $this->parseError = substr($parseError, 0, 120);
        parent::__construct($reasonCode.': '.$this->parseError, 0, $previous);
    }
}
