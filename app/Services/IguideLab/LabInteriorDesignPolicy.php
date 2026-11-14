<?php
namespace App\Services\IguideLab;
/** Explicit unchanged-form material restyling; never inferred from the public preset alone. */
final class LabInteriorDesignPolicy
{
    public const VERSION='interior-existing-material-workflow/1';
    public const SEGMENTATION='automatic-interior-material-segmentation/1';
    public static function snapshot(): array
    {return ['version'=>self::VERSION,'analysisSteps'=>3,'imageSteps'=>1,'segmentationSteps'=>15,'sectionSteps'=>1,'compositionSteps'=>1,'evaluationSteps'=>1,
        'minimumMaterials'=>2,'maximumMaterials'=>5,'maximumForegroundObjects'=>5,'automaticRepair'=>false,'automaticAcceptance'=>false,'paletteOnlyReady'=>false];}
    public static function enabled(array $policy,string $preset): bool
    {return $preset==='interior-design'&&($policy['interiorDesign']??null)===self::snapshot();}
}
