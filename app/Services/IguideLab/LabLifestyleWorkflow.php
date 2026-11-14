<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabComposite,IguideLabMachineSegmentation,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowEvaluation,IguideLabWorkflowItem};

/** One bounded standing-pair recipe. No automatic repair or human mask claims. */
class LabLifestyleWorkflow
{
    public const INTENT = 'Add exactly two fictional adults standing separately and conversing naturally, adult_1 to the left and adult_2 to the right. Both entire bodies, hair, visible hands and both shoes must be plausible and contained with 10% image margin. No sitting, props, touching, person-person overlap or furniture interaction. Keep every original object, architecture, finish and detail unchanged. Use only a visibly level source floor region at plausible adult scale. In targets use exactly adult_1 and adult_2, each action add and objectCount 1. Relevant reflections, foreground overlap, uncertain feet or anatomy and broad or directional cast shadows require manual_review; this recipe supports diffuse ambient light and tiny shoe contacts only. Do not invent depth, calibration or hidden supports.';

    public function snapshot(): array
    {
        return ['version'=>'automatic-standing-pair-lifestyle/1','driverSha256'=>hash_file('sha256',__FILE__),
            'artifactAdapterSha256'=>hash_file('sha256',(new \ReflectionClass(LabLifestyleArtifacts::class))->getFileName()),
            'authoritySha256'=>hash_file('sha256',(new \ReflectionClass(LabLifestyleSegmentationAuthority::class))->getFileName()),
            'intentSha256'=>hash('sha256',self::INTENT),'support'=>app(LabLifestyleSupport::class)->snapshot(),
            'assessment'=>app(LabLifestyleAssessmentBridge::class)->snapshot()];
    }
    public function ready(): bool
    {
        return config('iguide-lab.lifestyle_framing_enabled',false)===true
            &&app(LabLifestyleFramings::class)->ready()
            &&app(LabLifestyleSupport::class)->ready()&&app(LabLifestyleAssessments::class)->ready()
            &&app(LabSectionGeometry::class)->ready()&&app(LabWorkflowArtifacts::class)->ready()
            &&app(LabWorkflowEvaluations::class)->ready()&&app(LabAnalyses::class)->ready()
            &&app(LabMachineSegmentationTransport::class)->ready();
    }
    public function validateSnapshot(array $snapshot): void
    {
        abort_unless(LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),422,'The reserved lifestyle implementation changed.');
    }
    public function advance(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project): void
    {
        $owner=app(LabWorkflows::class);$steps=app(LabWorkflowSteps::class);$data=$item->data;
        abort_unless(config('iguide-lab.lifestyle_workflow_enabled',false)===true&&LabLifestyleAssessmentPolicy::enabled($workflow->policy,$item->preset),503,'Automatic lifestyle is disabled.');
        $this->validateSnapshot($workflow->policy['lifestyleWorkflow']);
        abort_unless($this->ready(),503,'Automatic lifestyle runtime is unavailable.');
        app(LabAnalysisRecipes::class)->validate($workflow->policy['analysisRecipe']);
        app(LabAnalysisRecipes::class)->validate($workflow->policy['sectionAnalysisRecipe']);
        abort_unless($workflow->policy['modelId']==='gpt-image-2.6-sunburst'&&$workflow->policy['quality']==='high'&&$workflow->policy['complexity']==='low'
            &&$workflow->policy['evaluationRecipeSha256']===LabVisualQualityProtocol::recipeSha256()
            &&$workflow->policy['evaluationParserSha256']===hash_file('sha256',(new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()),422);
        app(LabWorkflowArtifacts::class)->preflight($item,$workflow->policy['artifacts']);
        $native=app(LabWorkflowSources::class)->verify($project->id,$item->source);
        $instructions=self::INTENT.($item->instructions!==''?"\nUser intent (must remain within the two-person recipe): ".$item->instructions:'');
        abort_if(mb_strlen($instructions)>3200,422);
        $options=array_replace(LabAnalyses::EDIT_DEFAULTS,['roomType'=>'auto','furnitureDensity'=>'light']);
        abort_unless(isset($workflow->policy['lifestyleFraming']),422,'The standing-pair recipe requires original-source framing.');
        $framingService=app(LabLifestyleFramings::class);
        $framing=$framingService->start($framingService->reserve($item));$data['sourceFramingId']=$framing->id;
        if(!$this->completed($item,$framing,'analyzing',$data))return;
        $framing=$framingService->completed($item,$framing->id);
        if($framing->result['status']!=='framing_candidate') {
            $owner->finish($item,'needs_review','framing_requires_review','A safe standing-pair source framing could not be proposed. No image was requested.',$data);return;
        }
        $view=$framing->result['view'];
        $sectionInput=['caseId'=>$native->id,'view'=>$view];$sectionStep=$steps->reserve($item,'lifestyle-section','section',$sectionInput);
        $section=IguideLabSection::where('project_id',$project->id)->where('request_id',$sectionStep->request_id)->first();
        if(!$section)$section=app(LabSections::class)->start($project,$sectionInput+['requestId'=>$sectionStep->request_id]);
        elseif($section->status==='queued'&&$section->started_at===null&&$section->created_at->lt(now()->subMinute())) {
            // Free local child, only a never-started extraction is eligible for dispatch reconciliation.
            $claimed=app(LabWorkflowInvocation::class)->claim($steps->invocation($sectionStep),$project->id,$sectionStep->request_id,'section',function()use($section,$sectionStep){
                $fresh=$section->fresh();$step=$sectionStep->fresh();$data=$step->data??[];
                if($fresh->status!=='queued'||$fresh->started_at!==null||(int)($data['lastQueueReconciledAt']??0)>now()->timestamp-60)return false;
                $data['lastQueueReconciledAt']=now()->timestamp;$step->update(['data'=>$data]);return true;
            });
            if($claimed)\App\Jobs\ProcessIguideLabSection::dispatch($section->id);
        }
        $steps->attach($sectionStep,$section->id);$data['sectionId']=$section->id;
        if(!$this->completed($item,$section,'preparing',$data))return;
        abort_unless($section->case_id===$native->id&&$section->source_sha256===$native->source_sha256&&LabAnalysisRecipes::hash($section->view)===LabAnalysisRecipes::hash($view),422);
        $cut=IguideLabCase::where('project_id',$project->id)->findOrFail($section->derived_case_id);
        $cutInput=['caseId'=>$cut->id,'preset'=>'lifestyle','style'=>$item->style,'instructions'=>$instructions,'options'=>$options];
        if(isset($workflow->policy['lifestyleFraming']))$cutInput['lifestyleFraming']=app(LabLifestyleCutReadiness::class)->reference($item,$framing->id,$cut);
        $analysis=$this->analysis($item,$project,'lifestyle-section-plan',$cutInput);$data['analysisId']=$analysis->id;
        if(!$this->completed($item,$analysis,'analyzing',$data))return;
        if(isset($workflow->policy['lifestyleFraming'])) {
            $gate=app(LabLifestyleCutReadiness::class)->verified($analysis,false);
            if(!$gate['allRequiredChecksPass']) {
                $owner->finish($item,'needs_review','actual_cut_requires_review','Actual view scope, margin, clearance or optical checks require review. No image was requested.',$data);return;
            }
        }
        if(!$this->twoTargets($analysis->proposal)||($analysis->proposal['workflow']['mode']??null)!=='whole_panorama'){
            $owner->finish($item,'needs_review','unsupported_section_plan','The actual section does not support the unchanged two-person scope.',$data);return;}
        $context=$analysis->data['sourceContext']??null;
        abort_unless(is_array($context),422,'The actual section has no verified native parent context.');
        app(LabAnalysisSourceContext::class)->verify($cut,$context);
        abort_unless(($context['binding']['nativeCaseId']??null)===$native->id&&($context['binding']['sectionId']??null)===$section->id
            &&($context['binding']['nativeSourceSha256']??null)===$native->source_sha256,422,'Section analysis parent authority changed.');
        $imageInput=['caseId'=>$cut->id,'preset'=>'lifestyle','style'=>$item->style,'modelId'=>$workflow->policy['modelId'],'prompt'=>$analysis->proposal['generationPrompt'],
            'options'=>array_replace($analysis->settings['options'],['analysisId'=>$analysis->id,'promptMode'=>'scene-plan','complexity'=>'low','sectionId'=>$section->id])];
        $imageStep=$steps->reserve($item,'lifestyle-image','image',$imageInput);
        $run=IguideLabRun::where('project_id',$project->id)->where('request_id',$imageStep->request_id)->first();
        if(!$run)$run=app(LabService::class)->start($project,$imageInput+['requestId'=>$imageStep->request_id],$steps->invocation($imageStep));
        else $owner->reconcileQueuedChild($imageStep,$run);
        $steps->attach($imageStep,$run->id);$data['runId']=$run->id;$item->attempt_count=1;
        if(!$this->completed($item,$run,'editing',$data))return;
        $assessments=app(LabLifestyleAssessments::class);$assessmentStep=$assessments->reserve($item,$section->id,$run->id);
        $assessment=$assessments->start($assessmentStep);$data['assessmentId']=$assessment->id;
        if(!$this->completed($item,$assessment,'checking',$data))return;
        if(($assessment->result['status']??null)!=='ready_for_segmentation'){
            $data['assessmentReviewReasons']=$assessment->result['reviewReasons']??[];
            $owner->finish($item,'needs_review','placement_requires_review','Actual placement or its optical/context dependencies require review before segmentation.',$data);return;}
        $segSteps=$assessments->reserveSegmentationSteps($assessment);
        foreach(['source-floor','candidate-people'] as $role) {
            $step=collect($segSteps)->first(fn($s)=>($s->input['logicalPlan']['stepKey']??null)===$role);abort_unless($step,422);
            $record=app(LabMachineSegmentations::class)->start($step);$data['segmentations'][$role]=$record->id;
            if(!$this->completed($item,$record,'checking',$data))return;
        }
        $support=app(LabLifestyleSupport::class)->derive($item,$assessment,$workflow->policy['lifestyleWorkflow']['support']);
        $data['support']=['path'=>$support['reportPath'],'sha256'=>$support['reportSha256'],'status'=>$support['report']['status']];
        if($support['report']['status']!=='ready_for_unreviewed_composition'){
            $data['supportReviewReasons']=$support['report']['reviewReasons'];
            $owner->finish($item,'needs_review','support_requires_review','Automatic object, floor or contact support did not pass.',$data);return;}
        $artifacts=app(LabLifestyleArtifacts::class);
        $composition=$steps->reserve($item,'lifestyle-composition','composition',['runId'=>$run->id,'outputSha256'=>$artifacts->runHash($run),
            'assessmentSha256'=>$assessment->result_sha256,'supportSha256'=>$support['reportSha256'],'recipe'=>$workflow->policy['artifacts'],'lifestyleRecipe'=>$workflow->policy['lifestyleWorkflow']]);
        if(!$composition->child_id){
            $composite=$artifacts->composeLifestyle($item,$composition,$run,$analysis,$assessment,$support);$steps->attach($composition,$composite->id);
            $data['compositeId']=$composite->id;$owner->save($item,'preparing',$data);return;
        }
        $composite=IguideLabComposite::where('project_id',$project->id)->findOrFail($composition->child_id);
        $artifacts->verifyComposite($item,$composite,$composition,$run);$data['compositeId']=$composite->id;
        $evidence=$artifacts->evidence($item,$composite,$analysis);
        if(!$evidence['context']['mechanicalQa']['passed']){$owner->finish($item,'needs_review','mechanical_checks_failed','The native composition failed its numerical checks.',$data);return;}
        $evaluationStep=$steps->reserve($item,'lifestyle-evaluation','evaluation',$evidence);
        $evaluation=IguideLabWorkflowEvaluation::where('project_id',$project->id)->where('request_id',$evaluationStep->request_id)->first();
        if(!$evaluation)$evaluation=app(LabWorkflowEvaluations::class)->start($evaluationStep);else $owner->reconcileQueuedChild($evaluationStep,$evaluation);
        $steps->attach($evaluationStep,$evaluation->id);$data['evaluationId']=$evaluation->id;
        if(!$this->completed($item,$evaluation,'checking',$data))return;
        app(LabWorkflowEvaluations::class)->payload($evidence,$project->id,$item->id);
        if(($evaluation->result['qualityStatus']??null)==='passed_automated_checks')$owner->finish($item,'ready',null,null,$data);
        else $owner->finish($item,'needs_review','quality_checks_failed','Independent full-sphere checks require review; no second image is authorized.',$data);
    }
    private function analysis(IguideLabWorkflowItem $item,IguideLabProject $project,string $key,array $input): IguideLabAnalysis
    {
        $steps=app(LabWorkflowSteps::class);$step=$steps->reserve($item,$key,'analysis',$input);
        $record=IguideLabAnalysis::where('project_id',$project->id)->where('request_id',$step->request_id)->first();
        if(!$record)$record=app(LabAnalyses::class)->start($project,$input+['requestId'=>$step->request_id],$steps->invocation($step));
        else app(LabWorkflows::class)->reconcileQueuedChild($step,$record);
        $steps->attach($step,$record->id);return $record;
    }
    private function completed(IguideLabWorkflowItem $item,$record,string $stage,array $data): bool
    {
        if($record->status==='completed')return true;
        $owner=app(LabWorkflows::class);
        if(in_array($record->status,['failed','needs_attention','needs_review'],true))$owner->finish($item,'needs_review','lifestyle_child_unconfirmed','A lifestyle child requires review. Its existing receipt was retained.',$data);
        else $owner->save($item,$stage,$data);
        return false;
    }
    public function twoTargets(array $proposal): bool
    {
        $targets=$proposal['targets']??[];$ids=array_column($targets,'id');sort($ids);
        return $ids===['adult_1','adult_2']&&count(array_filter($targets,fn($t)=>($t['action']??null)==='add'&&($t['objectCount']??null)===1))===2;
    }
}
