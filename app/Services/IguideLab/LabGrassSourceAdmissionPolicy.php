<?php
namespace App\Services\IguideLab;

/** Explicit opt-in source-only screening; no changes to legacy recipe budgets. */
class LabGrassSourceAdmissionPolicy
{
    public const VERSION = 'grass-original-source-admission/1';
    public static function snapshot(): array
    {
        return ['version' => self::VERSION, 'maximumSourceRounds' => 2, 'maximumDetailViews' => 2,
            'analysisSteps' => 4, 'imageSteps' => 1, 'segmentationSteps' => 6, 'evaluationSteps' => 1,
            'automaticAcceptance' => false, 'scope' => 'all_visible_established_turf'];
    }
    public static function enabled(array $policy, string $preset): bool
    {
        return $preset === 'grass' && ($policy['grassSourceAdmission'] ?? null) === self::snapshot();
    }
    public static function contextPolicy(): array
    {
        return ['scope' => 'all_visible_established_turf', 'operation' => 'recolor_existing_turf', 'sourceMaskWidth' => 2048, 'sourceMaskHeight' => 1024,
            'maximumRounds' => 2, 'maximumDetailViews' => 2, 'detailWidth' => 1024, 'detailHeight' => 1024, 'minimumDetailHfov' => 30, 'maximumDetailHfov' => 70];
    }
}
