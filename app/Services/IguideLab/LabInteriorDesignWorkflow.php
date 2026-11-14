<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabComposite,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowEvaluation,IguideLabWorkflowItem,IguideLabWorkflowStep};

/** Bounded existing opaque furnishings. No layout/shape repair or colour-only success. */
class LabInteriorDesignWorkflow
{
    public function snapshot(): array
    {
        $files=[];foreach([self::class,LabInteriorDesignArtifacts::class,LabInteriorDesignImageDraft::class,LabMachineImageDrafts::class,
            LabInteriorDesignMasks::class,LabInteriorDesignMaskPlan::class,LabMachineSegmentationCapabilities::class,LabService::class,LabProvider::class,LabWorkflowEvaluations::class] as $class)
            $files[class_basename($class)]=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        return ['version'=>LabInteriorDesignPolicy::VERSION,'implementation'=>$files,'reviewRequirementsSha256'=>LabAnalysisRecipes::hash(LabInteriorDesignArtifacts::REVIEW_CONSTRAINTS)];
    }
    public function ready(): bool
    {
        return app(LabInteriorDesignAssessments::class)->ready()&&app(LabInteriorDesignMaterials::class)->ready()
            &&app(LabSectionGeometry::class)->ready()&&app(LabWorkflowArtifacts::class)->ready()&&app(LabWorkflowEvaluations::class)->ready()
            &&app(LabMachineSegmentationTransport::class)->ready();
    }
    public function validateSnapshot(array $snapshot): void
    {abort_unless(LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),422,'The reserved material workflow changed.');}
    public function advance(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project): void
    {
        $owner=app(LabWorkflows::class);$steps=app(LabWorkflowSteps::class);$assessments=app(LabInteriorDesignAssessments::class);$data=$item->data;
        abort_unless(config('iguide-lab.interior_design_workflow_enabled',false)===true&&LabInteriorDesignPolicy::enabled($workflow->policy,$item->preset)&&$this->ready(),503);
        $this->validateSnapshot($workflow->policy['interiorWorkflow']);$assessments->validate($workflow->policy['interiorAssessments']);
        app(LabInteriorDesignMaterials::class)->validate($workflow->policy['interiorMaterials']);
        abort_unless($workflow->policy['modelId']==='gpt-image-2.6-sunburst'&&$workflow->policy['quality']==='high'&&$workflow->policy['complexity']==='low'
            &&$workflow->policy['evaluationRecipeSha256']===LabVisualQualityProtocol::recipeSha256()
            &&$workflow->policy['evaluationParserSha256']===hash_file('sha256',(new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()),422);
        app(LabWorkflowArtifacts::class)->preflight($item,$workflow->policy['artifacts']);$native=app(LabWorkflowSources::class)->verify($project->id,$item->source);
        $framingStep=$assessments->reserve($item,'framing');$framing=$assessments->start($framingStep);$data['interiorFramingId']=$framing->id;
        if(!$this->completed($item,$framing,'analyzing',$data))return;
        $framing=$assessments->completed($item,$framing->id,'framing');
        if($framing->result['status']!=='framing_candidate'){$this->hold($item,'material_source_route_unsupported','This room does not justify the requested style within complete unchanged-form opaque materials.',$data);return;}
        $sectionInput=['caseId'=>$native->id,'view'=>$framing->result['view'],'framingId'=>$framing->id];
        $sectionStep=$steps->reserve($item,'interior-section','section',$sectionInput);
        $section=IguideLabSection::where('project_id',$project->id)->where('request_id',$sectionStep->request_id)->first();
        if(!$section)$section=app(LabSections::class)->start($project,['caseId'=>$native->id,'view'=>$framing->result['view'],'requestId'=>$sectionStep->request_id]);
        else $this->reconcileSection($sectionStep,$section);
        $steps->attach($sectionStep,$section->id);$data['sectionId']=$section->id;
        if(!$this->completed($item,$section,'preparing',$data))return;
        $cutStep=$assessments->reserve($item,'cut',['framingId'=>$framing->id,'sectionId'=>$section->id]);$cut=$assessments->start($cutStep);$data['interiorCutAssessmentId']=$cut->id;
        if(!$this->completed($item,$cut,'analyzing',$data))return;$cut=$assessments->completed($item,$cut->id,'cut');
        if($cut->result['status']!=='ready_for_source_masks'){$this->hold($item,'material_cut_scope_unsupported','Actual cut and parent context do not support complete materials, protected foreground or requested style.',$data);return;}
        if(!$this->segment($item,$cut,$data))return;
        $support=app(LabInteriorDesignMaterials::class)->sourceSupport($item,$cut,$workflow->policy['interiorMaterials']);
        $data['materialSourceSupport']=['path'=>$support['reportPath'],'sha256'=>$support['reportSha256'],'status'=>$support['report']['status']];
        if($support['report']['status']!=='eligible_for_donor_request'){$data['materialSupportReasons']=$support['report']['reasons'];$this->hold($item,'material_source_support_unsupported','Original mask topology, foreground or measurable structure is insufficient for safe material guidance.',$data);return;}
        $input=['caseId'=>$section->derived_case_id,'modelId'=>$workflow->policy['modelId'],'preset'=>'interior-design','style'=>$item->style,
            'interiorPlanId'=>$cut->id,'supportSha256'=>$support['reportSha256'],'prompt'=>$cut->result['generationPrompt'],
            'options'=>['sectionId'=>$section->id,'complexity'=>'low','promptMode'=>'machine-interior-material-plan']];
        $imageStep=$steps->reserve($item,'interior-image','image',$input);$run=IguideLabRun::where('project_id',$project->id)->where('request_id',$imageStep->request_id)->first();
        if(!$run)$run=app(LabService::class)->start($project,$input+['requestId'=>$imageStep->request_id],$steps->invocation($imageStep),null,$cut);else $owner->reconcileQueuedChild($imageStep,$run);
        $steps->attach($imageStep,$run->id);$data['runId']=$run->id;$item->attempt_count=1;
        if(!$this->completed($item,$run,'editing',$data))return;
        $candidateStep=$assessments->reserve($item,'candidate',['cutId'=>$cut->id,'runId'=>$run->id]);$candidate=$assessments->start($candidateStep);$data['interiorCandidateAssessmentId']=$candidate->id;
        if(!$this->completed($item,$candidate,'checking',$data))return;$candidate=$assessments->completed($item,$candidate->id,'candidate');
        if($candidate->result['status']!=='ready_for_candidate_masks'){$this->hold($item,'material_donor_unsupported','Actual donor geometry, material detail, requested style or optical consistency requires review.',$data);return;}
        if(!$this->segment($item,$candidate,$data))return;
        $artifacts=app(LabInteriorDesignArtifacts::class);$composition=$steps->reserve($item,'interior-composition','composition',[
            'runId'=>$run->id,'outputSha256'=>$artifacts->runHash($run),'cutAssessmentSha256'=>$cut->result_sha256,'candidateAssessmentSha256'=>$candidate->result_sha256,
            'recipe'=>$workflow->policy['artifacts'],'materials'=>$workflow->policy['interiorMaterials'],'interiorRecipe'=>$workflow->policy['interiorWorkflow']]);
        if(!$composition->child_id){$composite=$artifacts->composeMaterials($item,$composition,$run,$candidate);
            if(!$composite){$this->hold($item,'material_registration_or_transfer_unsupported','Measured registration, non-colour donor detail or bounded native transfer did not pass.',$data);return;}
            $steps->attach($composition,$composite->id);$data['compositeId']=$composite->id;$owner->save($item,'preparing',$data);return;}
        $composite=IguideLabComposite::where('project_id',$project->id)->findOrFail($composition->child_id);$data['compositeId']=$composite->id;
        $evidence=$artifacts->materialEvidence($item,$composite,$composition,$run);
        if(!$evidence['context']['mechanicalQa']['passed']){$this->hold($item,'material_native_checks_failed','The native material candidate failed mechanical checks.',$data);return;}
        $evaluationStep=$steps->reserve($item,'interior-evaluation','evaluation',$evidence);
        $evaluation=IguideLabWorkflowEvaluation::where('project_id',$project->id)->where('request_id',$evaluationStep->request_id)->first();
        if(!$evaluation)$evaluation=app(LabWorkflowEvaluations::class)->start($evaluationStep);else $owner->reconcileQueuedChild($evaluationStep,$evaluation);
        $steps->attach($evaluationStep,$evaluation->id);$data['evaluationId']=$evaluation->id;
        if(!$this->completed($item,$evaluation,'checking',$data))return;
        $verified=app(LabWorkflowEvaluations::class)->verifiedCompleted($item,$evaluation);
        $passed=($verified['qualityStatus']??null)==='passed_automated_checks';
        // No human acceptance or second image can be inferred from a checker outcome.
        $owner->finish($item,$passed?'ready':'needs_review',$passed?null:'material_style_or_quality_requires_review',
            $passed?null:'Independent native checks did not verify recognizable requested style and material coherence.',$data);
    }
    private function segment(IguideLabWorkflowItem $item,$assessment,array &$data): bool
    {
        foreach(app(LabInteriorDesignMasks::class)->reserve($item,$assessment) as $step){
            $record=app(LabMachineSegmentations::class)->start($step);$data['materialSegmentations'][$step->step_key]=$record->id;
            if(!$this->completed($item,$record,'checking',$data))return false;
            app(LabInteriorDesignMasks::class)->verifiedReceipt($step);
        }return true;
    }
    private function reconcileSection(IguideLabWorkflowStep $step,IguideLabSection $section): void
    {
        if($section->status!=='queued'||$section->started_at!==null||!$section->created_at->lt(now()->subMinute()))return;
        $claim=app(LabWorkflowInvocation::class)->claim(app(LabWorkflowSteps::class)->invocation($step),$step->project_id,$step->request_id,'section',function()use($section,$step){
            $fresh=$section->fresh();$s=$step->fresh();$d=$s->data??[];
            if($fresh->status!=='queued'||$fresh->started_at!==null||(int)($d['lastQueueReconciledAt']??0)>now()->timestamp-60)return false;
            $d['lastQueueReconciledAt']=now()->timestamp;$s->update(['data'=>$d]);return true;
        });if($claim)\App\Jobs\ProcessIguideLabSection::dispatch($section->id);
    }
    private function completed(IguideLabWorkflowItem $item,$record,string $stage,array $data): bool
    {
        if($record->status==='completed')return true;
        if(in_array($record->status,['failed','needs_attention','needs_review'],true))$this->hold($item,'material_child_requires_review','A material workflow child could not confirm its result. No ambiguous request will be repeated.',$data);
        else app(LabWorkflows::class)->save($item,$stage,$data);return false;
    }
    private function hold(IguideLabWorkflowItem $item,string $code,string $message,array $data): void
    {app(LabWorkflows::class)->finish($item,'needs_review',$code,$message,$data);}
}
