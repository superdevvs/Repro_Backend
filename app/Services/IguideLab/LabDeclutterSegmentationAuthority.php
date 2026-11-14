<?php
namespace App\Services\IguideLab;
use App\Contracts\LabMachineSegmentationCapability;
use App\Models\{IguideLabMachineSegmentation,IguideLabWorkflowStep};
/** Closed square source-only declutter capability; not registered or enabled by this class. */
class LabDeclutterSegmentationAuthority implements LabMachineSegmentationCapability
{
    public function plan(IguideLabWorkflowStep $step): array
    {
        abort_unless(is_string($step->input['assessmentStepId'] ?? null),503,'Persisted removal plan is unavailable.');
        return app(LabDeclutterRemovalPlans::class)->forSegmentation($step)['boundPlan'];
    }
    public function associate(IguideLabWorkflowStep $step,array $receipt): array { return app(LabDeclutterRemovalPlans::class)->associate($step,$receipt); }
    public function validatePlan(array $plan,string $requestId): void { app(LabDeclutterSegmentationPlan::class)->validate($plan,$requestId); }
    public function payload(array $plan,string $bytes): array { return app(LabDeclutterSegmentationPlan::class)->payload($plan,$bytes); }
    public function describeMask(string $bytes): array { return app(LabMachineMaskPixels::class)->describe($bytes); }
    public function readyAssociationStatus(): string { return 'ready_for_support_validation'; }
    public function verifyOwnedInputs(IguideLabWorkflowStep $step,array $plan): array
    {
        abort_unless(($step->input['protocolVersion'] ?? null)===LabDeclutterRemovalPolicy::SEGMENTATION,422);
        $trusted=app(LabDeclutterRemovalPlans::class)->forSegmentation($step);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($trusted['boundPlan'])===LabMachineSegmentationPlan::canonicalHash($plan),422,'Removal support plan changed.');
        $bytes=file_get_contents($trusted['inputPath']);
        abort_unless(strlen($bytes)<=30*1024*1024&&hash_equals($plan['inputSha256'],hash('sha256',$bytes)),422,'Removal source changed.');
        $size=@getimagesizefromstring($bytes);abort_unless($size&&[$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);
        $decoded=@imagecreatefromstring($bytes);abort_unless($decoded!==false,422);imagedestroy($decoded);
        return [$bytes,['assessmentId'=>$trusted['assessmentId'],'assessmentSha256'=>$trusted['assessmentSha256'],
            'sourceSha256'=>$trusted['inputSha256'],'sourceRole'=>'source_cut','contextSha256'=>$plan['contextSha256']]];
    }
    public function prerequisite(IguideLabWorkflowStep $step,array $plan): void
    {
        if ($plan['stepKey']!=='target_local_shadow') return;
        $ids=IguideLabWorkflowStep::where('project_id',$step->project_id)->where('item_id',$step->item_id)->where('kind','segmentation')->pluck('id');
        $found=IguideLabMachineSegmentation::whereIn('step_id',$ids)->where('status','completed')->get()->first(fn($r)=>($r->data['plan']['stepKey'] ?? null)==='target_object'
            && ($r->data['plan']['contextSha256'] ?? null)===$plan['contextSha256'] && ($r->data['plan']['assessmentProposalSha256'] ?? null)===$plan['assessmentProposalSha256']
            && ($r->result['association']['status'] ?? null)==='ready_for_support_validation');
        abort_unless($found,422,'The object receipt must pass before a separately declared local-shadow request.');
        $objectStep=IguideLabWorkflowStep::where('project_id',$step->project_id)->where('item_id',$step->item_id)->findOrFail($found->step_id);
        $this->verifiedReceipt($objectStep);
    }
    /** Revalidate retained raw receipt and all machine coverage before reuse. */
    public function verifiedReceipt(IguideLabWorkflowStep $step): array
    {
        $step->refresh();$plan=$this->plan($step);$this->validatePlan($plan,$step->request_id);
        [$bytes,$binding]=$this->verifyOwnedInputs($step,$plan);unset($bytes);
        $record=IguideLabMachineSegmentation::where('project_id',$step->project_id)->where('step_id',$step->id)->sole();
        abort_unless($record->status==='completed'&&$step->child_id===$record->id&&$record->request_id===$step->request_id
            &&$record->request_hash===$step->input_sha256&&($record->data['plan']??null)===$plan
            &&LabAnalysisRecipes::hash($record->data['assetBinding']??[])===LabAnalysisRecipes::hash($binding)
            &&($record->result['maskOrigin']??null)==='model_segmentation'&&($record->result['selectionOrigin']??null)==='machine'
            &&($record->result['humanReviewed']??null)===false&&($record->result['automaticAcceptance']??null)===false,422,'Stored machine receipt ownership changed.');
        $receipt=$record->result['receipt'];
        abort_unless(($receipt['providerRequestId']??null)===$record->provider_request_id&&($receipt['status']??null)==='completed',422,'Provider receipt identity changed.');
        $base='iguide-lab/'.$step->project_id.'/machine-segmentations/'.$record->id;$loader=app(LabDeclutterRemovalContext::class);
        $loader->file($step->project_id,$base.'/provider-result.json',$receipt['rawResponseSha256'],LabMachineSegmentationTransport::MAX_RESPONSE_BYTES);
        $artifacts=$record->result['artifacts']??[];
        abort_unless(count($artifacts)===count($receipt['masks'])&&count($artifacts)===count($plan['expectedIdentities']),422);
        foreach($artifacts as $index=>$asset){
            abort_unless(($asset['index']??null)===$index&&($asset['path']??null)===$base.'/mask-'.$index.'.png',422);
            $path=$loader->file($step->project_id,$asset['path'],$asset['sha256'],LabMachineSegmentationTransport::MAX_MASK_BYTES);
            abort_unless($this->describeMask(file_get_contents($path))===$receipt['masks'][$index],422,'Decoded support mask changed.');
        }
        $association=$this->associate($step,$receipt);
        abort_unless(($association['status']??null)===$this->readyAssociationStatus()
            &&LabAnalysisRecipes::hash($association)===LabAnalysisRecipes::hash($record->result['association']),422,'Machine support association changed.');
        return ['plan'=>$plan,'receipt'=>$receipt,'association'=>$association,'artifacts'=>$artifacts,'recordId'=>$record->id];
    }
}
