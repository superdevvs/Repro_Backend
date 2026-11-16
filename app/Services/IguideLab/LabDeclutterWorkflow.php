<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabComposite,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowEvaluation,IguideLabWorkflowItem};

/** One source-selected opaque ornament on a simple matte receiver; no automatic repair. */
class LabDeclutterWorkflow
{
    public const INTENT = 'Remove exactly one compact, clearly decorative, opaque tabletop object and only its attributable short local shadow. Preserve all other objects and every permanent finish. In targets use exactly target_object, action remove, objectCount 1. Select one complete perspective section with at least 10% margin around the object and its local shadow. The surrounding receiver must be simple continuous matte material. Transparent, reflective or patterned support, possible reflections, shared or long shadows, overlapping objects, thin foliage or open holes, uncertain foreground ordering, missing context, and hidden optical dependencies require manual_review. Do not infer unseen detail as ground truth. A proposed framing is not measured clearance. If no such object is clearly supported, choose manual_review.';
    public const MATERIALS_INTENT = 'Remove the small loose portable clutter visible on one countertop or tabletop: one compact item, or one tight group of touching small loose items removed together, with only their attributable short local shadows. Containers, jars, canisters, mail, remotes, cups, toiletries, utensil holders or a freestanding knife block qualify. Preserve all non-selected items, furniture, plants, glass, mirrors, installed appliances, plumbing, sockets, cabinetry and other fixed features. In targets use exactly one target with id target_object, action remove and objectCount set to the number of selected items. Select one complete perspective section with margin around the selection and its local shadow, keeping the selection away from the section border. The visible receiver may be simple continuous material or stochastic stone/speckle. Hidden receiver pixels are an unobserved generated estimate, not recoverable ground truth. Record every doubt (transparency, foliage, holes, overlap, shared or long shadows, uncertain foreground ordering, missing optical context, possible target reflections, patterned receiver) in uncertainties: each doubt becomes a caveat for the final independent check and does not by itself stop the edit. Choose manual_review only when no removable small loose item is visible. This is removal of the selected items, not whole-room decluttering.';
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
        $view=$this->route($source->proposal);
        if($view===null){$owner->finish($item,'needs_review','unsupported_removal_route','The source analysis found no removable small loose item with a bounded section.',$data);return;}
        // Source doubts are recorded caveats for the final check, not stops before an image.
        $data['sourceCaveats']=$source->proposal['uncertainties']??[];
        if(!$this->sourceRoute($source->proposal))$data['sourceCaveats'][]=mb_substr('The source analysis chose manual review; the section was derived from the overview its target cites. Model reason: '.($source->proposal['workflow']['reason']??''),0,600);
        $sectionInput=['caseId'=>$native->id,'view'=>$view];$sectionStep=$steps->reserve($item,'declutter-section','section',$sectionInput);
        $section=IguideLabSection::where('project_id',$project->id)->where('request_id',$sectionStep->request_id)->first();
        if(!$section)$section=app(LabSections::class)->start($project,$sectionInput+['requestId'=>$sectionStep->request_id],$steps->invocation($sectionStep));
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
        if(($plan->result['status']??null)!=='ready_for_segmentation'){
            $data['removalReviewReasons']=$plan->result['reviewReasons']??[];$owner->finish($item,'needs_review','removal_plan_requires_review','The removal plan found no selectable item away from the cut edge.',$data);return;}
        $plans->verified($plan);$data['removalCaveats']=$plan->result['caveats']??[];
        foreach($plans->reserveSegmentationSteps($plan) as $step){
            $record=app(LabMachineSegmentations::class)->start($step);$data['segmentations'][$step->input['logicalPlan']['stepKey']]=$record->id;
            if(!$this->completed($item,$record,'checking',$data))return;
        }
        $support=app(LabDeclutterSupport::class)->derive($item,$plan,$workflow->policy['declutterWorkflow']['support']);
        $data['support']=['path'=>$support['reportPath'],'sha256'=>$support['reportSha256'],'status'=>$support['report']['status']];$data['supportCaveats']=$support['report']['caveats']??[];
        if($support['report']['status']!=='ready_for_generation'){$data['supportReviewReasons']=$support['report']['reviewReasons'];$owner->finish($item,'needs_review','removal_support_requires_review','No usable item mask was found away from the cut edge; no image was submitted.',$data);return;}
        if($stop=app(LabPreImageCapacity::class)->stop($item,'declutter-image')){$owner->finish($item,'needs_review',$stop[0],$stop[1],$data);return;}
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
    /** Overview directions a source analysis cites (analysis_views.py ANGLES), as section yaw. */
    private const OVERVIEW_YAW=['front'=>0.0,'right'=>90.0,'back'=>180.0,'left'=>-90.0];
    /**
     * The model's own section; or, when the model held for manual_review but still names one removal target,
     * a fixed section toward the first overview its target cites. 120 degrees keeps that whole 100-degree overview
     * inside the 10% guard band; the removal plan on that cut then finds the item box or stops before any image.
     * Null when there is nothing to remove or no cited direction.
     */
    public function route(array $p): ?array
    {
        if($this->sourceRoute($p)){$v=$p['workflow']['sections'][0];return ['yaw'=>(float)$v['yawDegrees'],'pitch'=>(float)$v['pitchDegrees'],'hFov'=>(float)$v['hfovDegrees']];}
        $t=$p['targets']??[];$n=$t[0]['objectCount']??null;
        if(($p['workflow']['mode']??null)!=='manual_review'||($p['workflow']['sections']??null)!==[]||count($t)!==1||($t[0]['id']??null)!=='target_object'
            ||($t[0]['action']??null)!=='remove'||!($n===null||(is_int($n)&&$n>=1)))return null;
        foreach($t[0]['evidenceViewIds']??[] as $id)if(is_string($id)&&isset(self::OVERVIEW_YAW[$id]))return ['yaw'=>self::OVERVIEW_YAW[$id],'pitch'=>-15.0,'hFov'=>120.0];
        return null;
    }
    public function sourceRoute(array $p): bool
    {
        // Stops only when there is no removable target or no supported section; doubts are caveats.
        $t=$p['targets']??[];$v=$p['workflow']['sections']??[];$n=$t[0]['objectCount']??null;
        return count($t)===1&&($t[0]['id']??null)==='target_object'&&($t[0]['action']??null)==='remove'&&($n===null||(is_int($n)&&$n>=1))
            &&($p['workflow']['mode']??null)==='perspective_sections'&&count($v)===1
            &&($v[0]['targetIds']??[])===['target_object']&&$v[0]['pitchDegrees']>=-75&&$v[0]['pitchDegrees']<=30
            &&$v[0]['hfovDegrees']>=30&&$v[0]['hfovDegrees']<=120;
    }
    private function completed(IguideLabWorkflowItem $item,$record,string $stage,array $data): bool
    {
        if($record->status==='completed')return true;$owner=app(LabWorkflows::class);
        if(in_array($record->status,['failed','needs_attention','needs_review'],true)){
            $data['childFailure']=['table'=>$record->getTable(),'id'=>$record->id,'status'=>$record->status,'errorCode'=>$record->error_code??null];
            $owner->finish($item,'needs_review','removal_child_unconfirmed','A removal child requires review; the existing receipt is retained.',$data);
        }
        else $owner->save($item,$stage,$data);return false;
    }
}
