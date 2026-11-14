<?php
namespace App\Services\IguideLab;

final class LabInteriorRoomPolicy
{
    public const VERSION=LabInteriorRoomProfiles::VERSION;
    public static function snapshot(): array {return LabInteriorRoomProfiles::snapshot();}
    public static function enabled(array $policy,string $preset): bool
    {
        return $preset==='interior-design' && isset($policy['interiorRoomRouting'])
            && LabMachineSegmentationPlan::canonicalHash($policy['interiorRoomRouting'])===LabMachineSegmentationPlan::canonicalHash(self::snapshot());
    }
}
