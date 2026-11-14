<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabDeclutterRemovalPlan,IguideLabRun,IguideLabWorkflow,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Exact internal draft transport. A removal plan is not a successful scene analysis. */
class LabDeclutterImageDraft
{
    public function apply(IguideLabDeclutterRemovalPlan $record,IguideLabCase $case,array $input,?array $invocation): array
    {
        abort_unless(config('iguide-lab.declutter_workflow_enabled',false)===true,503,'Automatic removal is disabled.');
        [$planStep,$item]=app(LabDeclutterRemovalPlans::class)->verified($record);
        abort_unless($invocation!==null&&$invocation['itemId']===$item->id&&$invocation['kind']==='image'
            &&$record->project_id===$case->project_id&&($input['removalPlanId']??null)===$record->id
            &&$input['modelId']==='gpt-image-2.6-sunburst'&&$input['preset']==='declutter'&&empty($input['style'])
            &&$input['prompt']===$record->result['proposal']['generationPrompt'],422,'The internal removal draft changed.');
        $options=$input['options']??[];
        abort_unless(($options['sectionId']??null)===$planStep->input['sectionId']&&($options['complexity']??null)==='low'
            &&empty($options['analysisId'])&&empty($options['sectionMaskId'])&&empty($options['referenceCaseIds'])
            &&empty($options['referenceRunIds'])&&empty($options['referenceCompositeIds'])&&empty($options['allowFinishChanges']),422,'Unsupported removal transport options.');
        $context=$record->data['contextSnapshot'];$binding=$context['context']['binding'];
        $workflow=IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
        $support=app(LabDeclutterSupport::class)->derive($item,$record,$workflow->policy['declutterWorkflow']['support']??[]);
        abort_unless($support['report']['status']==='ready_for_generation'&&($input['supportSha256']??null)===$support['reportSha256'],422,'Source-only removal support is not ready.');
        abort_unless($case->projection==='rectilinear'&&[$case->width,$case->height]===[2048,2048]
            &&$case->source_sha256===$binding['sourceCutSha256']
            &&($case->metadata['sectionId']??null)===$planStep->input['sectionId'],422,'Removal draft source is not its actual cut.');
        $disk=Storage::disk(LabService::DISK);$bytes=$disk->get($case->source_path);
        abort_unless(hash('sha256',$bytes)===$binding['sourceCutSha256']&&hash('sha256',LabImages::prepared($bytes))===$binding['submittedSourceSha256'],422,'Prepared removal source changed.');
        return ['version'=>'machine-removal-plan/1','id'=>$record->id,'stepId'=>$planStep->id,'requestId'=>$record->request_id,
            'resultSha256'=>$record->result_sha256,'sourceSha256'=>$binding['sourceCutSha256'],'preparedSourceSha256'=>$binding['submittedSourceSha256'],
            'nativeSourceSha256'=>$binding['nativeSourceSha256'],'sourceGeometrySha256'=>$binding['sourceGeometrySha256'],
            'promptSha256'=>hash('sha256',$input['prompt']),'sourceContext'=>$context['sourceContextSnapshot']['binding'],
            'supportReportSha256'=>$support['reportSha256'],'supportSha256'=>$support['report']['support']['sha256'],
            'sourceContextBindingSha256'=>$context['sourceContextSnapshot']['bindingSha256'],'automaticGeneration'=>true,
            'operatorInterventions'=>0,'humanReviewed'=>false];
    }
    public function verifyRun(IguideLabRun $run,IguideLabCase $case): void
    {
        $saved=$run->options['sourceMachinePlan']??null;abort_unless(is_array($saved)&&($saved['version']??null)==='machine-removal-plan/1',422);
        $record=IguideLabDeclutterRemovalPlan::where('project_id',$run->project_id)->findOrFail($saved['id']);
        $invocation=$run->options['workflowInvocation']??null;
        $step=IguideLabWorkflowStep::where('project_id',$run->project_id)->where('request_id',$run->request_id)->where('kind','image')->sole();
        abort_unless(in_array($step->child_id,[null,$run->id],true)&&$step->input_sha256===LabAnalysisRecipes::hash($step->input)
            &&$run->prompt===$step->input['prompt']&&($run->options['promptMode']??null)==='machine-removal-plan'
            &&$run->case_id===$case->id&&$run->model_id==='gpt-image-2.6-sunburst'&&$run->preset==='declutter'&&$run->style===null
            &&($run->options['quality']??null)==='high'&&($run->options['complexity']??null)==='low'
            &&[$run->options['width']??null,$run->options['height']??null]===[2048,2048]
            &&empty($run->options['sourceAnalysis'])&&empty($run->options['analysisId']),422,'The machine image reservation changed.');
        $current=$this->apply($record,$case,$step->input,$invocation);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($current)===LabMachineSegmentationPlan::canonicalHash($saved),422,'The reserved machine draft changed.');
    }
}
