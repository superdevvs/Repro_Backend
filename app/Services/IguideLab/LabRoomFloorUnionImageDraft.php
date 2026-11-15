<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabRoomAssessment};
final class LabRoomFloorUnionImageDraft extends LabRoomEvidenceImageDraft
{
    public const VERSION='machine-interior-evidence-furnishing-plan/2';
    public const LAYOUT_VERSION=LabRoomFloorUnionLayoutProtocol::VERSION;
    public const CANDIDATE_VERSION=LabRoomFloorUnionCandidateProtocol::VERSION;
    public function prompt(IguideLabRoomAssessment $layout,?IguideLabRoomAssessment $prior=null): string
    {
        LabRoomFloorWitnessUnion::selection($layout->result['sourceFloor'],$layout->result['profileId'],$layout->result['objects']);
        $text=parent::prompt($layout,$prior).LabRoomFloorWitnessUnion::prompt($layout->result['sourceFloor']);
        abort_unless(mb_strlen($text)<=8000,422,'The complete numeric floor-union prompt exceeds8000characters.');return $text;
    }
    public function apply(IguideLabRoomAssessment $record,IguideLabCase $case,array $input,?array $invocation): array
    {
        abort_unless(LabRoomFloorWitnessUnion::selected($record->data['recipe']),422);
        // Parent revalidates current source, layout, actual SAM receipt and correction parent on every attempt.
        $plan=parent::apply($record,$case,$input,$invocation);
        abort_unless($record->result_sha256===$plan['resultSha256']
            &&LabMachineSegmentationPlan::canonicalHash($record->result)===$plan['resultSha256'],422,'Source union layout changed during image binding.');
        return $plan+['sourceFloorGeometry'=>LabRoomFloorWitnessUnion::geometryBinding($record->result['sourceFloor'])];
    }
}
