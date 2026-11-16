<?php
namespace App\Services\IguideLab;

/**
 * Thrown before an attempt row is written (spec 5.6 attempt step 2, D8): survey_cap_reached, survey_daily_limit,
 * or project_inactive. Internal: never rendered to a client.
 */
final class LabTourSurveyCapReached extends \RuntimeException
{
    public const CODES = ['survey_cap_reached', 'survey_daily_limit', 'project_inactive'];

    public function __construct(public readonly string $reasonCode, ?\Throwable $previous = null)
    {
        if (! in_array($reasonCode, self::CODES, true)) throw new \InvalidArgumentException('Unknown survey cap code.');
        parent::__construct($reasonCode, 0, $previous);
    }
}
