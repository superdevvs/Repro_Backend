<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabComposite,IguideLabMachineSegmentation,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowEvaluation,IguideLabWorkflowItem};

/** One bounded three-object empty-room experiment. No automatic repair or human mask claims. */
class LabStagingWorkflow
{
    public const INTENT = 'Stage an empty living room with exactly one sofa, one coffee table and one accent chair, in the requested style. Add no rug, lamps, art, plants, cushions or accessories. Preserve every existing feature and object. In targets use exactly the IDs sofa, coffee_table and chair, each action add and objectCount 1. The complete group and all feet/contact shadows must fit one coherent perspective section with at least 10% image margin. Preserve routes, openings, vents and hearth clearance. If this cannot be justified from visible source evidence, or the room is occupied or requires hidden/uncertain optical or occlusion changes, choose manual_review. Section framing is a proposal, never a measured clearance claim.';
    public function snapshot(): array
    {
        return ['version'=>'automatic-empty-room-staging/1','driverSha256'=>hash_file('sha256',__FILE__),
            'artifactAdapterSha256'=>hash_file('sha256',(new \ReflectionClass(LabStagingArtifacts::class))->getFileName()),
            'authoritySha256'=>hash_file('sha256',(new \ReflectionClass(LabStagingSegmentationAuthority::class))->getFileName()),
            'intentSha256'=>hash('sha256',self::INTENT),'support'=>app(LabStagingSupport::class)->snapshot(),
            'assessment'=>app(LabStagingAssessmentBridge::class)->snapshot()];
    }
    public function ready(): bool
    {
        return app(LabStagingSupport::class)->ready()&&app(LabStagingAssessments::class)->ready()
            &&app(LabSectionGeometry::class)->ready()&&app(LabWorkflowArtifacts::class)->ready()
            &&app(LabWorkflowEvaluations::class)->ready()&&app(LabAnalyses::class)->ready()
            &&app(LabMachineSegmentationTransport::class)->ready();
    }
    public function validateSnapshot(array $snapshot): void
    {
        abort_unless(LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),422,'The reserved staging implementation changed.');
    }
    public function advance(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project): void
    {
        $owner=app(LabWorkflows::class);$steps=app(LabWorkflowSteps::class);$data=$item->data;
        abort_unless(config('iguide-lab.staging_workflow_enabled',false)===true&&LabStagingAssessmentPolicy::enabled($workflow->policy,$item->preset),503,'Automatic staging is disabled.');
        $this->validateSnapshot($workflow->policy['stagingWorkflow']);
        abort_unless($this->ready(),503,'Automatic staging runtime is unavailable.');
        app(LabAnalysisRecipes::class)->validate($workflow->policy['analysisRecipe']);
        app(LabAnalysisRecipes::class)->validate($workflow->policy['sectionAnalysisRecipe']);
        abort_unless($workflow->policy['modelId']==='gpt-image-2.6-sunburst'&&$workflow->policy['quality']==='high'&&$workflow->policy['complexity']==='low'
            &&$workflow->policy['evaluationRecipeSha256']===LabVisualQualityProtocol::recipeSha256()
            &&$workflow->policy['evaluationParserSha256']===hash_file('sha256',(new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()),422);
        app(LabWorkflowArtifacts::class)->preflight($item,$workflow->policy['artifacts']);
        $native=app(LabWorkflowSources::class)->verify($project->id,$item->source);
        $instructions=self::INTENT.($item->instructions!==''?"\nUser intent (must remain within the three-item recipe): ".$item->instructions:'');
        abort_if(mb_strlen($instructions)>3200,422);
        $options=array_replace(LabAnalyses::EDIT_DEFAULTS,['roomType'=>'living','furnitureDensity'=>'light']);
        $input=['caseId'=>$native->id,'preset'=>'staging','style'=>$item->style,'instructions'=>$instructions,'options'=>$options];
        $source=$this->analysis($item,$project,'staging-source',$input);$data['sourceAnalysisId']=$source->id;
        if(!$this->completed($item,$source,'analyzing',$data))return;
        $proposal=$source->proposal;
        if(!$this->threeTargets($proposal)||($proposal['workflow']['mode']??null)!=='perspective_sections'||count($proposal['workflow']['sections'])!==1){
            $owner->finish($item,'needs_review','unsupported_staging_route','This source does not support one complete three-object staging section.',$data);return;}
        $suggestion=$proposal['workflow']['sections'][0];$ids=$suggestion['targetIds'];sort($ids);
        if($ids!==['chair','coffee_table','sofa']||$suggestion['pitchDegrees'] < -60||$suggestion['pitchDegrees'] > 15){
            $owner->finish($item,'needs_review','unsupported_staging_view','The proposed section is outside the supported floor-view geometry.',$data);return;}
        $view=['yaw'=>(float)$suggestion['yawDegrees'],'pitch'=>(float)$suggestion['pitchDegrees'],'hFov'=>(float)$suggestion['hfovDegrees']];
        $sectionInput=['caseId'=>$native->id,'view'=>$view];$sectionStep=$steps->reserve($item,'staging-section','section',$sectionInput);
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
        $cutInput=['caseId'=>$cut->id,'preset'=>'staging','style'=>$item->style,'instructions'=>$instructions,'options'=>$options];
        $analysis=$this->analysis($item,$project,'staging-section-plan',$cutInput);$data['analysisId']=$analysis->id;
        if(!$this->completed($item,$analysis,'analyzing',$data))return;
        if(!$this->threeTargets($analysis->proposal)||($analysis->proposal['workflow']['mode']??null)!=='whole_panorama'){
            $owner->finish($item,'needs_review','unsupported_section_plan','The actual section does not support the unchanged three-object scope.',$data);return;}
        $context=$analysis->data['sourceContext']??null;
        abort_unless(is_array($context),422,'The actual section has no verified native parent context.');
        app(LabAnalysisSourceContext::class)->verify($cut,$context);
        abort_unless(($context['binding']['nativeCaseId']??null)===$native->id&&($context['binding']['sectionId']??null)===$section->id
            &&($context['binding']['nativeSourceSha256']??null)===$native->source_sha256,422,'Section analysis parent authority changed.');
        $imageInput=['caseId'=>$cut->id,'preset'=>'staging','style'=>$item->style,'modelId'=>$workflow->policy['modelId'],'prompt'=>$analysis->proposal['generationPrompt'],
            'options'=>array_replace($analysis->settings['options'],['analysisId'=>$analysis->id,'promptMode'=>'scene-plan','complexity'=>'low','sectionId'=>$section->id])];
        $imageStep=$steps->reserve($item,'staging-image','image',$imageInput);
        $run=IguideLabRun::where('project_id',$project->id)->where('request_id',$imageStep->request_id)->first();
        if(!$run)$run=app(LabService::class)->start($project,$imageInput+['requestId'=>$imageStep->request_id],$steps->invocation($imageStep));
        else $owner->reconcileQueuedChild($imageStep,$run);
        $steps->attach($imageStep,$run->id);$data['runId']=$run->id;$item->attempt_count=1;
        if(!$this->completed($item,$run,'editing',$data))return;
        $assessments=app(LabStagingAssessments::class);$assessmentStep=$assessments->reserve($item,$section->id,$run->id);
        $assessment=$assessments->start($assessmentStep);$data['assessmentId']=$assessment->id;
        if(!$this->completed($item,$assessment,'checking',$data))return;
        if(($assessment->result['status']??null)!=='ready_for_segmentation'){
            $data['assessmentReviewReasons']=$assessment->result['reviewReasons']??[];
            $owner->finish($item,'needs_review','placement_requires_review','Actual placement or its optical/context dependencies require review before segmentation.',$data);return;}
        $segSteps=$assessments->reserveSegmentationSteps($assessment);
        foreach(['source-floor','candidate-furniture'] as $role) {
            $step=collect($segSteps)->first(fn($s)=>($s->input['logicalPlan']['stepKey']??null)===$role);abort_unless($step,422);
            $record=app(LabMachineSegmentations::class)->start($step);$data['segmentations'][$role]=$record->id;
            if(!$this->completed($item,$record,'checking',$data))return;
        }
        $support=app(LabStagingSupport::class)->derive($item,$assessment,$workflow->policy['stagingWorkflow']['support']);
        $data['support']=['path'=>$support['reportPath'],'sha256'=>$support['reportSha256'],'status'=>$support['report']['status']];
        if($support['report']['status']!=='ready_for_unreviewed_composition'){
            $data['supportReviewReasons']=$support['report']['reviewReasons'];
            $owner->finish($item,'needs_review','support_requires_review','Automatic object, floor or contact support did not pass.',$data);return;}
        $artifacts=app(LabStagingArtifacts::class);
        $composition=$steps->reserve($item,'staging-composition','composition',['runId'=>$run->id,'outputSha256'=>$artifacts->runHash($run),
            'assessmentSha256'=>$assessment->result_sha256,'supportSha256'=>$support['reportSha256'],'recipe'=>$workflow->policy['artifacts'],'stagingRecipe'=>$workflow->policy['stagingWorkflow']]);
        if(!$composition->child_id){
            $composite=$artifacts->composeStaging($item,$composition,$run,$analysis,$assessment,$support);$steps->attach($composition,$composite->id);
            $data['compositeId']=$composite->id;$owner->save($item,'preparing',$data);return;
        }
        $composite=IguideLabComposite::where('project_id',$project->id)->findOrFail($composition->child_id);
        $artifacts->verifyComposite($item,$composite,$composition,$run);$data['compositeId']=$composite->id;
        $evidence=$artifacts->evidence($item,$composite,$analysis);
        if(!$evidence['context']['mechanicalQa']['passed']){$owner->finish($item,'needs_review','mechanical_checks_failed','The native composition failed its numerical checks.',$data);return;}
        $evaluationStep=$steps->reserve($item,'staging-evaluation','evaluation',$evidence);
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
        if(in_array($record->status,['failed','needs_attention','needs_review'],true))$owner->finish($item,'needs_review','staging_child_unconfirmed','A staging child requires review. Its existing receipt was retained.',$data);
        else $owner->save($item,$stage,$data);
        return false;
    }
    public function threeTargets(array $proposal): bool
    {
        $targets=$proposal['targets']??[];$ids=array_column($targets,'id');sort($ids);
        return $ids===['chair','coffee_table','sofa']&&count(array_filter($targets,fn($t)=>($t['action']??null)==='add'&&($t['objectCount']??null)===1))===3;
    }
}
