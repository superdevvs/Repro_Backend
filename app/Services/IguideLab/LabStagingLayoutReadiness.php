<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Rebuilds source, original framing and bounded predecessor receipt authority before every spend. */
class LabStagingLayoutReadiness
{
    public function reference(IguideLabWorkflowItem $item,string $framingId,IguideLabCase $cut,?string $priorId=null): array
    {
        $framing=app(LabStagingFramings::class)->completed($item,$framingId);
        abort_unless($item->preset==='staging'&&$framing->result['status']==='framing_candidate',422);
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $section=IguideLabSection::where('project_id',$item->project_id)->where('status','completed')->where('derived_case_id',$cut->id)->sole();
        $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('kind','section')->where('child_id',$section->id)->sole();
        abort_unless($section->case_id===$native->id&&$section->source_sha256===$native->source_sha256
            &&$step->input_sha256===LabAnalysisRecipes::hash($step->input)&&($step->input['caseId']??null)===$native->id
            &&LabMachineSegmentationPlan::canonicalHash($step->input['view']??[])===LabMachineSegmentationPlan::canonicalHash($section->view)
            &&$cut->projection==='rectilinear'&&[$cut->width,$cut->height]===[2048,2048],422,'Source layout section association changed.');
        $context=app(LabAnalysisSourceContext::class)->capture($cut);
        abort_unless($context&&$context['binding']['sectionId']===$section->id&&$context['binding']['nativeCaseId']===$native->id,422);
        $followup=['ordinal'=>0,'operation'=>'initial','previousAnalysisId'=>null,'previousResultSha256'=>null];$details=[];$priorResult=null;
        $view=$framing->result['view'];$expectedStep='staging-section';
        if($priorId!==null){
            $prior=IguideLabAnalysis::where('project_id',$item->project_id)->findOrFail($priorId);
            // Check before recursion; no client or corrupted record may build an authority cycle.
            abort_unless(($prior->data['stagingLayout']['followup']['ordinal']??null)===0
                &&($prior->data['workflowInvocation']['itemId']??null)===$item->id
                &&($prior->data['stagingLayout']['framingId']??null)===$framingId,422,'Only an owned initial layout can authorize a follow-up.');
            $gate=$this->verified($prior,false);
            abort_unless(in_array($gate['decision'],['request_details','request_reframe'],true),422,'Prior layout did not request source work.');
            $operation=$gate['decision']==='request_details'?'native_details':'reframe';
            if($operation==='native_details'){
                abort_unless($prior->case_id===$cut->id,422,'Detail context cannot change the edit cut.');
                $details=app(LabStagingLayoutViews::class)->details($item,$prior,$gate)['views'];
            }else{$view=$gate['reframePose'];$expectedStep='staging-reframed-section';abort_if($prior->case_id===$cut->id,422);}
            $followup=['ordinal'=>1,'operation'=>$operation,'previousAnalysisId'=>$prior->id,'previousResultSha256'=>$this->resultHash($prior)];
            $priorResult=['proposalSha256'=>$prior->proposal_sha256,'layout'=>$gate];
        }
        abort_unless($step->step_key===$expectedStep,422,'Source layout section step changed.');
        foreach(['yaw','pitch','hFov'] as $key)abort_unless((float)$section->view[$key]===(float)$view[$key],422,'Source layout pose changed.');
        $png=LabImages::prepared(Storage::disk(LabService::DISK)->get($cut->source_path));
        return ['version'=>LabStagingLayoutProtocol::GATE,'framingId'=>$framing->id,'framingResultSha256'=>$framing->result_sha256,
            'nativeSourceSha256'=>$native->source_sha256,'sourceCutSha256'=>$cut->source_sha256,'preparedSourceSha256'=>hash('sha256',$png),
            'sourceGeometrySha256'=>$context['binding']['geometrySha256'],'followup'=>$followup,'detailViews'=>$details,'priorResult'=>$priorResult];
    }
    public function resultHash(IguideLabAnalysis $analysis): string
    {return LabMachineSegmentationPlan::canonicalHash(['proposalSha256'=>$analysis->proposal_sha256,'layout'=>$analysis->data['stagingLayoutResult']??null]);}
    public function verifyReference(array $invocation,IguideLabCase $cut,array $reference): void
    {
        abort_unless(config('iguide-lab.staging_layout_enabled',false)===true,503,'Prospective source layout is disabled.');
        $item=IguideLabWorkflowItem::where('project_id',$cut->project_id)->findOrFail($invocation['itemId']??'');
        $workflow=IguideLabWorkflow::where('project_id',$cut->project_id)->findOrFail($item->workflow_id);
        abort_unless(isset($workflow->policy['stagingLayout'],$workflow->policy['stagingFraming'])&&($invocation['workflowId']??null)===$workflow->id,422);
        app(LabStagingLayouts::class)->validate($workflow->policy['stagingLayout']);
        $fresh=$this->reference($item,$reference['framingId']??'',$cut,$reference['followup']['previousAnalysisId']??null);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($fresh)===LabMachineSegmentationPlan::canonicalHash($reference),422,'Source layout authority changed.');
    }
    private function inputs(IguideLabAnalysis $analysis): array
    {
        $data=$analysis->data;$reference=$data['stagingLayout']??null;$invocation=$data['workflowInvocation']??null;
        abort_unless(is_array($reference)&&is_array($invocation),422);
        $cut=IguideLabCase::where('project_id',$analysis->project_id)->findOrFail($analysis->case_id);$this->verifyReference($invocation,$cut,$reference);
        $step=IguideLabWorkflowStep::where('project_id',$analysis->project_id)->where('item_id',$invocation['itemId'])->findOrFail($invocation['stepId']);
        $expected=$reference['followup']['ordinal']===0?'staging-layout-initial':'staging-layout-followup';
        abort_unless($step->kind==='analysis'&&$step->step_key===$expected&&$step->child_id===$analysis->id&&$step->request_id===$analysis->request_id
            &&$step->input_sha256===LabAnalysisRecipes::hash($step->input)&&$invocation===app(LabWorkflowSteps::class)->invocation($step)
            &&($step->input['caseId']??null)===$cut->id&&($step->input['preset']??null)==='staging'
            &&($step->input['style']??null)===$analysis->settings['style']&&$analysis->source_sha256===$cut->source_sha256
            &&LabMachineSegmentationPlan::canonicalHash($step->input['stagingLayout']??[])===LabMachineSegmentationPlan::canonicalHash($reference),422,'Source layout step association changed.');
        $recipe=app(LabAnalysisRecipes::class)->validate($data['executionRecipe']);abort_unless($recipe['compilerVersion']===LabStagingLayoutProtocol::VERSION,422);
        app(LabAnalysisSourceContext::class)->verify($cut,$data['sourceContext']);app(LabStagingLayoutViews::class)->verifyStored($analysis);
        $context=['sourceSha256'=>$analysis->source_sha256,'projection'=>'rectilinear','preset'=>'staging','style'=>$analysis->settings['style'],
            'viewIds'=>array_column($data['views'],'id'),'instructions'=>app(LabAnalyses::class)->wireInstructions($analysis->settings),
            'sourceContext'=>$data['sourceContextManifest'],'stagingLayout'=>$reference];
        $images=[];foreach($data['views'] as $v)$images[]='data:image/png;base64,'.base64_encode(file_get_contents(app(LabStagingFramingContext::class)->file($analysis->project_id,$v['path'],$v['sha256'])));
        $payload=app(LabAnalysisRecipes::class)->request($data['executionRecipe'],$context,$images);
        abort_unless(LabAnalysisRecipes::hash($payload)===$data['providerBodySha256'],422,'Source layout payload changed.');return [$context,$payload];
    }
    public function verifyForAttempt(IguideLabAnalysis $analysis,array $payload): void
    {
        $analysis->refresh();abort_unless($analysis->status==='submitting',422);
        [, $fresh]=$this->inputs($analysis);
        abort_unless(LabAnalysisRecipes::hash($fresh)===LabAnalysisRecipes::hash($payload),422,'Source layout attempt payload changed.');
    }
    public function verified(IguideLabAnalysis $analysis,bool $requirePass=true): array
    {
        abort_unless($analysis->status==='completed'&&$analysis->proposal_sha256===LabAnalysisRecipes::hash($analysis->proposal),422);
        [$context,$payload]=$this->inputs($analysis);$data=$analysis->data;$receipt=$data['stagingLayoutReceipt']??[];
        $attempts=$analysis->provider_attempts??[];$index=count($attempts)-1;$last=$attempts[$index]??[];
        abort_unless(in_array(count($attempts),[1,2],true)&&($attempts[0]['credentialSource']??null)==='lab-primary'
            &&($attempts[0]['requestedModelId']??null)==='gpt-6-astra'
            &&($last['status']??null)==='received'&&($last['requestedModelId']??null)==='gpt-6-astra'
            &&($last['httpStatus']??0)>=200&&($last['httpStatus']??0)<300&&is_string($analysis->provider_request_id)&&$analysis->provider_request_id!==''
            &&($last['providerRequestId']??null)===$analysis->provider_request_id
            &&($index===0||(($attempts[0]['status']??null)==='quota_rejected'&&($attempts[0]['httpStatus']??null)===429&&($last['credentialSource']??null)==='dashboard-fallback'))
            &&($receipt['payloadSha256']??null)===LabAnalysisRecipes::hash($payload)&&($receipt['providerRequestId']??null)===$analysis->provider_request_id,422,'Source layout receipt association changed.');
        $path='iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id.'/response-'.$index.'.json';abort_unless(($receipt['path']??null)===$path,422);
        $raw=json_decode(file_get_contents(app(LabStagingFramingContext::class)->file($analysis->project_id,$path,$receipt['sha256']??'',512*1024)),true,512,JSON_THROW_ON_ERROR);
        abort_unless(($raw['model']??null)==='gpt-6-astra'&&is_string($raw['id']??null)&&$raw['id']!==''&&$raw['id']===($receipt['responseId']??null),422);
        $parsed=LabStagingLayoutProtocol::parse($raw,$context);$gate=$parsed['stagingLayout'];
        abort_unless(LabAnalysisRecipes::hash($parsed['proposal'])===$analysis->proposal_sha256
            &&LabMachineSegmentationPlan::canonicalHash($gate)===LabMachineSegmentationPlan::canonicalHash($data['stagingLayoutResult']??[]),422,'Saved source layout result changed.');
        if($requirePass)abort_unless($gate['eligibleForDraft']===true&&($analysis->proposal['workflow']['mode']??null)==='whole_panorama'
            &&app(LabStagingWorkflow::class)->threeTargets($analysis->proposal),422,'Prospective source layout still requires review.');
        return $gate;
    }
    public function verifyImageStep(IguideLabWorkflowStep $step): void
    {
        $analysis=IguideLabAnalysis::where('project_id',$step->project_id)->findOrFail($step->input['options']['analysisId']??'');$this->verified($analysis);
        abort_unless($analysis->case_id===($step->input['caseId']??null)&&$analysis->data['workflowInvocation']['itemId']===$step->item_id
            &&($step->input['prompt']??null)===$analysis->proposal['generationPrompt'],422,'Image is not the exact source-layout draft.');
    }
}
