<?php
namespace App\Services\IguideLab;
use App\Contracts\LabMachineSegmentationCapability;
use App\Models\{IguideLabCase,IguideLabRun,IguideLabSection,IguideLabMachineSegmentation,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;
/** Closed square staging capability, rooted in a completed typed assessment. */
class LabStagingSegmentationAuthority implements LabMachineSegmentationCapability
{
    public function plan(IguideLabWorkflowStep $step): array
    {
        abort_unless(is_string($step->input['assessmentStepId'] ?? null),503,'Persisted staging assessment is unavailable.');
        return app(LabStagingAssessments::class)->forSegmentation($step)['boundPlan'];
    }
    public function associate(IguideLabWorkflowStep $step,array $receipt): array { return app(LabStagingAssessments::class)->associate($step,$receipt); }
    public function validatePlan(array $plan,string $requestId): void { app(LabMachineSegmentationPlan::class)->validate($plan,$requestId); }
    public function payload(array $plan,string $bytes): array { return app(LabMachineSegmentationPlan::class)->payload($plan,$bytes); }
    public function describeMask(string $bytes): array { return app(LabMachineMaskPixels::class)->describe($bytes); }
    public function readyAssociationStatus(): string { return 'ready_for_support_validation'; }
    public function verifyOwnedInputs(IguideLabWorkflowStep $step,array $plan): array
    {
        abort_unless(($step->input['protocolVersion'] ?? null)===LabMachineSegmentations::PROTOCOL,422,'Unsupported segmentation protocol.');
        $item=IguideLabWorkflowItem::where('project_id',$step->project_id)->findOrFail($step->item_id);
        $native=app(LabWorkflowSources::class)->verify($step->project_id,$item->source);
        $section=IguideLabSection::where('project_id',$step->project_id)->where('status','completed')->findOrFail($step->input['sectionId'] ?? '');
        abort_unless($section->case_id===$native->id,422,'Section belongs to another capture.');
        $case=IguideLabCase::where('project_id',$step->project_id)->findOrFail($section->derived_case_id);
        $snapshot=app(LabMasks::class)->sectionSnapshot($step->project_id,$section->id,$case,null);
        $run=IguideLabRun::where('project_id',$step->project_id)->where('case_id',$case->id)->where('status','completed')->findOrFail($step->input['candidateRunId'] ?? '');
        abort_unless($case->projection==='rectilinear' && [$case->width,$case->height,$run->output_width,$run->output_height]===[2048,2048,2048,2048]
            && ($run->options['sourceSection'] ?? null)===$snapshot && app(LabOutputDigests::class)->dto($run)!==null,422,'Candidate section provenance is unavailable.');
        $source=$this->ownedBytes($step->project_id,$case->source_path,'cases/'.$case->id.'.png',$case->source_sha256);
        $candidate=$this->ownedBytes($step->project_id,$run->output_path,'runs/'.$run->id.'.png',$run->output_sha256);
        $submitted=hash('sha256',LabImages::prepared($source));
        abort_unless(($run->options['preparedInputSha256'][0] ?? null)===$submitted,422,'The exact generation source preparation changed.');
        $bytes=$plan['stepKey']==='source-floor'?$source:$candidate;
        abort_unless(hash_equals($plan['inputSha256'],hash('sha256',$bytes)),422,'The selected segmentation source changed.');
        return [$bytes,['nativeSource'=>$item->source,'section'=>$snapshot,'caseId'=>$case->id,'candidateRunId'=>$run->id,
            'sourceCutSha256'=>$case->source_sha256,'submittedSourceSha256'=>$submitted,'candidateSha256'=>$run->output_sha256]];
    }
    private function ownedBytes(string $projectId,string $path,string $suffix,string $sha): string
    {
        $disk=Storage::disk(LabService::DISK); $expected='iguide-lab/'.$projectId.'/'.$suffix;
        $root=realpath($disk->path('iguide-lab')); $file=$disk->path($path); $real=realpath($file);
        abort_unless($path===$expected && $root && $real && $real===$root.DIRECTORY_SEPARATOR.$projectId.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$suffix)
            && !is_link($file) && filesize($real)>0 && filesize($real)<=30*1024*1024,422,'Segmentation input is outside owned storage.');
        $bytes=file_get_contents($real); abort_unless(hash_equals($sha,hash('sha256',$bytes)),422,'Segmentation input bytes changed.');
        $size=@getimagesizefromstring($bytes); abort_unless($size && ($size['mime'] ?? null)==='image/png' && [$size[0],$size[1]]===[2048,2048],422,'Stored segmentation input dimensions changed.');
        $decoded=@imagecreatefromstring($bytes); abort_unless($decoded!==false,422,'Stored segmentation input cannot be decoded.'); imagedestroy($decoded);
        return $bytes;
    }
    public function prerequisite(IguideLabWorkflowStep $step,array $plan): void
    {
        if ($plan['stepKey']!=='candidate-furniture') return;
        $ids=IguideLabWorkflowStep::where('project_id',$step->project_id)->where('item_id',$step->item_id)->where('kind','segmentation')->pluck('id');
        $found=IguideLabMachineSegmentation::whereIn('step_id',$ids)->where('status','completed')->get()->first(fn($r)=>($r->data['plan']['stepKey'] ?? null)==='source-floor'
            && ($r->data['plan']['contextSha256'] ?? null)===$plan['contextSha256'] && ($r->data['plan']['assessmentProposalSha256'] ?? null)===$plan['assessmentProposalSha256']
            && ($r->result['association']['status'] ?? null)==='ready_for_support_validation');
        abort_unless($found,422,'The original-floor receipt must pass before spending the furniture request.');
        $floorStep=IguideLabWorkflowStep::where('project_id',$step->project_id)->where('item_id',$step->item_id)->findOrFail($found->step_id);
        $this->verifiedReceipt($floorStep);
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
        $base='iguide-lab/'.$step->project_id.'/machine-segmentations/'.$record->id;$loader=app(LabStagingAssessmentContext::class);
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
