<?php
namespace App\Services\IguideLab;
/** Internal candidate only. No public capability is registered by this class. */
class LabDeclutterRemovalPolicy
{
    public const VERSION = 'automatic-declutter-removal-plan/1';
    public const POLICY = 'automatic-isolated-object-declutter/1';
    public const SEGMENTATION = 'automatic-declutter-segmentation/1';
    public static function snapshot(): array { return ['version'=>self::POLICY,'analysisSteps'=>2,'imageSteps'=>1,'segmentationSteps'=>2,'evaluationSteps'=>1,'automaticImageRetries'=>0,'automaticAcceptance'=>false]; }
    public static function enabled(array $policy,string $preset): bool { return $preset==='declutter' && ($policy['declutterRemoval']??null)===self::snapshot(); }
}
