<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabRun};
/** Closed server-owned provenance dispatch; unknown typed drafts fail closed. */
class LabMachineImageDrafts
{
    public function verifyRun(IguideLabRun $run,IguideLabCase $case): void
    {
        match($run->options['sourceMachinePlan']['version']??null){
            'machine-removal-plan/1'=>app(LabDeclutterImageDraft::class)->verifyRun($run,$case),
            LabInteriorDesignImageDraft::VERSION=>app(LabInteriorDesignImageDraft::class)->verifyRun($run,$case),
            default=>abort(422,'Unknown machine image authority.'),
        };
    }
}
