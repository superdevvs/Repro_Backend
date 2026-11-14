<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Receipt-bound actual-cut authority. Source-framing candidates alone never authorize paid image claims. */
class LabStagingCutReadiness
{
    public function reference(IguideLabWorkflowItem $item,string $framingId,IguideLabCase $cut): array
    {
        $framing=app(LabStagingFramings::class)->completed($item,$framingId);$result=$framing->result;
        abort_unless($result['status']==='framing_candidate'&&$result['imageGenerationAuthorized']===false,422,'No source framing candidate.');
        $section=IguideLabSection::where('project_id',$item->project_id)->where('status','completed')->where('derived_case_id',$cut->id)->sole();
        $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('kind','section')->where('child_id',$section->id)->sole();
        abort_unless($section->case_id===$item->case_id&&$section->source_sha256===$item->source['sha256']&&$step->input_sha256===LabAnalysisRecipes::hash($step->input)
            &&$cut->projection==='rectilinear'&&[$cut->width,$cut->height]===[2048,2048],422,'Framing section association changed.');
        foreach(['yaw','pitch','hFov'] as $key)abort_unless((float)$section->view[$key]===(float)$result['view'][$key],422,'Framing section pose changed.');
        $context=app(LabAnalysisSourceContext::class)->capture($cut);abort_unless($context&&$context['binding']['sectionId']===$section->id&&$context['binding']['nativeCaseId']===$item->case_id,422);
        return ['version'=>LabStagingFramingProtocol::VERSION,'id'=>$framing->id,'resultSha256'=>$framing->result_sha256,
            'nativeSourceSha256'=>$item->source['sha256'],'view'=>$result['view'],'constraints'=>$result['constraints']];
    }
    public function verifyReference(array $invocation,IguideLabCase $cut,array $reference): void
    {
        $item=IguideLabWorkflowItem::where('project_id',$cut->project_id)->findOrFail($invocation['itemId']??'');
        $workflow=IguideLabWorkflow::where('project_id',$cut->project_id)->findOrFail($item->workflow_id);
        abort_unless(isset($workflow->policy['stagingFraming'])&&($invocation['workflowId']??null)===$workflow->id,422,'Framing requires its reserved workflow.');
        $fresh=$this->reference($item,$reference['id']??'',$cut);
        abort_unless(LabAnalysisRecipes::hash($fresh)===LabAnalysisRecipes::hash($reference),422,'Framing reference changed.');
    }
    public function verified(IguideLabAnalysis $analysis,bool $requirePass=true): array
    {
        $data=$analysis->data;$reference=$data['stagingFraming']??null;$invocation=$data['workflowInvocation']??null;
        abort_unless($analysis->status==='completed'&&is_array($reference)&&is_array($invocation),422,'Actual cut readiness is incomplete.');
        $cut=IguideLabCase::where('project_id',$analysis->project_id)->findOrFail($analysis->case_id);$this->verifyReference($invocation,$cut,$reference);
        $step=IguideLabWorkflowStep::where('project_id',$analysis->project_id)->where('item_id',$invocation['itemId'])->findOrFail($invocation['stepId']);
        abort_unless($step->kind==='analysis'&&$step->child_id===$analysis->id&&$step->request_id===$analysis->request_id
            &&($step->input['caseId']??null)===$analysis->case_id&&($step->input['preset']??null)==='staging'&&($step->input['style']??null)===$analysis->settings['style']
            &&$step->input_sha256===LabAnalysisRecipes::hash($step->input)&&$invocation===app(LabWorkflowSteps::class)->invocation($step)
            &&LabAnalysisRecipes::hash($step->input['stagingFraming']??[])===LabAnalysisRecipes::hash($reference)
            &&$analysis->source_sha256===$cut->source_sha256&&$analysis->proposal_sha256===LabAnalysisRecipes::hash($analysis->proposal),422,'Cut gate ownership changed.');
        $recipe=app(LabAnalysisRecipes::class)->validate($data['executionRecipe']);abort_unless($recipe['compilerVersion']===LabStagingCutProtocol::VERSION,422);
        app(LabAnalysisSourceContext::class)->verify($cut,$data['sourceContext']);app(LabSectionAnalysisViews::class)->verifyStored($analysis);
        $context=['sourceSha256'=>$analysis->source_sha256,'projection'=>'rectilinear','preset'=>'staging','style'=>$analysis->settings['style'],
            'viewIds'=>array_column($data['views'],'id'),'instructions'=>app(LabAnalyses::class)->wireInstructions($analysis->settings),
            'sourceContext'=>$data['sourceContextManifest'],'stagingFraming'=>$reference];
        $images=[];$files=app(LabStagingFramingContext::class);
        foreach($data['views'] as $view)$images[]='data:image/png;base64,'.base64_encode(file_get_contents($files->file($analysis->project_id,$view['path'],$view['sha256'])));
        $payload=app(LabAnalysisRecipes::class)->request($data['executionRecipe'],$context,$images);
        $attempts=$analysis->provider_attempts??[];$index=count($attempts)-1;$last=$attempts[$index]??[];$receipt=$data['stagingCutReceipt']??[];
        abort_unless(in_array(count($attempts),[1,2],true)&&($attempts[0]['credentialSource']??null)==='lab-primary'&&($last['status']??null)==='received'
            &&($last['requestedModelId']??null)==='gpt-6-astra'&&($last['httpStatus']??0)>=200&&($last['httpStatus']??0)<300
            &&($last['providerRequestId']??null)===$analysis->provider_request_id&&is_string($analysis->provider_request_id)&&$analysis->provider_request_id!==''
            &&($index===0||(($attempts[0]['status']??null)==='quota_rejected'&&($attempts[0]['httpStatus']??null)===429&&($last['credentialSource']??null)==='dashboard-fallback'))
            &&LabAnalysisRecipes::hash($payload)===$data['providerBodySha256']&&($receipt['payloadSha256']??null)===$data['providerBodySha256'],422,'Cut gate paid execution changed.');
        $path='iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id.'/response-'.$index.'.json';
        abort_unless(($receipt['path']??null)===$path&&($receipt['providerRequestId']??null)===$analysis->provider_request_id,422);
        $raw=json_decode(file_get_contents($files->file($analysis->project_id,$path,$receipt['sha256']??'',512*1024)),true,flags:JSON_THROW_ON_ERROR);
        abort_unless(($raw['model']??null)==='gpt-6-astra'&&is_string($raw['id']??null)&&$raw['id']!==''&&$raw['id']===($receipt['responseId']??null),422);
        $parsed=LabStagingCutProtocol::parse($raw,$context);$gate=$parsed['stagingReadiness'];
        abort_unless(LabAnalysisRecipes::hash($parsed['proposal'])===$analysis->proposal_sha256&&LabAnalysisRecipes::hash($gate)===LabAnalysisRecipes::hash($data['stagingReadiness']??[]),422,'Actual cut gate changed.');
        if($requirePass)abort_unless($gate['allRequiredChecksPass']===true&&($analysis->proposal['workflow']['mode']??null)==='whole_panorama'
            &&app(LabStagingWorkflow::class)->threeTargets($analysis->proposal),422,'Actual cut scope/clearance/optical checks require review.');
        return $gate;
    }
    public function verifyImageStep(IguideLabWorkflowStep $step): void
    {
        $analysis=IguideLabAnalysis::where('project_id',$step->project_id)->findOrFail($step->input['options']['analysisId']??'');
        $this->verified($analysis);
        abort_unless($analysis->case_id===($step->input['caseId']??null)&&$analysis->data['workflowInvocation']['itemId']===$step->item_id
            &&($step->input['prompt']??null)===$analysis->proposal['generationPrompt'],422,'Automatic image is not the exact checked draft.');
    }
}
