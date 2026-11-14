<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabProject,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowItem};

/** Source-only prospective layout plus at most one exact-input follow-up; final placement stays downstream. */
class LabStagingLayouts
{
    public const VERSION='automatic-staging-source-layout/1';
    public function implementation(): array
    {
        $files=[];
        foreach([self::class,LabStagingLayoutReadiness::class,LabStagingLayoutViews::class,LabStagingLayoutProtocol::class,
            LabStagingLayoutGeometry::class,LabSectionAnalysisProtocol::class,LabScenePlanProtocol::class,LabSectionAnalysisViews::class,
            LabAnalysisSourceContext::class,LabAnalyses::class,LabAnalysisProvider::class,LabAnalysisRecipes::class,LabStagingWorkflow::class,
            LabWorkflowPolicy::class,LabWorkflowSteps::class,LabWorkflowInvocation::class,LabStagingArtifacts::class,LabImages::class] as $class){
            $path=(new \ReflectionClass($class))->getFileName();$files[str_replace('\\','/',substr($path,strlen(base_path())+1))]=hash_file('sha256',$path);}
        foreach(['staging_layout_details.py','pano_geometry.py','analysis-recipes/staging-layout-1.json'] as $name)$files['resources/iguide-lab/'.$name]=hash_file('sha256',resource_path('iguide-lab/'.$name));
        foreach(['app/Http/Controllers/API/IguideLabController.php','routes/iguide-lab.php','config/iguide-lab.php'] as $name)$files[$name]=hash_file('sha256',base_path($name));
        ksort($files);return $files;
    }
    public function snapshot(): array
    {
        $path=(string)config('iguide-lab.staging_layout_runtime_manifest');$python=(string)config('iguide-lab.pipeline_python_path');
        abort_unless($path!==''&&is_file($path)&&!is_link($path)&&filesize($path)<=65536&&is_file($python)&&is_executable($python),503,'Validated staging layout runtime is unavailable.');
        $manifest=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$files=$this->implementation();
        abort_unless(($manifest['version']??null)===self::VERSION&&($manifest['validationStatus']??null)==='passed'
            &&($manifest['pythonPath']??null)===$python&&($manifest['pythonSha256']??null)===hash_file('sha256',$python)
            &&($manifest['pythonVersion']??null)==='3.12.3'&&($manifest['numpyVersion']??null)==='2.5.4'&&($manifest['pillowVersion']??null)==='13.0.0'
            &&LabMachineSegmentationPlan::canonicalHash($manifest['files']??[])===LabMachineSegmentationPlan::canonicalHash($files),503,'Staging layout implementation is not validated.');
        return ['version'=>self::VERSION,'files'=>$files,'runtimeManifestSha256'=>hash_file('sha256',$path),'pythonSha256'=>$manifest['pythonSha256'],
            'cutRecipe'=>app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::STAGING_LAYOUT),
            'maxAdaptiveSourceOperations'=>1,'maxNativeDetails'=>2,'maxSourceSections'=>2,'maxImages'=>1,'maxAnalyses'=>4];
    }
    public function validate(array $snapshot): void
    {abort_unless(LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),422,'Reserved source layout implementation changed.');}
    public function ready(): bool
    {try{$this->snapshot();return config('iguide-lab.staging_framing_enabled',false)===true&&app(LabStagingFramings::class)->ready();}catch(\Throwable){return false;}}

    /** Null means a durable pending/held child; never fallback to a weaker legacy source plan. */
    public function prepare(IguideLabWorkflow $workflow,IguideLabWorkflowItem $item,IguideLabProject $project,array $options): ?array
    {
        abort_unless(config('iguide-lab.staging_layout_enabled',false)===true&&isset($workflow->policy['stagingLayout'],$workflow->policy['stagingFraming']),503);
        $this->validate($workflow->policy['stagingLayout']);$owner=app(LabWorkflows::class);$data=$item->data;
        $native=app(LabWorkflowSources::class)->verify($project->id,$item->source);
        $framings=app(LabStagingFramings::class);$framing=$framings->start($framings->reserve($item));$data['sourceFramingId']=$framing->id;
        if(!$this->completed($item,$framing,'analyzing',$data))return null;
        $framing=$framings->completed($item,$framing->id);
        if($framing->result['status']!=='framing_candidate'){$owner->finish($item,'needs_review','framing_requires_review','Original source framing requires review. No image was requested.',$data);return null;}
        $section=$this->section($item,$project,$native,'staging-section',$framing->result['view']);$data['sectionId']=$section->id;
        if(!$this->completed($item,$section,'preparing',$data))return null;
        $cut=IguideLabCase::where('project_id',$project->id)->findOrFail($section->derived_case_id);
        $readiness=app(LabStagingLayoutReadiness::class);
        $initial=$this->analysis($item,$project,'staging-layout-initial',$cut,$options,$readiness->reference($item,$framing->id,$cut));
        $data['sourceLayoutAnalysisId']=$initial->id;$data['analysisId']=$initial->id;
        if(!$this->completed($item,$initial,'analyzing',$data))return null;
        $analysis=$initial;$gate=$readiness->verified($analysis,false);
        if(in_array($gate['decision'],['request_details','request_reframe'],true)){
            if($gate['decision']==='request_reframe'){
                $section=$this->section($item,$project,$native,'staging-reframed-section',$gate['reframePose']);$data['sectionId']=$section->id;
                if(!$this->completed($item,$section,'preparing',$data))return null;
                $cut=IguideLabCase::where('project_id',$project->id)->findOrFail($section->derived_case_id);
            }
            $reference=$readiness->reference($item,$framing->id,$cut,$initial->id);
            $analysis=$this->analysis($item,$project,'staging-layout-followup',$cut,$options,$reference);$data['analysisId']=$analysis->id;
            $data['sourceLayoutFollowup']=['analysisId'=>$analysis->id,'operation'=>$reference['followup']['operation'],'ordinal'=>1];
            if(!$this->completed($item,$analysis,'analyzing',$data))return null;
            $gate=$readiness->verified($analysis,false);
        }
        if(!$gate['eligibleForDraft']){
            $data['sourceLayoutResult']=$gate;
            $owner->finish($item,'needs_review','source_layout_requires_review','Prospective floor, clearance, scale or optical evidence remains unresolved. No image was requested.',$data);return null;
        }
        $readiness->verified($analysis);$data['sourceLayoutResult']=$gate;
        return ['section'=>$section,'cut'=>$cut,'analysis'=>$analysis,'data'=>$data];
    }
    private function section(IguideLabWorkflowItem $item,IguideLabProject $project,IguideLabCase $native,string $key,array $view): IguideLabSection
    {
        $steps=app(LabWorkflowSteps::class);$input=['caseId'=>$native->id,'view'=>$view];$step=$steps->reserve($item,$key,'section',$input);
        $section=IguideLabSection::where('project_id',$project->id)->where('request_id',$step->request_id)->first();
        if(!$section)$section=app(LabSections::class)->start($project,$input+['requestId'=>$step->request_id]);
        elseif($section->status==='queued'&&$section->started_at===null&&$section->created_at->lt(now()->subMinute())){
            $claimed=app(LabWorkflowInvocation::class)->claim($steps->invocation($step),$project->id,$step->request_id,'section',function()use($section,$step){
                $fresh=$section->fresh();$record=$step->fresh();$data=$record->data??[];
                if($fresh->status!=='queued'||$fresh->started_at!==null||(int)($data['lastQueueReconciledAt']??0)>now()->timestamp-60)return false;
                $data['lastQueueReconciledAt']=now()->timestamp;$record->update(['data'=>$data]);return true;
            });if($claimed)\App\Jobs\ProcessIguideLabSection::dispatch($section->id);
        }
        $steps->attach($step,$section->id);return $section;
    }
    private function analysis(IguideLabWorkflowItem $item,IguideLabProject $project,string $key,IguideLabCase $cut,array $options,array $reference): IguideLabAnalysis
    {
        $instructions='Plan exactly one sofa, one coffee table and one accent chair in the requested style. Use explicit prospective source-floor footprints and complete-object envelopes. Preserve all architecture, finishes, routes, openings, capture artifacts and existing objects. No rug or accessories. This pre-image plan does not certify the realized furniture; actual placement and full-sphere quality are checked separately.';
        if($item->instructions!=='')$instructions.="\nUser intent, restricted to this three-piece scope: ".$item->instructions;
        $input=['caseId'=>$cut->id,'preset'=>'staging','style'=>$item->style,'instructions'=>$instructions,'options'=>$options,'stagingLayout'=>$reference];
        $steps=app(LabWorkflowSteps::class);$step=$steps->reserve($item,$key,'analysis',$input);
        $analysis=IguideLabAnalysis::where('project_id',$project->id)->where('request_id',$step->request_id)->first();
        if(!$analysis)$analysis=app(LabAnalyses::class)->start($project,$input+['requestId'=>$step->request_id],$steps->invocation($step));
        else app(LabWorkflows::class)->reconcileQueuedChild($step,$analysis);
        $steps->attach($step,$analysis->id);return $analysis;
    }
    private function completed(IguideLabWorkflowItem $item,$record,string $phase,array $data): bool
    {
        if($record->status==='completed')return true;
        $owner=app(LabWorkflows::class);
        if(in_array($record->status,['failed','needs_attention','needs_review'],true))$owner->finish($item,'needs_review','source_layout_child_unconfirmed','A source-only child requires review; its existing receipt is retained.',$data);
        else $owner->save($item,$phase,$data);return false;
    }
}
