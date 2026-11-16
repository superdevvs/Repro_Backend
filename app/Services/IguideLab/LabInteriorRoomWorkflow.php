<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabComposite,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};

/** Closed source-selected branch. New explicit evidence policy permits at most one completed-candidate correction. */
class LabInteriorRoomWorkflow
{
    public function snapshot(): array
    {
        $hashes=[];foreach(['driver'=>self::class,'registry'=>LabInteriorRoomProfiles::class,'route'=>LabInteriorRoomRoutes::class,'materialReadScope'=>LabInteriorRoomReadScope::class,
            'artifacts'=>LabRoomFurnishingArtifacts::class,'imageDraft'=>LabRoomFurnishingImageDraft::class,'maskAuthority'=>LabRoomFurnishingMasks::class,
            'maskPlan'=>LabRoomFurnishingMaskPlan::class,'support'=>LabRoomFurnishingSupport::class,'service'=>LabService::class,
            'provider'=>LabProvider::class,'machineDrafts'=>LabMachineImageDrafts::class,'samRouter'=>LabMachineSegmentationCapabilities::class,
            'steps'=>LabWorkflowSteps::class,'invocation'=>LabWorkflowInvocation::class,'evaluation'=>LabWorkflowEvaluations::class,'evaluationProvider'=>LabWorkflowEvaluationProvider::class,
            'stagingAlias'=>LabStagingRoomAlias::class,'draftMaskPlan'=>LabRoomFurnitureTextMaskPlan::class] as $name=>$class)
            $hashes[$name.'Sha256']=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        foreach(['objectWorker'=>'room_object_worker.py','objectCompositor'=>'object_layer_compositor.py'] as $name=>$file)$hashes[$name.'Sha256']=hash_file('sha256',resource_path('iguide-lab/'.$file));
        $floor=config('iguide-lab.interior_floor_witness_enabled',false)===true?['floorContract'=>LabRoomFloorWitness::snapshot()]:[];
        if(LabRoomFloorWitnessUnion::enabled()){
            abort_unless($floor!==[]&&config('iguide-lab.interior_room_evidence_drafts_enabled',false)===true,503,'Floor union requires source witnesses and evidence drafts.');
            $floor=['floorContract'=>LabRoomFloorWitnessUnion::snapshot()];
            foreach(['floorUnion'=>LabRoomFloorWitnessUnion::class,'unionProtocol'=>LabRoomFloorUnionProtocol::class,'unionLayout'=>LabRoomFloorUnionLayoutProtocol::class,
                'unionCandidate'=>LabRoomFloorUnionCandidateProtocol::class,'unionImage'=>LabRoomFloorUnionImageDraft::class] as $name=>$class)$hashes[$name.'Sha256']=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        }
        $draft=[];if(config('iguide-lab.interior_room_evidence_drafts_enabled',false)===true){
            abort_unless($floor!==[],503,'Evidence drafts require the source-floor witness policy.');
            $draft=['evidenceDrafts'=>LabRoomEvidenceDraftPolicy::snapshot()];
            foreach(['draftPolicy'=>LabRoomEvidenceDraftPolicy::class,'draftProtocol'=>LabRoomEvidenceProtocol::class,'draftLayout'=>LabRoomEvidenceLayoutProtocol::class,
                'draftCandidate'=>LabRoomEvidenceCandidateProtocol::class,'draftImage'=>LabRoomEvidenceImageDraft::class] as $name=>$class)$hashes[$name.'Sha256']=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        }
        return $draft+$floor+['version'=>LabInteriorRoomProfiles::VERSION,'implementation'=>$hashes,'profiles'=>LabInteriorRoomProfiles::snapshot(),
            'legacyMaterialAdapter'=>'unchanged-existing-material-workflow-with-source-route-guard/1','livingSupport'=>'unchanged-staging-support/1'];
    }
    public function validateSnapshot(array $snapshot): void {abort_unless(LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),422,'Reserved Interior room driver changed.');}
    public function ready(): bool
    {
        try{return config('iguide-lab.interior_design_workflow_enabled',false)===true&&app(LabInteriorDesignWorkflow::class)->ready()
            &&app(LabInteriorRoomAssessments::class)->ready()&&app(LabRoomFurnishingSupport::class)->ready()
            &&app(LabSectionGeometry::class)->ready()&&app(LabWorkflowArtifacts::class)->ready()&&app(LabWorkflowEvaluations::class)->ready()
            &&app(LabMachineSegmentationTransport::class)->ready();}catch(\Throwable){return false;}
    }
    public function advance(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project): void
    {
        abort_unless(config('iguide-lab.interior_room_routing_enabled',false)===true&&LabInteriorRoomPolicy::enabled($workflow->policy,$item->preset),503);
        $this->validateSnapshot($workflow->policy['interiorRoomWorkflow']??[]);
        abort_unless($workflow->policy['modelId']==='gpt-image-2.6-sunburst'&&$workflow->policy['quality']==='high'&&$workflow->policy['complexity']==='low'
            &&$workflow->policy['evaluationRecipeSha256']===LabVisualQualityProtocol::recipeSha256()
            &&$workflow->policy['evaluationParserSha256']===hash_file('sha256',(new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName()),422);
        app(LabRoomFurnishingSupport::class)->validate($workflow->policy['interiorRoomSupport']??[]);
        $owner=app(LabWorkflows::class);$service=app(LabInteriorRoomAssessments::class);$steps=app(LabWorkflowSteps::class);$data=$item->data;
        app(LabWorkflowArtifacts::class)->preflight($item,$workflow->policy['artifacts']);
        $routeStep=$service->reserve($item,'route');$routeRecord=$service->start($routeStep);$data['roomRouteAssessmentId']=$routeRecord->id;
        if(!$this->completed($item,$routeRecord,'analyzing',$data))return;
        $routeRecord=$service->completed($item,$routeRecord->id,'route');
        if($routeRecord->result['route']['status']!=='route_candidate'){$data['roomRouteFindings']=$routeRecord->result['route'];$this->hold($item,'interior_source_profile_unsupported','The source room or requested scope requires an unsupported furnishing, material or optical change.',$data);return;}
        $route=app(LabInteriorRoomRoutes::class)->bind($item,$routeRecord);$item->refresh();$data['interiorRoomRoute']=$route;
        if($stop=LabStagingRoomAlias::routeStop($workflow,$item,$route['profileId'])){$this->hold($item,$stop[0],$stop[1],$data);return;}
        if($route['profileId']===LabInteriorRoomProfiles::MATERIALS){$owner->save($item,'analyzing',$data);app(LabInteriorDesignWorkflow::class)->advance($workflow,$item,$project);return;}
        $framing=$service->start($service->reserve($item,'framing',['routeId'=>$routeRecord->id]));$data['roomFramingId']=$framing->id;
        if(!$this->completed($item,$framing,'analyzing',$data))return;$framing=$service->completed($item,$framing->id,'framing');
        if($framing->result['status']!=='framing_candidate'){$this->hold($item,'interior_furnishing_frame_unsupported','The original room does not yet support a complete bounded furniture group.',$data);return;}
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);$view=$framing->result['view'];
        $sectionInput=['caseId'=>$native->id,'framingId'=>$framing->id,'routeSha256'=>LabMachineSegmentationPlan::canonicalHash($route),'view'=>$view+['width'=>2048,'height'=>2048]];
        $sectionStep=$steps->reserve($item,'room-section','section',$sectionInput);
        $section=IguideLabSection::where('project_id',$project->id)->where('request_id',$sectionStep->request_id)->first();
        if(!$section)$section=app(LabSections::class)->start($project,$sectionInput+['requestId'=>$sectionStep->request_id],$steps->invocation($sectionStep));
        else $this->reconcileSection($sectionStep,$section);
        $steps->attach($sectionStep,$section->id);$data['sectionId']=$section->id;
        if(!$this->completed($item,$section,'preparing',$data))return;
        $layout=$service->start($service->reserve($item,'layout',['framingId'=>$framing->id,'sectionId'=>$section->id]));$data['roomLayoutId']=$layout->id;
        if(!$this->completed($item,$layout,'analyzing',$data))return;$layout=$service->completed($item,$layout->id,'layout');
        if($layout->result['status']!=='ready_for_image'){$this->hold($item,'interior_actual_cut_requires_review','Actual original floor, complete group placement or optical scope is unresolved; no image was requested.',$data);return;}
        $maskAuthority=app(LabRoomFurnishingMasks::class);$floorStep=$maskAuthority->reserve($item,$layout);$floorRecord=app(LabMachineSegmentations::class)->start($floorStep);$data['roomFloorSegmentationId']=$floorRecord->id;
        if(!$this->completed($item,$floorRecord,'checking',$data))return;$floor=$maskAuthority->verifiedReceipt($floorStep);
        $draft=LabRoomEvidenceDraftPolicy::selected($workflow->policy['interiorRoomAssessments']??[])!==null;$priorCandidate=null;
        for($ordinal=1;$ordinal<=LabStagingRoomAlias::imageLimit($workflow,$item,$draft?2:1);$ordinal++){
            $imageAdapter=app(LabRoomFloorWitnessUnion::selected($layout->data['recipe'])?LabRoomFloorUnionImageDraft::class:($draft?LabRoomEvidenceImageDraft::class:LabRoomFurnishingImageDraft::class));
            $imageInput=['caseId'=>$section->derived_case_id,'preset'=>'interior-design','style'=>$item->style,'modelId'=>$workflow->policy['modelId'],
                'roomPlanId'=>$layout->id,'floorReceiptSha256'=>LabMachineSegmentationPlan::canonicalHash($floor),
                'prompt'=>$draft?$imageAdapter->prompt($layout,$priorCandidate):$imageAdapter->prompt($layout),
                'options'=>['sectionId'=>$section->id,'complexity'=>'low','promptMode'=>'machine-interior-furnishing-plan']];
            if($draft)$imageInput['roomEvidenceDraft']=$imageAdapter->evidenceBinding($item,$layout,$ordinal,$priorCandidate?->id);
            if(!IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key',$ordinal===1?'room-image':'room-image-2')->exists()&&($stop=LabStagingRoomAlias::capacityStop($workflow,$item))){$this->hold($item,$stop[0],$stop[1],$data);return;}
            $imageStep=$steps->reserve($item,$ordinal===1?'room-image':'room-image-2','image',$imageInput);
            $run=IguideLabRun::where('project_id',$project->id)->where('request_id',$imageStep->request_id)->first();
            if(!$run)$run=app(LabService::class)->start($project,$imageInput+['requestId'=>$imageStep->request_id],$steps->invocation($imageStep),null,null,$layout);
            else $owner->reconcileQueuedChild($imageStep,$run);
            $steps->attach($imageStep,$run->id);$data['runId']=$run->id;$item->attempt_count=$ordinal;
            if(!$this->completed($item,$run,'editing',$data))return;
            $parents=['layoutId'=>$layout->id,'runId'=>$run->id];
            if($draft)$parents+=['imageOrdinal'=>$ordinal,'correctionParentId'=>$priorCandidate?->id];
            if(LabStagingRoomAlias::isAlias($workflow->policy,$item)){$this->finishDraft($workflow,$item,$project,$run,$imageStep,$layout,$section,$route,$parents,$data);return;}
            $candidate=$service->start($service->reserve($item,'candidate',$parents));$data['roomCandidateAssessmentId']=$candidate->id;
            if(!$this->completed($item,$candidate,'checking',$data))return;$candidate=$service->completed($item,$candidate->id,'candidate');
            if($candidate->result['status']==='ready_for_masks')break;
            if($draft&&$ordinal===1&&($candidate->result['repairDecision']['eligible']??null)===true){$priorCandidate=$candidate;continue;}
            $this->hold($item,'interior_actual_furnishing_requires_review','The generated group has unresolved actual geometry, contacts, style, foreground or optical dependencies.',$data);return;
        }
        $objectStep=$maskAuthority->reserve($item,$candidate);$objectRecord=app(LabMachineSegmentations::class)->start($objectStep);$data['roomObjectSegmentationId']=$objectRecord->id;
        if(!$this->completed($item,$objectRecord,'checking',$data))return;$maskAuthority->verifiedReceipt($objectStep);
        $support=app(LabRoomFurnishingSupport::class)->derive($item,$candidate,$workflow->policy['interiorRoomSupport']);
        if($support['report']['status']!=='ready_for_unreviewed_composition'){$data['roomSupportReasons']=$support['report']['reviewReasons'];$this->hold($item,'interior_furnishing_support_unresolved','Actual mask boundaries, visible contacts or original floor support could not be verified.',$data);return;}
        $artifacts=app(LabRoomFurnishingArtifacts::class);
        $composition=$steps->reserve($item,'room-composition','composition',['runId'=>$run->id,'outputSha256'=>$artifacts->runHash($run),'candidateAssessmentSha256'=>$candidate->result_sha256,
            'layoutAssessmentSha256'=>$layout->result_sha256,'routeSha256'=>LabMachineSegmentationPlan::canonicalHash($route),'supportSha256'=>$support['reportSha256'],
            'recipe'=>$workflow->policy['artifacts'],'roomRecipe'=>$workflow->policy['interiorRoomWorkflow'],'supportRecipe'=>$workflow->policy['interiorRoomSupport']]);
        if(!$composition->child_id){$composite=$artifacts->composeRoom($item,$composition,$run,$candidate,$support);$steps->attach($composition,$composite->id);$data['compositeId']=$composite->id;$owner->save($item,'preparing',$data);return;}
        $composite=IguideLabComposite::where('project_id',$item->project_id)->findOrFail($composition->child_id);$artifacts->verifyComposite($item,$composite,$composition,$run);$data['compositeId']=$composite->id;
        $evaluationStep=IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key','room-evaluation')->first();$evidence=$evaluationStep?$evaluationStep->input:$artifacts->roomEvidence($item,$composite,$composition,$run);
        if(!$evidence['context']['mechanicalQa']['passed']){$this->hold($item,'interior_native_mechanics_failed','The native composition did not pass source, boundary or panorama checks.',$data);return;}
        $evaluationStep??=$steps->reserve($item,'room-evaluation','evaluation',$evidence);
        $evaluation=\App\Models\IguideLabWorkflowEvaluation::where('project_id',$project->id)->where('request_id',$evaluationStep->request_id)->first();
        if(!$evaluation)$evaluation=app(LabWorkflowEvaluations::class)->start($evaluationStep);else $owner->reconcileQueuedChild($evaluationStep,$evaluation);
        $steps->attach($evaluationStep,$evaluation->id);$data['evaluationId']=$evaluation->id;
        if(!$this->completed($item,$evaluation,'checking',$data))return;$verified=app(LabWorkflowEvaluations::class)->verifiedCompleted($item,$evaluation);$passed=$verified['qualityStatus']==='passed_automated_checks';
        $owner->finish($item,$passed?'ready':'needs_review',$passed?null:'interior_furnishing_quality_requires_review',$passed?null:'Independent native review did not verify the intended style, geometry and optical coherence.',$data);
    }
    private const SETTLED=['completed','failed','needs_attention','needs_review'];
    /**
     * Staging: the paid draft always reaches a composite and the independent final check. The draft's own
     * check never stops it (it becomes a caveat). Invalid mask fragments are dropped; with no usable furniture
     * outline at all, the whole draft is blended inside the layout's planned region and the item ends Needs review.
     */
    private function finishDraft(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project,IguideLabRun $run,IguideLabWorkflowStep $imageStep,
        \App\Models\IguideLabRoomAssessment $layout,IguideLabSection $section,array $route,array $parents,array $data): void
    {
        $owner=app(LabWorkflows::class);$steps=app(LabWorkflowSteps::class);$service=app(LabInteriorRoomAssessments::class);
        $candidate=$service->start($service->reserve($item,'candidate',$parents));$data['roomCandidateAssessmentId']=$candidate->id;
        if(!in_array($candidate->status,self::SETTLED,true)){$owner->save($item,'checking',$data);return;}
        $masks=app(LabRoomFurnishingMasks::class);$maskStep=$masks->reserveDraft($item,$run,$imageStep);
        $maskRecord=app(LabMachineSegmentations::class)->start($maskStep);$data['roomObjectSegmentationId']=$maskRecord->id;
        if(!in_array($maskRecord->status,self::SETTLED,true)){$owner->save($item,'checking',$data);return;}
        $usable=$masks->draftMasks($maskStep)??['recordId'=>$maskRecord->id,'files'=>[],'indexes'=>[]];
        $artifacts=app(LabRoomFurnishingArtifacts::class);
        $composition=$steps->reserve($item,'room-composition','composition',['runId'=>$run->id,'outputSha256'=>$artifacts->runHash($run),'candidateAssessmentId'=>$candidate->id,
            'candidateAssessmentSha256'=>$candidate->status==='completed'&&is_string($candidate->result_sha256)?$candidate->result_sha256:LabMachineSegmentationPlan::canonicalHash(['id'=>$candidate->id,'status'=>$candidate->status]),
            'layoutAssessmentId'=>$layout->id,'layoutAssessmentSha256'=>$layout->result_sha256,'sectionId'=>$section->id,'routeSha256'=>LabMachineSegmentationPlan::canonicalHash($route),
            'draftMasks'=>['stepId'=>$maskStep->id,'recordId'=>$usable['recordId'],'sha256'=>array_column($usable['files'],'sha256'),'indexes'=>$usable['indexes']],
            'recipe'=>$workflow->policy['artifacts'],'roomRecipe'=>$workflow->policy['interiorRoomWorkflow'],'supportRecipe'=>$workflow->policy['interiorRoomSupport']]);
        if(!$composition->child_id){$composite=$artifacts->composeDraft($item,$composition,$run);$steps->attach($composition,$composite->id);$data['compositeId']=$composite->id;$owner->save($item,'preparing',$data);return;}
        $composite=IguideLabComposite::where('project_id',$item->project_id)->findOrFail($composition->child_id);$artifacts->verifyComposite($item,$composite,$composition,$run);$data['compositeId']=$composite->id;
        $evaluationStep=IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key','room-evaluation')->first();$evidence=$evaluationStep?$evaluationStep->input:$artifacts->roomEvidence($item,$composite,$composition,$run);
        if(!$evidence['context']['mechanicalQa']['passed']){$this->hold($item,'interior_native_mechanics_failed','The native composition did not pass source, boundary or panorama checks.',$data);return;}
        $evaluationStep??=$steps->reserve($item,'room-evaluation','evaluation',$evidence);
        $evaluation=\App\Models\IguideLabWorkflowEvaluation::where('project_id',$project->id)->where('request_id',$evaluationStep->request_id)->first();
        if(!$evaluation)$evaluation=app(LabWorkflowEvaluations::class)->start($evaluationStep);else $owner->reconcileQueuedChild($evaluationStep,$evaluation);
        $steps->attach($evaluationStep,$evaluation->id);$data['evaluationId']=$evaluation->id;
        if(!$this->completed($item,$evaluation,'checking',$data))return;$verified=app(LabWorkflowEvaluations::class)->verifiedCompleted($item,$evaluation);$passed=$verified['qualityStatus']==='passed_automated_checks';
        if($artifacts->draftRegionUsed($item,$composite)){$this->hold($item,'staging_furniture_outline_unavailable','Furniture was added by blending the draft over the planned furniture and floor area, because no clean furniture outline was found. Review the result before use.',$data);return;}
        $owner->finish($item,$passed?'ready':'needs_review',$passed?null:'staging_furniture_requires_review',$passed?null:'Furniture was added. The final check did not confirm every detail; review the result before use.',$data);
    }
    private function completed(IguideLabWorkflowItem $item,$record,string $stage,array $data): bool
    {if($record->status==='completed')return true;if(in_array($record->status,['failed','needs_attention','needs_review'],true))$this->hold($item,'interior_room_child_requires_review','A source-bound child could not confirm its result. No ambiguous paid action will be retried.',$data);else app(LabWorkflows::class)->save($item,$stage,$data);return false;}
    private function hold(IguideLabWorkflowItem $item,string $code,string $message,array $data): void {app(LabWorkflows::class)->finish($item,'needs_review',$code,$message,$data);}
    private function reconcileSection(IguideLabWorkflowStep $step,IguideLabSection $section): void
    {
        if($section->status!=='queued'||$section->started_at!==null||!$section->created_at->lt(now()->subMinute()))return;
        $claim=app(LabWorkflowInvocation::class)->claim(app(LabWorkflowSteps::class)->invocation($step),$step->project_id,$step->request_id,'section',function()use($step,$section){
            $s=$step->fresh();$d=$s->data??[];$r=$section->fresh();if($r->status!=='queued'||$r->started_at!==null||(int)($d['lastQueueReconciledAt']??0)>now()->timestamp-60)return false;
            $d['lastQueueReconciledAt']=now()->timestamp;$s->update(['data'=>$d]);return true;});if($claim)\App\Jobs\ProcessIguideLabSection::dispatch($section->id);
    }
}
