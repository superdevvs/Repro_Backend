<?php
namespace App\Services\IguideLab;
use App\Contracts\LabMachineSegmentationCapability;
use App\Models\{IguideLabInteriorAssessment,IguideLabMachineSegmentation,IguideLabWorkflowItem,IguideLabWorkflowStep};

/** Typed per-region machine selections. No client mask/review assertion is ever consumed. */
class LabInteriorDesignMasks implements LabMachineSegmentationCapability
{
    public function selections(IguideLabInteriorAssessment $record): array
    {
        $out=[];
        if($record->phase==='cut'){
            abort_unless($record->result['status']==='ready_for_source_masks',422);
            foreach($record->result['foreground'] as $t)$out[]=['role'=>'source-foreground','target'=>$t];
            foreach($record->result['targets'] as $t)$out[]=['role'=>'source-material','target'=>$t];
        }elseif($record->phase==='candidate'){
            abort_unless($record->result['status']==='ready_for_candidate_masks',422);
            foreach($record->result['targets'] as $t)$out[]=['role'=>'candidate-material','target'=>$t];
        }else abort(422,'Source framing cannot authorize segmentation.');return $out;
    }
    public function reserve(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $record): array
    {
        $record=app(LabInteriorDesignAssessments::class)->completed($item,$record->id,$record->phase);$steps=[];
        foreach($this->selections($record) as $s)$steps[]=app(LabWorkflowSteps::class)->reserve($item,'interior-'.$s['role'].'-'.$s['target']['id'],'segmentation',
            ['protocolVersion'=>LabInteriorDesignPolicy::SEGMENTATION,'assessmentId'=>$record->id,'assessmentStepId'=>$record->step_id,'maskRole'=>$s['role'],'targetId'=>$s['target']['id']]);
        return $steps;
    }
    public function owned(IguideLabWorkflowStep $step): array
    {
        abort_unless(config('iguide-lab.interior_design_workflow_enabled',false)===true,503);
        LabInteriorDesignProtocol::keys($step->input,['protocolVersion','assessmentId','assessmentStepId','maskRole','targetId']);
        abort_unless($step->kind==='segmentation'&&$step->input['protocolVersion']===LabInteriorDesignPolicy::SEGMENTATION&&$step->input_sha256===LabAnalysisRecipes::hash($step->input),422);
        app(LabWorkflowInvocation::class)->verify(app(LabWorkflowSteps::class)->invocation($step),$step->project_id,$step->request_id,'segmentation');
        $item=IguideLabWorkflowItem::where('project_id',$step->project_id)->findOrFail($step->item_id);
        $record=IguideLabInteriorAssessment::where('project_id',$step->project_id)->findOrFail($step->input['assessmentId']);
        $record=app(LabInteriorDesignAssessments::class)->completed($item,$record->id,$record->phase);
        abort_unless($record->step_id===$step->input['assessmentStepId'],422);
        $selected=collect($this->selections($record))->filter(fn($s)=>$s['role']===$step->input['maskRole']&&$s['target']['id']===$step->input['targetId'])->values();abort_unless($selected->count()===1,422);
        $selection=$selected->first();abort_unless($step->step_key==='interior-'.$selection['role'].'-'.$selection['target']['id'],422);
        $bundle=$record->data['contextSnapshot'];$asset=$bundle['assets'][$record->phase==='candidate'?1:0];
        abort_unless($asset['id']===($record->phase==='candidate'?'candidate':'source'),422);
        $path=app(LabInteriorDesignContext::class)->file($step->project_id,$asset['path'],$asset['sha256'],30*1024*1024);
        $plan=app(LabInteriorDesignMaskPlan::class)->bind($step->request_id,$selection['role'],$selection['target'],$asset['sha256'],$record->result_sha256,LabMachineSegmentationPlan::canonicalHash($bundle['context']));
        return ['record'=>$record,'item'=>$item,'plan'=>$plan,'inputPath'=>$path,'inputSha256'=>$asset['sha256']];
    }
    public function plan(IguideLabWorkflowStep $step): array {return $this->owned($step)['plan'];}
    public function validatePlan(array $plan,string $requestId): void {app(LabInteriorDesignMaskPlan::class)->validate($plan,$requestId);}
    public function payload(array $plan,string $bytes): array {return app(LabInteriorDesignMaskPlan::class)->payload($plan,$bytes);}
    public function describeMask(string $bytes): array {return app(LabMachineMaskPixels::class)->describe($bytes);}
    public function readyAssociationStatus(): string {return 'ready_for_material_support';}
    public function associate(IguideLabWorkflowStep $step,array $receipt): array {return app(LabInteriorDesignMaskPlan::class)->associate($this->owned($step)['plan'],$receipt);}
    public function verifyOwnedInputs(IguideLabWorkflowStep $step,array $plan): array
    {
        $owned=$this->owned($step);abort_unless(LabMachineSegmentationPlan::canonicalHash($owned['plan'])===LabMachineSegmentationPlan::canonicalHash($plan),422);
        $bytes=file_get_contents($owned['inputPath']);abort_unless(hash('sha256',$bytes)===$plan['inputSha256'],422);
        return [$bytes,['assessmentId'=>$owned['record']->id,'assessmentSha256'=>$owned['record']->result_sha256,'inputSha256'=>$owned['inputSha256'],
            'sourceRole'=>$plan['maskRole'],'targetId'=>$plan['targetId'],'contextSha256'=>$plan['contextSha256']]];
    }
    public function prerequisite(IguideLabWorkflowStep $step,array $plan): void
    {
        $owned=$this->owned($step);$prior=[];
        foreach($this->selections($owned['record']) as $selection){
            if($selection['role']===$plan['maskRole']&&$selection['target']['id']===$plan['targetId'])break;
            $prior[]='interior-'.$selection['role'].'-'.$selection['target']['id'];
        }
        foreach($prior as $key){$priorStep=IguideLabWorkflowStep::where('project_id',$step->project_id)->where('item_id',$step->item_id)->where('kind','segmentation')->where('step_key',$key)->sole();$this->verifiedReceipt($priorStep);}
    }
    public function verifiedReceipt(IguideLabWorkflowStep $step): array
    {
        $step->refresh();$plan=$this->plan($step);$this->validatePlan($plan,$step->request_id);[$bytes,$binding]=$this->verifyOwnedInputs($step,$plan);unset($bytes);
        $record=IguideLabMachineSegmentation::where('project_id',$step->project_id)->where('step_id',$step->id)->sole();
        abort_unless($record->status==='completed'&&$step->child_id===$record->id&&$record->request_id===$step->request_id&&$record->request_hash===$step->input_sha256
            &&LabMachineSegmentationPlan::canonicalHash($record->data['plan']??[])===LabMachineSegmentationPlan::canonicalHash($plan)
            &&LabAnalysisRecipes::hash($record->data['assetBinding']??[])===LabAnalysisRecipes::hash($binding)
            &&($record->result['maskOrigin']??null)==='model_segmentation'&&($record->result['selectionOrigin']??null)==='machine'
            &&($record->result['humanReviewed']??null)===false&&($record->result['automaticAcceptance']??null)===false,422,'Material mask record association changed.');
        $receipt=$record->result['receipt'];abort_unless(($receipt['providerRequestId']??null)===$record->provider_request_id&&($receipt['status']??null)==='completed',422);
        $base='iguide-lab/'.$step->project_id.'/machine-segmentations/'.$record->id;$loader=app(LabInteriorDesignContext::class);
        $loader->file($step->project_id,$base.'/provider-result.json',$receipt['rawResponseSha256'],LabMachineSegmentationTransport::MAX_RESPONSE_BYTES);
        $assets=$record->result['artifacts']??[];abort_unless(count($assets)===1&&count($receipt['masks'])===1,422);
        $asset=$assets[0];abort_unless(($asset['index']??null)===0&&($asset['path']??null)===$base.'/mask-0.png',422);
        $path=$loader->file($step->project_id,$asset['path'],$asset['sha256'],LabMachineSegmentationTransport::MAX_MASK_BYTES);
        abort_unless($this->describeMask(file_get_contents($path))===$receipt['masks'][0],422,'Material mask pixels changed.');
        $association=$this->associate($step,$receipt);abort_unless($association['status']===$this->readyAssociationStatus()
            &&LabAnalysisRecipes::hash($association)===LabAnalysisRecipes::hash($record->result['association']),422,'Material mask association changed.');
        return ['plan'=>$plan,'receipt'=>$receipt,'association'=>$association,'artifacts'=>$assets,'recordId'=>$record->id];
    }
    public function verifiedFor(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $assessment): array
    {
        $assessment=app(LabInteriorDesignAssessments::class)->completed($item,$assessment->id,$assessment->phase);$out=[];
        foreach($this->selections($assessment) as $s){$key='interior-'.$s['role'].'-'.$s['target']['id'];
            $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('kind','segmentation')->where('step_key',$key)->sole();$out[$s['role'].':'.$s['target']['id']]=$this->verifiedReceipt($step);}
        return $out;
    }
}
