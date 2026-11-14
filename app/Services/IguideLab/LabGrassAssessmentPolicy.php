<?php
namespace App\Services\IguideLab;

/** Separate source-sphere policy; never inferred from a client role or dimension. */
class LabGrassAssessmentPolicy
{
    public const VERSION = 'source-native-grass-assessment/1';
    public const POLICY = 'source-native-grass-workflow/1';
    public const SEGMENTATION = 'automatic-native-grass-segmentation/1';
    public const ROLES = ['lawn', 'paving', 'plants', 'loose_debris', 'non_turf_ground', 'capture_artifact'];
    public const PROMPTS = ['lawn' => 'grass lawn', 'paving' => 'paving', 'plants' => 'plants', 'loose_debris' => 'fallen leaves and twigs', 'non_turf_ground' => 'bare soil and mulch', 'capture_artifact' => 'camera and tripod'];
    public static function snapshot(): array
    {
        return ['version' => self::POLICY, 'analysisSteps' => 2, 'imageSteps' => 1, 'segmentationSteps' => 6, 'evaluationSteps' => 1, 'automaticAcceptance' => false];
    }
    public static function enabled(array $policy, string $preset): bool
    {
        return $preset === 'grass' && ($policy['grassAssessment'] ?? null) === self::snapshot();
    }
    public static function assessmentPolicy(): array
    {
        return ['scope' => 'all_visible_established_turf', 'operation' => 'recolor_existing_turf', 'maximumSegmentationOperations' => 6, 'maximumSelectionsPerRole' => 8, 'width' => 2048, 'height' => 1024];
    }
}
