<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabInteriorAssessment,IguideLabRun,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Typed original-cut material scope, not a fabricated successful scene analysis. */
class LabInteriorDesignImageDraft
{
    public const VERSION='machine-interior-material-plan/1';
    public function apply(IguideLabInteriorAssessment $record,IguideLabCase $case,array $input,?array $invocation): array
    {
        abort_unless(config('iguide-lab.interior_design_workflow_enabled',false)===true&&$invocation!==null,503);
        $item=IguideLabWorkflowItem::where('project_id',$case->project_id)->findOrFail($invocation['itemId']);
        $record=app(LabInteriorDesignAssessments::class)->completed($item,$record->id,'cut');$bundle=$record->data['contextSnapshot'];
        abort_unless($record->result['status']==='ready_for_source_masks'&&$invocation['kind']==='image'&&$input['interiorPlanId']===$record->id
            &&$input['modelId']==='gpt-image-2.6-sunburst'&&$input['preset']==='interior-design'&&$input['style']===$item->style
            &&$input['prompt']===$record->result['generationPrompt']&&$case->id===$bundle['cutCaseId'],422,'The original material draft changed.');
        $options=$input['options']??[];LabInteriorDesignProtocol::keys($options,['sectionId','complexity','promptMode']);
        abort_unless($options['sectionId']===$bundle['sectionId']&&$options['complexity']==='low'&&$options['promptMode']==='machine-interior-material-plan',422);
        $workflow=IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
        abort_unless(LabInteriorDesignPolicy::enabled($workflow->policy,$item->preset),422);
        $support=app(LabInteriorDesignMaterials::class)->sourceSupport($item,$record,$workflow->policy['interiorMaterials']??[]);
        abort_unless($support['report']['status']==='eligible_for_donor_request'&&$input['supportSha256']===$support['reportSha256'],422,'Source material support is not eligible.');
        $binding=$bundle['sourceContextSnapshot']['binding'];$source=$bundle['assets'][0];
        abort_unless($case->projection==='rectilinear'&&[$case->width,$case->height]===[2048,2048]
            &&$case->source_sha256===$bundle['context']['sourceSha256']&&($case->metadata['sectionId']??null)===$bundle['sectionId'],422);
        $bytes=Storage::disk(LabService::DISK)->get($case->source_path);
        abort_unless(hash('sha256',$bytes)===$case->source_sha256&&hash('sha256',LabImages::prepared($bytes))===$source['sha256'],422,'Prepared original material input changed.');
        return ['version'=>self::VERSION,'id'=>$record->id,'stepId'=>$record->step_id,'requestId'=>$record->request_id,'resultSha256'=>$record->result_sha256,
            'sourceSha256'=>$case->source_sha256,'preparedSourceSha256'=>$source['sha256'],'nativeSourceSha256'=>$item->source['sha256'],
            'sourceGeometrySha256'=>$bundle['geometry']['sha256'],'promptSha256'=>hash('sha256',$input['prompt']),'sourceContext'=>$binding,
            'sourceContextBindingSha256'=>$bundle['sourceContextSnapshot']['bindingSha256'],'supportReportSha256'=>$support['reportSha256'],
            'targetIds'=>array_column($record->result['targets'],'id'),'automaticGeneration'=>true,'operatorInterventions'=>0,'humanReviewed'=>false];
    }
    public function verifyRun(IguideLabRun $run,IguideLabCase $case): void
    {
        $saved=$run->options['sourceMachinePlan']??null;abort_unless(is_array($saved)&&($saved['version']??null)===self::VERSION,422);
        $record=IguideLabInteriorAssessment::where('project_id',$run->project_id)->findOrFail($saved['id']);
        $step=IguideLabWorkflowStep::where('project_id',$run->project_id)->where('request_id',$run->request_id)->where('kind','image')->sole();
        abort_unless(in_array($step->child_id,[null,$run->id],true)&&$step->input_sha256===LabAnalysisRecipes::hash($step->input)
            &&$run->prompt===$step->input['prompt']&&($run->options['promptMode']??null)==='machine-interior-material-plan'
            &&$run->case_id===$case->id&&$run->model_id==='gpt-image-2.6-sunburst'&&$run->preset==='interior-design'&&$run->style===$step->input['style']
            &&($run->options['quality']??null)==='high'&&($run->options['complexity']??null)==='low'
            &&[$run->options['width']??null,$run->options['height']??null]===[2048,2048]
            &&empty($run->options['sourceAnalysis'])&&empty($run->options['analysisId'])&&empty($run->options['sectionMaskId'])
            &&empty($run->options['referenceCaseIds'])&&empty($run->options['referenceRunIds'])&&empty($run->options['referenceCompositeIds'])
            &&($run->options['sectionId']??null)===$step->input['options']['sectionId'],422,'The reserved material image changed.');
        $current=$this->apply($record,$case,$step->input,$run->options['workflowInvocation']??null);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($saved)===LabMachineSegmentationPlan::canonicalHash($current),422,'The typed material source authority changed.');
    }
}
