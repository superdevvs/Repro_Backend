<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabComposite,IguideLabProject,IguideLabRoomAssessment,IguideLabRun,IguideLabSection,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Actual objects plus source-floor scalar contact attenuation, then unchanged full native QA. */
class LabRoomFurnishingArtifacts extends LabWorkflowArtifacts
{
    /** Called outside the short paid claim before every evaluation primary/quota attempt. */
    public function verifyEvaluationStep(IguideLabWorkflowItem $item,IguideLabWorkflowStep $step): void
    {
        abort_unless($step->kind==='evaluation'&&$step->step_key==='room-evaluation'&&$step->project_id===$item->project_id&&$step->item_id===$item->id,422);
        $composite=IguideLabComposite::where('project_id',$item->project_id)->findOrFail($step->input['compositeId']??'');
        $composition=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('step_key','room-composition')->where('child_id',$composite->id)->sole();
        $run=IguideLabRun::where('project_id',$item->project_id)->where('status','completed')->findOrFail($composition->input['runId']);
        $this->verifyComposite($item,$composite,$composition,$run);
        app(LabWorkflowEvaluations::class)->payload($step->input,$item->project_id,$item->id);
    }
    public static function constraints(string $profileId): array
    {
        $profile=LabInteriorRoomProfiles::get($profileId);
        return [$profile['scope'],
            'Interior design in the selected style must be clearly recognizable in coherent forms, material and texture. A pleasant palette alone is insufficient.',
            'Inspect every complete object, scale, contacts, doors, closets, vents, routes and hearth clearance. Preserve all existing architecture, finishes and objects.',
            'Inspect all original and final directions for reflections, optical occlusion and required shadows. Cropping a dependency away is not a pass. No broad cast shadow or reflected-image change is authorized by this initial profile.',
            $profileId===LabInteriorRoomProfiles::BEDROOM?'The bed and both tables require visible platform/plinth contact with the original floor; do not infer hidden support from furniture-leg heuristics.':'The sofa, chair and coffee table retain the existing bounded living-room support method; mask coverage is not physical clearance proof.',
            'The native canvas is source detail with 2048-square generated objects and tiny scalar contact shadows. It is not native generated detail or cross-view consistency.'];
    }
    public function runHash(IguideLabRun $run): string {return app(LabStagingArtifacts::class)->runHash($run);}
    /** The staging draft check, in any settled state: it was reserved for this item and is never required to pass. */
    private function draftCandidate(IguideLabWorkflowItem $item,string $id): IguideLabRoomAssessment
    {
        $record=IguideLabRoomAssessment::where('project_id',$item->project_id)->where('phase','candidate')->findOrFail($id);
        abort_unless(IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('kind','analysis')->whereKey($record->step_id)->where('child_id',$record->id)->exists()
            &&is_array($record->data['contextSnapshot']['context']??null),422,'The staging draft check does not belong to this item.');
        return $record;
    }
    private function draftCaveat(IguideLabRoomAssessment $candidate): string
    {
        $status=$candidate->status==='completed'?(string)($candidate->result['status']??'unknown'):'no usable answer ('.$candidate->status.')';
        return 'Automatic draft check: '.$status.'. The paid furniture draft was kept and composited; its doubts below are caveats for this final check, not a pass.';
    }
    /** @return list<string> */
    private function draftDoubts(IguideLabRoomAssessment $candidate): array
    {
        if($candidate->status!=='completed'||!is_array($candidate->result['checks']??null))return [];$out=[];
        foreach($candidate->result['checks'] as $name=>$check)if(is_array($check)&&($check['status']??'pass')!=='pass'&&is_string($check['reason']??null))
            $out[]='Draft check '.$name.' ('.$check['status'].'): '.$check['reason'];
        return $out;
    }
    public function composeRoom(IguideLabWorkflowItem $item,IguideLabWorkflowStep $step,IguideLabRun $run,IguideLabRoomAssessment $candidate,array $support): IguideLabComposite
    {
        $this->validateSnapshot($step->input['recipe']);$this->runtime();$service=app(LabInteriorRoomAssessments::class);
        $candidate=$service->completed($item,$candidate->id,'candidate');$b=$candidate->data['contextSnapshot'];$layout=$service->completed($item,$b['layoutId'],'layout');
        $route=app(LabInteriorRoomRoutes::class)->verify($item);$case=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        abort_unless($b['candidateRunId']===$run->id&&$step->input['outputSha256']===$this->runHash($run)&&$step->input['supportSha256']===$support['reportSha256']
            &&$step->input['candidateAssessmentSha256']===$candidate->result_sha256&&$step->input['layoutAssessmentSha256']===$layout->result_sha256
            &&$step->input['routeSha256']===LabMachineSegmentationPlan::canonicalHash($route)&&$support['report']['status']==='ready_for_unreviewed_composition',422);
        $disk=Storage::disk(LabService::DISK);$loader=app(LabInteriorRoomContext::class);$root=$disk->path($support['directory']);
        $loader->file($item->project_id,$support['reportPath'],$support['reportSha256'],1024*1024);
        $mask=function($key)use($support,$root){$a=$support['report']['assets'][$key];abort_unless([$a['width'],$a['height']]===[2048,2048]&&basename($a['file'])===$a['file'],422);
            return $this->asset(['path'=>$root.'/'.$a['file'],'sha256'=>$a['sha256']],$root,[2048,2048]);};
        $objects=$mask('objectAlpha');$shadow=$mask('scalarShadow');$floor=$mask('safeFloorAlpha');
        $section=IguideLabSection::where('project_id',$item->project_id)->where('status','completed')->findOrFail($b['sectionId']);
        $geometry=['path'=>$loader->file($item->project_id,$section->data['geometryPath'],$section->data['geometrySha256'],65536),'sha256'=>$section->data['geometrySha256']];
        $out='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/room-native-'.$step->id;
        $request=['schemaVersion'=>1,'operation'=>'recompose-section','privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),'source'=>['path'=>$disk->path($case->source_path),'sha256'=>$case->source_sha256],
            'candidate'=>['path'=>$disk->path($run->output_path),'sha256'=>$run->output_sha256],'preset'=>'interior-design','outputDirectory'=>$disk->path($out),
            'geometry'=>$geometry,'objectAlpha'=>$objects,'mode'=>'replacement','scalarShadow'=>$shadow,'floorAllowedAlpha'=>$floor];
        $report=$this->execute($out.'-request.json',$request,$step->input['recipe']);
        $preview=$this->asset($report['artifacts']['preview'],$disk->path($out),[2048,1024]);$native=$this->asset($report['artifacts']['native'],$disk->path($out),[$case->width,$case->height]);
        $alpha=$this->asset($report['artifacts']['effective-alpha'],$disk->path($out),[$case->width,$case->height]);$nativeCanvas=$case->width>2048;
        $view=$section->view+['vFov'=>$section->view['hFov'],'coordinateConvention'=>'raw panorama yaw zero at centre, positive yaw right, positive pitch up'];
        $manifest=['requestId'=>$step->request_id,'label'=>'Automatic Interior furnishing candidate','schemaVersion'=>1,'kind'=>'locally_composited_panorama','projection'=>'equirectangular',
            'preset'=>'interior-design','style'=>$item->style,'resolutionStrategy'=>$nativeCanvas?'native_canvas':'preview_only','compositionMode'=>'perspective_sections',
            'source'=>['caseId'=>$case->id,'sourceSha256'=>$case->source_sha256,'originalSha256'=>$case->source_sha256,'originalWidth'=>$case->width,'originalHeight'=>$case->height],
            'preview'=>['sha256'=>$preview['sha256'],'width'=>2048,'height'=>1024],'native'=>$nativeCanvas?['sha256'=>$native['sha256'],'width'=>$case->width,'height'=>$case->height]:null,
            'generatedDetail'=>['width'=>2048,'height'=>2048,'description'=>'One source-bound generated furnishing group. Original native floor receives separate tiny contact attenuation; no native generated-detail or cross-view claim.'],
            'recipeSha256'=>LabAnalysisRecipes::hash($step->input),'geometryManifestSha256'=>$geometry['sha256'],'scenePlanSha256'=>null,'qaManifestSha256'=>hash_file('sha256',$disk->path($out.'/report.json')),
            'children'=>[['runId'=>$run->id,'requestId'=>$run->request_id,'outputSha256'=>$run->output_sha256,'projection'=>'rectilinear','editScope'=>'masked','maskSha256'=>$objects['sha256'],'view'=>$view]],
            'lineage'=>['executionContext'=>'unattended_workflow','workflowInvocation'=>app(LabWorkflowSteps::class)->invocation($step),'recipe'=>$step->input['recipe'],
                'roomRecipe'=>$step->input['roomRecipe'],'supportRecipe'=>$step->input['supportRecipe'],'sourceRoomRoute'=>$route,'profileId'=>$route['profileId'],
                'layoutAssessmentId'=>$layout->id,'layoutAssessmentSha256'=>$layout->result_sha256,'candidateAssessmentId'=>$candidate->id,'candidateAssessmentSha256'=>$candidate->result_sha256,
                'supportReport'=>['path'=>$support['reportPath'],'sha256'=>$support['reportSha256']],'effectiveAlpha'=>['path'=>$out.'/effective-alpha.png','sha256'=>$alpha['sha256']],
                'sectionId'=>$section->id,'editView'=>$section->view,'editViewAuthority'=>'exact source extraction pose, not independent calibration',
                'composition'=>$report['composition']??null,'numericalQa'=>$report['qa'],'operatorInterventions'=>0,'manualAcceptance'=>false,'crossViewVerified'=>false,
                'maskOrigin'=>'model_segmentation','selectionOrigin'=>'machine','limitations'=>self::constraints($route['profileId'])]];
        [$composite]=app(LabComposites::class)->add(IguideLabProject::findOrFail($item->project_id),$case,$preview['path'],$manifest,$nativeCanvas?$native['path']:null,app(LabWorkflowSteps::class)->invocation($step));return $composite;
    }
    /** Staging draft: 1-3 text-only furniture masks composited by the opaque object worker; no support report gates it. */
    public function composeDraft(IguideLabWorkflowItem $item,IguideLabWorkflowStep $step,IguideLabRun $run): IguideLabComposite
    {
        $this->validateSnapshot($step->input['recipe']);$this->runtime();$route=app(LabInteriorRoomRoutes::class)->verify($item);
        $case=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);$masks=$this->draftMasks($item,$step);
        abort_unless($step->input['outputSha256']===$this->runHash($run)&&$step->input['routeSha256']===LabMachineSegmentationPlan::canonicalHash($route),422);
        $disk=Storage::disk(LabService::DISK);$loader=app(LabInteriorRoomContext::class);
        $section=IguideLabSection::where('project_id',$item->project_id)->where('status','completed')->findOrFail($step->input['sectionId']);
        $geometry=['path'=>$loader->file($item->project_id,$section->data['geometryPath'],$section->data['geometrySha256'],65536),'sha256'=>$section->data['geometrySha256']];
        $out='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/room-native-'.$step->id;
        $request=['schemaVersion'=>1,'operation'=>'compose-room-objects','privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),'source'=>['path'=>$disk->path($case->source_path),'sha256'=>$case->source_sha256],
            'candidate'=>['path'=>$disk->path($run->output_path),'sha256'=>$run->output_sha256],'preset'=>'interior-design','outputDirectory'=>$disk->path($out),'geometry'=>$geometry,'objectMasks'=>$masks['files']];
        $report=$this->executeObjects($out.'-request.json',$request,$step->input['recipe']);
        $preview=$this->asset($report['artifacts']['preview'],$disk->path($out),[2048,1024]);$native=$this->asset($report['artifacts']['native'],$disk->path($out),[$case->width,$case->height]);
        $alpha=$this->asset($report['artifacts']['effective-alpha'],$disk->path($out),[$case->width,$case->height]);$nativeCanvas=$case->width>2048;
        $view=$section->view+['vFov'=>$section->view['hFov'],'coordinateConvention'=>'raw panorama yaw zero at centre, positive yaw right, positive pitch up'];
        $reportSha=hash_file('sha256',$disk->path($out.'/report.json'));$limits=self::constraints($route['profileId']);
        $limits[]='Staging draft: furniture comes from one generated draft cut out with machine furniture masks; no contact shadows were added and its own draft check is a caveat, not a pass.';
        $manifest=['requestId'=>$step->request_id,'label'=>'Automatic virtual staging candidate','schemaVersion'=>1,'kind'=>'locally_composited_panorama','projection'=>'equirectangular',
            'preset'=>'interior-design','style'=>$item->style,'resolutionStrategy'=>$nativeCanvas?'native_canvas':'preview_only','compositionMode'=>'perspective_sections',
            'source'=>['caseId'=>$case->id,'sourceSha256'=>$case->source_sha256,'originalSha256'=>$case->source_sha256,'originalWidth'=>$case->width,'originalHeight'=>$case->height],
            'preview'=>['sha256'=>$preview['sha256'],'width'=>2048,'height'=>1024],'native'=>$nativeCanvas?['sha256'=>$native['sha256'],'width'=>$case->width,'height'=>$case->height]:null,
            'generatedDetail'=>['width'=>2048,'height'=>2048,'description'=>'One source-bound generated furniture draft composited with machine furniture masks. No native generated-detail or cross-view claim.'],
            'recipeSha256'=>LabAnalysisRecipes::hash($step->input),'geometryManifestSha256'=>$geometry['sha256'],'scenePlanSha256'=>null,'qaManifestSha256'=>$reportSha,
            'children'=>[['runId'=>$run->id,'requestId'=>$run->request_id,'outputSha256'=>$run->output_sha256,'projection'=>'rectilinear','editScope'=>'masked','maskSha256'=>$step->input['draftMasks']['sha256'][0],'view'=>$view]],
            'lineage'=>['executionContext'=>'unattended_workflow','workflowInvocation'=>app(LabWorkflowSteps::class)->invocation($step),'recipe'=>$step->input['recipe'],
                'roomRecipe'=>$step->input['roomRecipe'],'supportRecipe'=>$step->input['supportRecipe'],'sourceRoomRoute'=>$route,'profileId'=>$route['profileId'],
                'layoutAssessmentId'=>$step->input['layoutAssessmentId'],'layoutAssessmentSha256'=>$step->input['layoutAssessmentSha256'],'candidateAssessmentId'=>$step->input['candidateAssessmentId'],'candidateAssessmentSha256'=>$step->input['candidateAssessmentSha256'],
                'supportReport'=>['path'=>$out.'/report.json','sha256'=>$reportSha],'effectiveAlpha'=>['path'=>$out.'/effective-alpha.png','sha256'=>$alpha['sha256']],
                'sectionId'=>$section->id,'editView'=>$section->view,'editViewAuthority'=>'exact source extraction pose, not independent calibration',
                'composition'=>$report['composition']??null,'numericalQa'=>$report['qa'],'operatorInterventions'=>0,'manualAcceptance'=>false,'crossViewVerified'=>false,
                'maskOrigin'=>'model_segmentation','selectionOrigin'=>'machine','limitations'=>$limits]];
        [$composite]=app(LabComposites::class)->add(IguideLabProject::findOrFail($item->project_id),$case,$preview['path'],$manifest,$nativeCanvas?$native['path']:null,app(LabWorkflowSteps::class)->invocation($step));return $composite;
    }
    /** @return array{recordId:string,files:list<array{path:string,sha256:string}>} the exact masks this composition step was reserved with */
    private function draftMasks(IguideLabWorkflowItem $item,IguideLabWorkflowStep $step): array
    {
        $maskStep=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->findOrFail($step->input['draftMasks']['stepId']);
        $masks=app(LabRoomFurnishingMasks::class)->draftMasks($maskStep);
        abort_unless($masks!==null&&$masks['recordId']===$step->input['draftMasks']['recordId']&&array_column($masks['files'],'sha256')===$step->input['draftMasks']['sha256'],422,'Staging furniture masks changed.');
        return $masks;
    }
    protected function executeObjects(string $path,array $request,array $snapshot): array
    {
        $this->validateSnapshot($snapshot);$this->runtime();$disk=Storage::disk(LabService::DISK);$this->putOnce($path,json_encode($request,JSON_THROW_ON_ERROR));
        $worker=resource_path('iguide-lab/room_object_worker.py');$compositor=resource_path('iguide-lab/object_layer_compositor.py');
        $process=new \Symfony\Component\Process\Process([(string)config('iguide-lab.pipeline_python_path'),$worker,'--request',$disk->path($path)],null,$this->environment(),null,560);
        $process->run();$report=json_decode($process->getOutput(),true);
        abort_unless($process->isSuccessful()&&is_array($report)&&($report['status']??null)==='completed'&&($report['automaticAcceptance']??true)===false&&($report['providerCalls']??-1)===0
            &&($report['reviewStatus']??null)==='unreviewed'&&($report['operation']??null)===$request['operation']&&($report['sourceSha256']??null)===$request['source']['sha256']
            &&($report['candidateSha256']??null)===$request['candidate']['sha256']&&$this->runtimeMatches($report['runtime']??[],$snapshot)
            &&($report['objectRuntime']['objectWorkerSha256']??null)===hash_file('sha256',$worker)&&($report['objectRuntime']['compositorSha256']??null)===hash_file('sha256',$compositor),422,'Automatic local processing failed its execution checks.');
        $this->validateSnapshot($snapshot);return $report;
    }
    public function verifyComposite(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabWorkflowStep $step,IguideLabRun $run): void
    {
        if(isset($step->input['draftMasks'])){
            parent::verifyComposite($item,$composite,$step,$run);$l=$composite->manifest['lineage'];$route=app(LabInteriorRoomRoutes::class)->verify($item);
            app(LabInteriorRoomWorkflow::class)->validateSnapshot($l['roomRecipe']);$this->draftMasks($item,$step);
            abort_unless(LabMachineSegmentationPlan::canonicalHash($l['sourceRoomRoute'])===LabMachineSegmentationPlan::canonicalHash($route)
                &&$l['candidateAssessmentId']===$step->input['candidateAssessmentId']&&$l['candidateAssessmentSha256']===$step->input['candidateAssessmentSha256']
                &&$l['layoutAssessmentSha256']===$step->input['layoutAssessmentSha256']&&$l['sectionId']===$step->input['sectionId'],422);
            app(LabInteriorRoomContext::class)->file($item->project_id,$l['supportReport']['path'],$l['supportReport']['sha256'],4*1024*1024);return;
        }
        parent::verifyComposite($item,$composite,$step,$run);$l=$composite->manifest['lineage'];$route=app(LabInteriorRoomRoutes::class)->verify($item);
        app(LabInteriorRoomWorkflow::class)->validateSnapshot($l['roomRecipe']);app(LabRoomFurnishingSupport::class)->validate($l['supportRecipe']);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($l['sourceRoomRoute'])===LabMachineSegmentationPlan::canonicalHash($route)
            &&$l['candidateAssessmentSha256']===$step->input['candidateAssessmentSha256']&&$l['layoutAssessmentSha256']===$step->input['layoutAssessmentSha256'],422);
        $candidate=app(LabInteriorRoomAssessments::class)->completed($item,$l['candidateAssessmentId'],'candidate');
        abort_unless($candidate->result_sha256===$l['candidateAssessmentSha256']&&$candidate->data['contextSnapshot']['candidateRunId']===$run->id,422);
        $current=app(LabRoomFurnishingSupport::class)->derive($item,$candidate,$l['supportRecipe']);
        abort_unless($current['reportSha256']===$l['supportReport']['sha256']&&$current['reportPath']===$l['supportReport']['path'],422,'Room support checkpoint changed.');
    }
    public function roomEvidence(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabWorkflowStep $step,IguideLabRun $run): array
    {
        $this->verifyComposite($item,$composite,$step,$run);$case=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);$l=$composite->manifest['lineage'];
        $disk=Storage::disk(LabService::DISK);$loader=app(LabInteriorRoomContext::class);$section=IguideLabSection::where('project_id',$item->project_id)->findOrFail($l['sectionId']);
        $kind=$composite->native_path?'native':'preview';$candidatePath=$kind==='native'?$composite->native_path:$composite->preview_path;
        $out='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/room-evidence-'.$composite->id;
        $request=['schemaVersion'=>1,'operation'=>'build-evidence','privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),'source'=>['path'=>$disk->path($case->source_path),'sha256'=>$case->source_sha256],
            'candidate'=>['path'=>$disk->path($candidatePath),'sha256'=>$composite->manifest[$kind]['sha256']],'preset'=>'interior-design','outputDirectory'=>$disk->path($out),'editScope'=>'masked',
            'effectiveAlpha'=>['path'=>$loader->file($item->project_id,$l['effectiveAlpha']['path'],$l['effectiveAlpha']['sha256'],128*1024*1024),'sha256'=>$l['effectiveAlpha']['sha256']],
            'details'=>[['geometry'=>['path'=>$loader->file($item->project_id,$section->data['geometryPath'],$section->data['geometrySha256'],65536),'sha256'=>$section->data['geometrySha256']]]]];
        $report=$this->execute($out.'-request.json',$request,$l['recipe']);abort_unless(array_column($report['evidence'],'id')===[...LabVisualQualityProtocol::VIEW_IDS,'detail_1'],422);
        $views=$assets=[];$total=0;foreach($report['evidence'] as $board){$a=$this->asset($board,$disk->path($out),[2048,1024]);$total+=filesize($a['path']);
            abort_unless($a['path']===$disk->path($out.'/'.$board['id'].'.png')&&($board['sourceLeftCandidateRight']??null)===true&&$board['halves'][0]['originalAssetSha256']===$case->source_sha256
                &&$board['halves'][1]['originalAssetSha256']===$composite->manifest[$kind]['sha256'],422);
            $views[]=['id'=>$board['id'],'sha256'=>$a['sha256'],'width'=>2048,'height'=>1024];$assets[]=['path'=>$out.'/'.$board['id'].'.png','sha256'=>$a['sha256']];}abort_if($total>24*1024*1024,422);
        $draft=isset($step->input['draftMasks']);
        $candidate=$draft?$this->draftCandidate($item,$l['candidateAssessmentId']):app(LabInteriorRoomAssessments::class)->completed($item,$l['candidateAssessmentId'],'candidate');$c=$candidate->data['contextSnapshot']['context'];
        $caveats=['Original classifier optics: '.$c['route']['result']['opticalDependencies']['reason']];
        foreach($c['framing']['result']['checks'] as $name=>$check)$caveats[]='Framing '.$name.' ('.$check['status'].'): '.$check['reason'];
        $caveats[]='Planning and mask claims are not independent physical, optical or calibrated evidence.';
        if($draft){
            // Doubts of the draft check become caveats for the final check, truncated to its bounds instead of aborting.
            $caveats=array_slice($caveats,0,9);$caveats[]=$this->draftCaveat($candidate);
            foreach(array_slice($this->draftDoubts($candidate),0,2) as $doubt)$caveats[]=$doubt;
            $objects=json_decode(file_get_contents($disk->path($l['supportReport']['path'])),true,flags:JSON_THROW_ON_ERROR)['objects']??[];
            $caveats[]='Staging composition: '.(($objects['fallback']??null)==='edge_faded'?'furniture touching the draft border was faded at that border; ':'').'no contact shadows were added under the furniture.';
            $caveats=array_map(fn($text)=>mb_substr($text,0,600),$caveats);
        }
        foreach($caveats as $text)abort_unless(mb_strlen($text)<=600,422,'Complete source caveat exceeds the quality context bound.');abort_if(count($caveats)>13,422);
        $intent=app(LabWorkflowEvaluations::class)->intent($item);$blockers=array_values(array_map(fn($r)=>$r['code'],array_filter($report['qa']['reviewReasons']??[],fn($r)=>$r['blocking']??false)));
        $context=['sourceSha256'=>$case->source_sha256,'candidateSha256'=>$composite->manifest[$kind]['sha256'],'intentSha256'=>LabAnalysisRecipes::hash($intent),
            'evidenceManifestSha256'=>hash_file('sha256',$disk->path($out.'/report.json')),'projection'=>'equirectangular','preset'=>'interior-design','style'=>$item->style,
            'intent'=>json_encode($intent,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'views'=>$views,
            'mechanicalQa'=>['passed'=>($report['qa']['numericIntegrity']??null)==='pass'&&!$blockers,'blockingReasons'=>$blockers],
            'protectedFeatures'=>['Original architecture, fixed finishes, openings, vents, hearths, source objects, source floor texture and camera artifacts.',$c['framing']['result']['checks']['protected_features']['reason']],
            'sourceCaveats'=>$caveats,'opticalDependencies'=>[],
            'reviewRequirements'=>[...self::constraints($l['profileId']),'Inspect detail_1 and all original seven native-derived views. Verify actual realized objects and retained source features independently; a source plan is not approval.']];
        return ['compositeId'=>$composite->id,'manifestPath'=>$out.'/report.json','context'=>$context,'assets'=>$assets];
    }
}
