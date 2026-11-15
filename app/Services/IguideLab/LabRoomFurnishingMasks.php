<?php
namespace App\Services\IguideLab;
use App\Contracts\LabMachineSegmentationCapability;
use App\Models\{IguideLabCase,IguideLabMachineSegmentation,IguideLabRoomAssessment,IguideLabWorkflowItem,IguideLabWorkflowStep};

class LabRoomFurnishingMasks implements LabMachineSegmentationCapability
{
    public function reserve(IguideLabWorkflowItem $item,IguideLabRoomAssessment $record): IguideLabWorkflowStep
    {
        $record=app(LabInteriorRoomAssessments::class)->completed($item,$record->id,$record->phase);
        $role=match($record->phase){'layout'=>'source-floor','candidate'=>'candidate-furniture',default=>abort(422)};
        $version=$role==='source-floor'&&LabRoomFloorWitness::selected($record->data['recipe'])?LabRoomFloorWitness::VERSION:LabRoomFurnishingMaskPlan::PROTOCOL;
        return app(LabWorkflowSteps::class)->reserve($item,'room-'.$role,'segmentation',['protocolVersion'=>$version,
            'assessmentId'=>$record->id,'assessmentStepId'=>$record->step_id,'maskRole'=>$role]);
    }
    private function owned(IguideLabWorkflowStep $step): array
    {
        abort_unless(config('iguide-lab.interior_room_routing_enabled',false)===true,503);
        LabInteriorDesignProtocol::keys($step->input,['protocolVersion','assessmentId','assessmentStepId','maskRole']);
        abort_unless($step->kind==='segmentation'&&in_array($step->input['protocolVersion'],[LabRoomFurnishingMaskPlan::PROTOCOL,LabRoomFloorWitness::VERSION],true)&&$step->input_sha256===LabAnalysisRecipes::hash($step->input),422);
        app(LabWorkflowInvocation::class)->verify(app(LabWorkflowSteps::class)->invocation($step),$step->project_id,$step->request_id,'segmentation');
        $item=IguideLabWorkflowItem::where('project_id',$step->project_id)->findOrFail($step->item_id);$route=app(LabInteriorRoomRoutes::class)->verify($item);
        $phase=match($step->input['maskRole']){'source-floor'=>'layout','candidate-furniture'=>'candidate',default=>abort(422)};
        $record=app(LabInteriorRoomAssessments::class)->completed($item,$step->input['assessmentId'],$phase);$b=$record->data['contextSnapshot'];
        abort_unless($record->step_id===$step->input['assessmentStepId']&&$step->step_key==='room-'.$step->input['maskRole']
            &&$record->result['status']===($phase==='layout'?'ready_for_image':'ready_for_masks'),422);
        if($phase==='layout'){$case=IguideLabCase::where('project_id',$item->project_id)->findOrFail($b['cutCaseId']);$asset=['path'=>$case->source_path,'sha256'=>$case->source_sha256];
            $selections=[$record->result['sourceFloor']];}
        else {$asset=$b['assets'][1];abort_unless($asset['id']==='candidate',422);$selections=$record->result['objects'];}
        $path=app(LabInteriorRoomContext::class)->file($step->project_id,$asset['path'],$asset['sha256'],30*1024*1024);
        $v2=$phase==='layout'&&LabRoomFloorWitness::selected($record->data['recipe']);
        abort_unless($step->input['protocolVersion']===($v2?LabRoomFloorWitness::VERSION:LabRoomFurnishingMaskPlan::PROTOCOL),422,'Floor semantics cannot change on an existing reservation.');
        $plan=$v2?app(LabRoomFloorWitness::class)->bind($step->request_id,$route['profileId'],$selections[0],$asset['sha256'],$item->source['sha256'],$record->result_sha256,LabMachineSegmentationPlan::canonicalHash($b['context']))
            :app(LabRoomFurnishingMaskPlan::class)->bind($step->request_id,$step->input['maskRole'],$route['profileId'],$selections,$asset['sha256'],$record->result_sha256,LabMachineSegmentationPlan::canonicalHash($b['context']));
        return ['item'=>$item,'record'=>$record,'plan'=>$plan,'inputPath'=>$path,'binding'=>['assessmentId'=>$record->id,'assessmentSha256'=>$record->result_sha256,
            'sourceRole'=>$step->input['maskRole'],'inputSha256'=>$asset['sha256'],'profileId'=>$route['profileId'],'routeSha256'=>LabMachineSegmentationPlan::canonicalHash($route)]];
    }
    public function plan(IguideLabWorkflowStep $step): array {return $this->owned($step)['plan'];}
    private function planner(array $plan): LabRoomFloorWitness|LabRoomFurnishingMaskPlan {return isset($plan['witness'])?app(LabRoomFloorWitness::class):app(LabRoomFurnishingMaskPlan::class);}
    public function validatePlan(array $plan,string $requestId): void {$this->planner($plan)->validate($plan,$requestId);}
    public function payload(array $plan,string $bytes): array {return $this->planner($plan)->payload($plan,$bytes);}
    public function describeMask(string $bytes): array {return app(LabMachineMaskPixels::class)->describe($bytes);}
    public function readyAssociationStatus(): string {return 'ready_for_room_support';}
    public function associate(IguideLabWorkflowStep $step,array $receipt): array
    {
        $owned=$this->owned($step);$plan=$owned['plan'];
        if(!isset($plan['witness']))return app(LabRoomFurnishingMaskPlan::class)->associate($plan,$receipt);
        // Association runs after canonical files are retained but before the result is saved.
        $record=IguideLabMachineSegmentation::where('project_id',$step->project_id)->where('step_id',$step->id)->sole();
        abort_unless($step->child_id===$record->id&&in_array($record->status,['processing','completed'],true)&&$record->request_id===$step->request_id&&$record->request_hash===$step->input_sha256&&$record->provider_request_id===$receipt['providerRequestId']
            &&LabMachineSegmentationPlan::canonicalHash($record->data['plan']??[])===LabMachineSegmentationPlan::canonicalHash($plan)
            &&LabMachineSegmentationPlan::canonicalHash($record->data['assetBinding']??[])===LabMachineSegmentationPlan::canonicalHash($owned['binding']),422);
        $base='iguide-lab/'.$step->project_id.'/machine-segmentations/'.$record->id;$loader=app(LabInteriorRoomContext::class);
        $loader->file($step->project_id,$base.'/provider-result.json',$receipt['rawResponseSha256'],LabMachineSegmentationTransport::MAX_RESPONSE_BYTES);
        abort_unless(count($receipt['masks'])===1,422);
        $path=$loader->file($step->project_id,$base.'/mask-0.png',$receipt['masks'][0]['sha256'],LabMachineSegmentationTransport::MAX_MASK_BYTES);
        return app(LabRoomFloorWitness::class)->associate($plan,$receipt,file_get_contents($path));
    }
    public function verifyOwnedInputs(IguideLabWorkflowStep $step,array $plan): array
    {
        $owned=$this->owned($step);abort_unless(LabMachineSegmentationPlan::canonicalHash($plan)===LabMachineSegmentationPlan::canonicalHash($owned['plan']),422);
        $bytes=file_get_contents($owned['inputPath']);abort_unless(hash('sha256',$bytes)===$plan['inputSha256'],422);return [$bytes,$owned['binding']];
    }
    public function prerequisite(IguideLabWorkflowStep $step,array $plan): void
    {
        if($plan['stepKey']==='candidate-furniture'){$owned=$this->owned($step);$this->floor($owned['item'],$owned['record']->data['contextSnapshot']['layoutId']);}
    }
    public function floor(IguideLabWorkflowItem $item,string $layoutId): array
    {
        $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('step_key','room-source-floor')->sole();
        abort_unless(($step->input['assessmentId']??null)===$layoutId,422);return $this->verifiedReceipt($step);
    }
    public function verifiedReceipt(IguideLabWorkflowStep $step): array
    {
        $step->refresh();$owned=$this->owned($step);$plan=$owned['plan'];$this->validatePlan($plan,$step->request_id);
        $record=IguideLabMachineSegmentation::where('project_id',$step->project_id)->where('step_id',$step->id)->sole();
        abort_unless($record->status==='completed'&&$step->child_id===$record->id&&$record->request_id===$step->request_id&&$record->request_hash===$step->input_sha256
            &&LabMachineSegmentationPlan::canonicalHash($record->data['plan']??[])===LabMachineSegmentationPlan::canonicalHash($plan)
            &&LabMachineSegmentationPlan::canonicalHash($record->data['assetBinding']??[])===LabMachineSegmentationPlan::canonicalHash($owned['binding'])
            &&($record->result['maskOrigin']??null)==='model_segmentation'&&($record->result['selectionOrigin']??null)==='machine'
            &&($record->result['humanReviewed']??null)===false&&($record->result['automaticAcceptance']??null)===false,422);
        $receipt=$record->result['receipt'];abort_unless(($receipt['providerRequestId']??null)===$record->provider_request_id&&($receipt['status']??null)==='completed',422);
        $base='iguide-lab/'.$step->project_id.'/machine-segmentations/'.$record->id;$loader=app(LabInteriorRoomContext::class);
        $loader->file($step->project_id,$base.'/provider-result.json',$receipt['rawResponseSha256'],LabMachineSegmentationTransport::MAX_RESPONSE_BYTES);
        $assets=$record->result['artifacts']??[];abort_unless(count($assets)===count($receipt['masks'])&&count($assets)===count($plan['expectedIdentities']),422);
        foreach($assets as $i=>$asset){abort_unless(($asset['index']??null)===$i&&($asset['path']??null)===$base.'/mask-'.$i.'.png',422);
            $path=$loader->file($step->project_id,$asset['path'],$asset['sha256'],LabMachineSegmentationTransport::MAX_MASK_BYTES);
            abort_unless($this->describeMask(file_get_contents($path))===$receipt['masks'][$i],422);}
        $association=isset($plan['witness'])?$this->associate($step,$receipt):app(LabRoomFurnishingMaskPlan::class)->associate($plan,$receipt);
        abort_unless($association['status']===$this->readyAssociationStatus()&&LabMachineSegmentationPlan::canonicalHash($association)===LabMachineSegmentationPlan::canonicalHash($record->result['association']),422);
        return ['recordId'=>$record->id,'plan'=>$plan,'receipt'=>$receipt,'association'=>$association,'artifacts'=>$assets];
    }
}
