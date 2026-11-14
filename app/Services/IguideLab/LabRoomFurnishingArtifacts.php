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
        [$composite]=app(LabComposites::class)->add(IguideLabProject::findOrFail($item->project_id),$case,$preview['path'],$manifest,$nativeCanvas?$native['path']:null);return $composite;
    }
    public function verifyComposite(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabWorkflowStep $step,IguideLabRun $run): void
    {
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
        $candidate=app(LabInteriorRoomAssessments::class)->completed($item,$l['candidateAssessmentId'],'candidate');$c=$candidate->data['contextSnapshot']['context'];
        $caveats=['Original classifier optics: '.$c['route']['result']['opticalDependencies']['reason']];
        foreach($c['framing']['result']['checks'] as $name=>$check)$caveats[]='Original framing '.$name.' ('.$check['status'].'): '.$check['reason'];
        $caveats[]='Planning and mask claims are not independent physical, optical or calibrated evidence.';
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
