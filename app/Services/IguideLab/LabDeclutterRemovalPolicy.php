<?php
namespace App\Services\IguideLab;
/** Internal candidate only. No public capability is registered by this class. */
class LabDeclutterRemovalPolicy
{
    public const VERSION = 'automatic-declutter-removal-plan/1';
    public const POLICY = 'automatic-isolated-object-declutter/1';
    public const MATERIALS_VERSION = 'automatic-declutter-removal-plan/2';
    public const MATERIALS_POLICY = 'automatic-isolated-object-declutter/2';
    public const SEGMENTATION = 'automatic-declutter-segmentation/1';
    public static function snapshot(bool $materials = false): array { return ['version'=>$materials?self::MATERIALS_POLICY:self::POLICY,'analysisSteps'=>2,'imageSteps'=>1,'segmentationSteps'=>2,'evaluationSteps'=>1,'automaticImageRetries'=>0,'automaticAcceptance'=>false]; }
    public static function materials(array $policy): bool { return ($policy['declutterRemoval']??null)===self::snapshot(true); }
    public static function protocolVersion(array $policy): string { return self::materials($policy)?self::MATERIALS_VERSION:self::VERSION; }
    public static function enabled(array $policy,string $preset): bool { return $preset==='declutter' && (($policy['declutterRemoval']??null)===self::snapshot() || self::materials($policy)); }
}
