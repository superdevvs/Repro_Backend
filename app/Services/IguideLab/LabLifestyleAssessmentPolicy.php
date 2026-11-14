<?php
namespace App\Services\IguideLab;

/** Closed internal policy; a public Lifestyle string does not assert capability. */
final class LabLifestyleAssessmentPolicy
{
    public const VERSION='automatic-standing-pair-assessment/1';
    public const POLICY='two-standing-fictional-adults/1';
    public static function snapshot(): array
    {
        return ['version'=>self::POLICY,'analysisSteps'=>3,'imageSteps'=>1,'segmentationSteps'=>2,'evaluationSteps'=>1,
            'personCount'=>2,'pose'=>'standing','props'=>false,'reflectionSynthesis'=>false,'automaticAcceptance'=>false];
    }
    public static function enabled(array $policy,string $preset): bool
    {return $preset==='lifestyle'&&($policy['lifestyleAssessment']??null)===self::snapshot();}
}
