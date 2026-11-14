<?php
namespace App\Services\IguideLab;
/** An explicit internal policy, never inferred from client preset text. */
class LabStagingAssessmentPolicy
{
    public const VERSION = 'automatic-staging-assessment/1';
    public const POLICY = 'empty-living-three-piece-staging/1';
    public static function snapshot(): array { return ['version' => self::POLICY, 'analysisSteps' => 3, 'imageSteps' => 1, 'segmentationSteps' => 2, 'evaluationSteps' => 1, 'automaticAcceptance' => false]; }
    public static function enabled(array $policy, string $preset): bool { return $preset === 'staging' && ($policy['stagingAssessment'] ?? null) === self::snapshot(); }
}
