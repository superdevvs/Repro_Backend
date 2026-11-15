<?php
namespace App\Services\IguideLab;
use App\Contracts\LabMachineSegmentationCapability;
use App\Models\IguideLabWorkflowStep;
/** Closed server protocol routing. Clients cannot name a class or relax dimensions. */
class LabMachineSegmentationCapabilities
{
    public function forStep(IguideLabWorkflowStep $step): LabMachineSegmentationCapability
    {
        return match ($step->input['protocolVersion'] ?? null) {
            LabRoomFloorWitness::VERSION => config('iguide-lab.interior_room_routing_enabled',false)===true&&config('iguide-lab.interior_floor_witness_enabled',false)===true
                ? app(LabRoomFurnishingMasks::class) : abort(503,'The floor witness capability is disabled.'),
            LabRoomFurnishingMaskPlan::PROTOCOL => config('iguide-lab.interior_room_routing_enabled',false)===true
                ? app(LabRoomFurnishingMasks::class) : abort(503,'The Interior furnishing capability is disabled.'),
            'automatic-lifestyle-segmentation/1' => config('iguide-lab.lifestyle_workflow_enabled',false)===true
                ? app(LabLifestyleSegmentationAuthority::class) : abort(503,'The standing-pair capability is disabled.'),
            LabInteriorDesignPolicy::SEGMENTATION => config('iguide-lab.interior_design_workflow_enabled',false)===true
                ? app(LabInteriorDesignMasks::class) : abort(503,'The material-region capability is disabled.'),
            LabDeclutterRemovalPolicy::SEGMENTATION => config('iguide-lab.declutter_workflow_enabled',false)===true
                ? app(LabDeclutterSegmentationAuthority::class) : abort(503,'The source-removal capability is disabled.'),
            LabMachineSegmentations::PROTOCOL => app(LabStagingSegmentationAuthority::class),
            'automatic-native-grass-segmentation/1' => class_exists(LabGrassSegmentationAuthority::class)
                ? app(LabGrassSegmentationAuthority::class) : abort(503,'The source-sphere grass capability is not installed.'),
            default => abort(422,'Unsupported machine segmentation capability.'),
        };
    }
}
