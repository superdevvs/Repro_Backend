<?php

namespace App\Contracts;

use App\Models\IguideLabWorkflowStep;

/** No default binding: a persisted, typed machine-assessment owner must implement this. */
interface LabMachineSegmentationAuthority
{
    /** Revalidate completed assessment/context and return its bound plan for this reserved step UUID. */
    public function plan(IguideLabWorkflowStep $step): array;

    /** Return the strict protocol's operational receipt association, never quality acceptance. */
    public function associate(IguideLabWorkflowStep $step, array $receipt): array;
}
