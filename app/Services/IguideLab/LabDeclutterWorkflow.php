<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabComposite,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowEvaluation,IguideLabWorkflowItem};

/** One source-selected opaque ornament on a simple matte receiver; no automatic repair. */
class LabDeclutterWorkflow
{
    public const INTENT = 'Remove exactly one compact, clearly decorative, opaque tabletop object and only its attributable short local shadow. Preserve all other objects and every permanent finish. In targets use exactly target_object, action remove, objectCount 1. Select one complete perspective section with at least 10% margin around the object and its local shadow. The surrounding receiver must be simple continuous matte material. Transparent, reflective or patterned support, possible reflections, shared or long shadows, overlapping objects, thin foliage or open holes, uncertain foreground ordering, missing context, and hidden optical dependencies require manual_review. Do not infer unseen detail as ground truth. A proposed framing is not measured clearance. If no such object is clearly supported, choose manual_review.';
    public const MATERIALS_INTENT = 'Remove exactly one compact opaque portable countertop or tabletop item and only its attributable short local shadow. The selected item may be decorative or everyday clutter, including a container, utensil holder or freestanding knife block. Preserve all non-selected items, installed appliances, plumbing, sockets, cabinetry and other fixed features. In targets use exactly target_object, action remove, objectCount 1. Select one complete perspective section with at least 10% margin around the item and its local shadow. The visible receiver may be simple continuous material or stochastic stone/speckle, with unobstructed nearby material on at least two cardinal sides. No structural joint, counter edge, regular grid, major directional vein or coherent target reflection may cross the replacement. Broad unrelated illumination is not a target reflection and must continue. Transparency, foliage, holes, overlap, shared or long shadows, uncertain foreground ordering, missing optical context and possible target reflections require manual_review. Hidden receiver pixels are an unobserved generated estimate, not recoverable ground truth. This is one-item removal, not whole-room decluttering. If this bounded route is unsupported, choose manual_review.';
    public static function intent(bool $materials = false): string { return $materials?self::MATERIALS_INTENT:self::INTENT; }
    public function snapshot(bool $materials = false): array
    {
        return ['version'=>$materials?LabDeclutterRemovalPolicy::MATERIALS_POLICY:LabDeclutterRemovalPolicy::POLICY,'driverSha256'=>hash_file('sha256',__FILE__),
            'artifactAdapterSha256'=>hash_file('sha256',(new \ReflectionClass(LabDeclutterArtifacts::class))->getFileName()),
            'imageAdapterSha256'=>hash_file('sha256',(new \ReflectionClass(LabDeclutterImageDraft::class))->getFileName()),
            'authoritySha256'=>hash_file('sha256',(new \ReflectionClass(LabDeclutterSegmentationAuthority::class))->getFileName()),
            'intentSha256'=>hash('sha256',self::intent($materials)),'support'=>app(LabDeclutterSupport::class)->snapshot(),
            'removalPlan'=>app(LabDeclutterRemovalBridge::class)->snapshot()];
    }
    public function ready(): bool
    {
        return app(LabDeclutterSupport::class)->ready()&&app(LabDeclutterRemovalPlans::class)->ready()
            &&app(LabSectionGeometry::class)->ready()&&app(LabWorkflowArtifacts::class)->ready()
            &&app(LabWorkflowEvaluations::class)->ready()&&app(LabAnalyses::class)->ready()
            &&app(LabMachineSegmentationTransport::class)->ready();
    }
    public function validateSnapshot(array $snapshot): void
    {
        $materials=($snapshot['version']??null)===LabDeclutterRemovalPolicy::MATERIALS_POLICY;
        abort_unless(LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot($materials)),422,'The reserved removal implementation changed.');
    }
    public function advance(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project): void
    {
        $owner=app(LabWorkflows::class);$steps=app(LabWorkflowSteps::class);$data=$item->data;
        abort_unless(config('iguide-lab.declutter_workflow_enabled',false)===true&&LabDeclutterRemovalPolicy::enabled($workflow->policy,$item->preset),503,'Automatic removal is disabled.');
        $materials=LabDeclutterRemovalPolicy::materials($workflow->policy);
        abort_unless(!$materials||config('iguide-lab.declutter_materials_enabled',false)===true,503,'Material-aware removal is disabled.');
        abort_unless(($workflow->policy['declutterWorkflow']['version']??null)===($materials?LabDeclutterRemovalPolicy::MATERIALS_POLICY:LabDeclutterRemovalPolicy::POLICY),422,'Removal policy and recipe versions differ.');
        $this->validateSnapshot($workflow->policy['declutterWorkflow']);abort_unless($this->ready(),503);
        app(LabAnalysisRecipes::class)->validate($workflow->policy['analysisRecipe']);
        abort_unless($workflow->policy['modelId']==='gpt-image-2.6-sunburst'&&$workflow->policy['quality']==='high'&&$workflow->policy['complexity']==='low'
            &&$workflow->policy['evaluationRecipeSha256']===LabVisualQualityProtocol::recipeSha256()
            &&$workflow->policy['evaluationParserSha256']===hash_file('sha256',(new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()),422);
        if($item->instructions!==''||$item->style!==null){$owner->finish($item,'needs_review','unsupported_removal_scope','The initial automatic removal recipe does not accept custom scope or style.',$data);return;}
        app(LabWorkflowArtifacts::class)->preflight($item,$workflow->policy['artifacts']);
        $native=app(LabWorkflowSources::class)->verify($project->id,$item->source);
        $input=['caseId'=>$native->id,'preset'=>'declutter','style'=>null,'instructions'=>self::intent($materials),'options'=>LabAnalyses::EDIT_DEFAULTS];
        $sourceStep=$steps->reserve($item,'declutter-source','analysis',$input);
        $source=IguideLabAnalysis::where('project_id',$project->id)->where('request_id',$sourceStep->request_id)->first();
        if(!$source)$source=app(LabAnalyses::class)->start($project,$input+['requestId'=>$sourceStep->request_id],$steps->invocation($sourceStep));
        else $owner->reconcileQueuedChild($sourceStep,$source);
        $steps->attach($sourceStep,$source->id);$data['sourceAnalysisId']=$source->id;
        if(!$this->completed($item,$source,'analyzing',$data))return;
        if(!$this->sourceRoute($source->proposal)){$owner->finish($item,'needs_review','unsupported_removal_route','The source does not justify one isolated opaque target and a complete bounded section.',$data);return;}
        $v=$source->proposal['workflow']['sections'][0];
        $view=['yaw'=>(float)$v['yawDegrees'],'pitch'=>(float)$v['pitchDegrees'],'hFov'=>(float)$v['hfovDegrees']];
        $sectionInput=['caseId'=>$native->id,'view'=>$view];$sectionStep=$steps->reserve($item,'declutter-section','section',$sectionInput);
        $section=IguideLabSection::where('project_id',$project->id)->where('request_id',$sectionStep->request_id)->first();
        if(!$section)$section=app(LabSections::class)->start($project,$sectionInput+['requestId'=>$sectionStep->request_id]);
        elseif($section->status==='queued'&&$section->started_at===null&&$section->created_at->lt(now()->subMinute())){
            $claimed=app(LabWorkflowInvocation::class)->claim($steps->invocation($sectionStep),$project->id,$sectionStep->request_id,'section',function()use($section,$sectionStep){
                $fresh=$section->fresh();$step=$sectionStep->fresh();$d=$step->data??[];
                if($fresh->status!=='queued'||$fresh->started_at!==null||(int)($d['lastQueueReconciledAt']??0)>now()->timestamp-60)return false;
                $d['lastQueueReconciledAt']=now()->timestamp;$step->update(['data'=>$d]);return true;
            });
            if($claimed)\App\Jobs\ProcessIguideLabSection::dispatch($section->id);
        }
        $steps->attach($sectionStep,$section->id);$data['sectionId']=$section->id;
        if(!$this->completed($item,$section,'preparing',$data))return;
        abort_unless($section->case_id===$native->id&&$section->source_sha256===$native->source_sha256&&LabAnalysisRecipes::hash($section->view)===LabAnalysisRecipes::hash($view),422);
        $cut=IguideLabCase::where('project_id',$project->id)->findOrFail($section->derived_case_id);
        $plans=app(LabDeclutterRemovalPlans::class);$planStep=$plans->reserve($item,$section->id,$source->id);$plan=$plans->start($planStep);$data['removalPlanId']=$plan->id;
        if(!$this->completed($item,$plan,'analyzing',$data))return;
        $plans->verified($plan);
        if(($plan->result['status']??null)!=='ready_for_segmentation'){
            $data['removalReviewReasons']=$plan->result['reviewReasons']??[];$owner->finish($item,'needs_review','removal_plan_requires_review','Source removal or its hidden-surface dependencies are unsupported.',$data);return;}
        foreach($plans->reserveSegmentationSteps($plan) as $step){
            $record=app(LabMachineSegmentations::class)->start($step);$data['segmentations'][$step->input['logicalPlan']['stepKey']]=$record->id;
            if(!$this->completed($item,$record,'checking',$data))return;
        }
        $support=app(LabDeclutterSupport::class)->derive($item,$plan,$workflow->policy['declutterWorkflow']['support']);
        $data['support']=['path'=>$support['reportPath'],'sha256'=>$support['reportSha256'],'status'=>$support['report']['status']];
        if($support['report']['status']!=='ready_for_generation'){$data['supportReviewReasons']=$support['report']['reviewReasons'];$owner->finish($item,'needs_review','removal_support_requires_review','Source-only support is incomplete or conflicts with protected detail.',$data);return;}
        $imageInput=['caseId'=>$cut->id,'preset'=>'declutter','style'=>null,'modelId'=>$workflow->policy['modelId'],
            'removalPlanId'=>$plan->id,'supportSha256'=>$support['reportSha256'],'prompt'=>$plan->result['proposal']['generationPrompt'],
            'options'=>['complexity'=>'low','sectionId'=>$section->id]];
        $imageStep=$steps->reserve($item,'declutter-image','image',$imageInput);
        $run=IguideLabRun::where('project_id',$project->id)->where('request_id',$imageStep->request_id)->first();
        if(!$run)$run=app(LabService::class)->start($project,$imageInput+['requestId'=>$imageStep->request_id],$steps->invocation($imageStep),$plan);
        else $owner->reconcileQueuedChild($imageStep,$run);
        $steps->attach($imageStep,$run->id);$data['runId']=$run->id;$item->attempt_count=1;
        if(!$this->completed($item,$run,'editing',$data))return;
        $artifacts=app(LabDeclutterArtifacts::class);
        $composition=$steps->reserve($item,'declutter-composition','composition',['runId'=>$run->id,'outputSha256'=>$artifacts->runHash($run),
            'removalPlanSha256'=>$plan->result_sha256,'supportSha256'=>$support['reportSha256'],'recipe'=>$workflow->policy['artifacts'],
            'declutterRecipe'=>$workflow->policy['declutterWorkflow'],'harmonization'=>app(LabHarmonization::class)->snapshot($cut->source_sha256)]);
        if(!$composition->child_id){
            $composite=$artifacts->composeRemoval($item,$composition,$run,$source,$plan,$support);
            if(!$composite){$owner->finish($item,'needs_review','harmonization_requires_review','The bounded source-domain boundary solve did not pass.',$data);return;}
            $steps->attach($composition,$composite->id);$data['compositeId']=$composite->id;$owner->save($item,'preparing',$data);return;
        }
        $composite=IguideLabComposite::where('project_id',$project->id)->findOrFail($composition->child_id);
        $artifacts->verifyComposite($item,$composite,$composition,$run);$data['compositeId']=$composite->id;
        $evidence=$artifacts->evidence($item,$composite,$source);
        if(!$evidence['context']['mechanicalQa']['passed']){$owner->finish($item,'needs_review','mechanical_checks_failed','The removal composition failed numerical checks.',$data);return;}
        $evaluationStep=$steps->reserve($item,'declutter-evaluation','evaluation',$evidence);
        $evaluation=IguideLabWorkflowEvaluation::where('project_id',$project->id)->where('request_id',$evaluationStep->request_id)->first();
        if(!$evaluation)$evaluation=app(LabWorkflowEvaluations::class)->start($evaluationStep);else $owner->reconcileQueuedChild($evaluationStep,$evaluation);
        $steps->attach($evaluationStep,$evaluation->id);$data['evaluationId']=$evaluation->id;
        if(!$this->completed($item,$evaluation,'checking',$data))return;
        app(LabWorkflowEvaluations::class)->payload($evidence,$project->id,$item->id);
        $passed=($evaluation->result['qualityStatus']??null)==='passed_automated_checks';
        $owner->finish($item,$passed?'ready':'needs_review',$passed?null:'quality_checks_failed',$passed?null:'Independent panorama checks require review; no second image is authorized.',$data);
    }
    public function sourceRoute(array $p): bool
    {
        $t=$p['targets']??[];$v=$p['workflow']['sections']??[];
        return count($t)===1&&($t[0]['id']??null)==='target_object'&&($t[0]['action']??null)==='remove'&&($t[0]['objectCount']??null)===1
            &&empty($p['uncertainties'])&&($p['workflow']['mode']??null)==='perspective_sections'&&count($v)===1
            &&($v[0]['targetIds']??[])===['target_object']&&$v[0]['pitchDegrees']>=-75&&$v[0]['pitchDegrees']<=30
            &&$v[0]['hfovDegrees']>=30&&$v[0]['hfovDegrees']<=120;
    }
    private function completed(IguideLabWorkflowItem $item,$record,string $stage,array $data): bool
    {
        if($record->status==='completed')return true;$owner=app(LabWorkflows::class);
        if(in_array($record->status,['failed','needs_attention','needs_review'],true))$owner->finish($item,'needs_review','removal_child_unconfirmed','A removal child requires review; the existing receipt is retained.',$data);
        else $owner->save($item,$stage,$data);return false;
    }
}
