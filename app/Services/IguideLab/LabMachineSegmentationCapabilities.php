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
            LabMachineSegmentations::PROTOCOL => app(LabStagingSegmentationAuthority::class),
            default => abort(422,'Unsupported machine segmentation capability.'),
        };
    }
}
