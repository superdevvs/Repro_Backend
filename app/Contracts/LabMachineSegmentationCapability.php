<?php
namespace App\Contracts;
use App\Models\IguideLabWorkflowStep;
interface LabMachineSegmentationCapability extends LabMachineSegmentationAuthority
{
    public function validatePlan(array $plan,string $requestId): void;
    public function payload(array $plan,string $bytes): array;
    public function describeMask(string $bytes): array;
    public function readyAssociationStatus(): string;
    /** @return array{0:string,1:array} Exact bytes and immutable server-owned binding. */
    public function verifyOwnedInputs(IguideLabWorkflowStep $step,array $plan): array;
    public function prerequisite(IguideLabWorkflowStep $step,array $plan): void;
}
