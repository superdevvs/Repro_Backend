<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabComposite,IguideLabInteriorAssessment,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Registered material fields retain original forms; independent style review remains mandatory. */
class LabInteriorDesignArtifacts extends LabWorkflowArtifacts
{
    public const REVIEW_CONSTRAINTS=[
        'The requested Interior design STYLE must be clearly recognizable through actual coherent material, textile or pattern changes on the existing furnishings. A palette-only, weak or merely pleasant recolour is not an edit pass. If that style requires new forms or objects, this bounded candidate must be held.',
        'Compare original and candidate silhouettes, layout, large folds, seams, contact shadows and foreground accessories. Preserve their original geometry and ordering. Inspect pattern scale, perspective, weave continuity, mixed-edge colour halos and visible material realism.',
        'Independently inspect the full original panorama for missed reflection, glossy or transparent surfaces, occlusion and lighting dependencies. Registration statistics, source masks and planning claims do not certify semantic material identity, missing dependencies or style success.',
        'The native canvas retains original high-frequency source detail with a bounded registered 2K material field. It is not native generated detail. Inspect whether retained old texture conflicts with the proposed new material and whether protected original rims produce visible seams.',
    ];
    public function runHash(IguideLabRun $run): string
    {
        abort_unless($run->status==='completed'&&$run->output_path==='iguide-lab/'.$run->project_id.'/runs/'.$run->id.'.png'
            &&[$run->output_width,$run->output_height]===[2048,2048]&&is_string($run->output_sha256),422);
        $file=app(LabInteriorDesignContext::class)->file($run->project_id,$run->output_path,$run->output_sha256,32*1024*1024);
        $size=getimagesize($file);abort_unless([$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);return $run->output_sha256;
    }
    public function composeMaterials(IguideLabWorkflowItem $item,IguideLabWorkflowStep $step,IguideLabRun $run,IguideLabInteriorAssessment $candidate): ?IguideLabComposite
    {
        $this->validateSnapshot($step->input['recipe']);$this->runtime();$service=app(LabInteriorDesignAssessments::class);
        $candidate=$service->completed($item,$candidate->id,'candidate');$cut=$service->completed($item,$candidate->data['contextSnapshot']['cutAssessmentId'],'cut');
        $case=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);$bundle=$cut->data['contextSnapshot'];
        abort_unless($candidate->data['contextSnapshot']['candidateRunId']===$run->id&&$step->input['outputSha256']===$this->runHash($run)
            &&$step->input['candidateAssessmentSha256']===$candidate->result_sha256&&$step->input['cutAssessmentSha256']===$cut->result_sha256,422);
        $transfer=app(LabInteriorDesignMaterials::class)->transfer($item,$candidate,$step->input['materials'],$step->request_id);
        if($transfer['report']['status']!=='unreviewed')return null;
        $disk=Storage::disk(LabService::DISK);$get=function(string $key,array $dims)use($item,$transfer){$a=$transfer['report']['outputs'][$key];
            abort_unless([$a['width'],$a['height']]===$dims,422);return app(LabInteriorDesignMaterials::class)->asset($item->project_id,$transfer['directory'].'/'.$a['file'],$a['sha256'],192*1024*1024);};
        $native=$get('native',[$case->width,$case->height]);$preview=$get('preview',[2048,1024]);$alpha=$get('effectiveAlpha',[$case->width,$case->height]);$sectionAlpha=$get('sectionAlpha',[2048,2048]);
        $stats=$transfer['report']['diagnostics']['native']??[];abort_unless(($stats['outsideAlphaChangedPixels']??-1)===0&&($stats['editablePixels']??0)>0,422);
        $section=IguideLabSection::where('project_id',$item->project_id)->findOrFail($bundle['sectionId']);$view=$section->view+['vFov'=>$section->view['hFov'],'coordinateConvention'=>'raw panorama yaw zero at centre, positive yaw right, positive pitch up'];
        $nativeCanvas=$case->width>2048;
        $manifest=['requestId'=>$step->request_id,'label'=>'Automatic existing-furnishing material candidate','schemaVersion'=>1,'kind'=>'locally_composited_panorama','projection'=>'equirectangular',
            'preset'=>'interior-design','style'=>$item->style,'resolutionStrategy'=>$nativeCanvas?'native_canvas':'preview_only','compositionMode'=>'perspective_sections',
            'source'=>['caseId'=>$case->id,'sourceSha256'=>$case->source_sha256,'originalSha256'=>$case->source_sha256,'originalWidth'=>$case->width,'originalHeight'=>$case->height],
            'preview'=>['sha256'=>$preview['sha256'],'width'=>2048,'height'=>1024],'native'=>$nativeCanvas?['sha256'=>$native['sha256'],'width'=>$case->width,'height'=>$case->height]:null,
            'generatedDetail'=>['width'=>2048,'height'=>2048,'description'=>'Registered 2K material/textile detail fields on original geometry. Native canvas retains source detail; no native generated detail or general style coverage claim.'],
            'recipeSha256'=>LabAnalysisRecipes::hash($step->input),'geometryManifestSha256'=>$bundle['geometry']['sha256'],'scenePlanSha256'=>null,'qaManifestSha256'=>$transfer['reportSha256'],
            'children'=>[['runId'=>$run->id,'requestId'=>$run->request_id,'outputSha256'=>$run->output_sha256,'projection'=>'rectilinear','editScope'=>'masked','maskSha256'=>$sectionAlpha['sha256'],'view'=>$view]],
            'lineage'=>['executionContext'=>'unattended_workflow','workflowInvocation'=>app(LabWorkflowSteps::class)->invocation($step),'recipe'=>$step->input['recipe'],'materials'=>$step->input['materials'],
                'interiorRecipe'=>$step->input['interiorRecipe'],'cutAssessmentId'=>$cut->id,'cutAssessmentSha256'=>$cut->result_sha256,'candidateAssessmentId'=>$candidate->id,'candidateAssessmentSha256'=>$candidate->result_sha256,
                'materialReport'=>['path'=>$transfer['reportPath'],'sha256'=>$transfer['reportSha256']],'effectiveAlpha'=>['path'=>$transfer['directory'].'/'.$transfer['report']['outputs']['effectiveAlpha']['file'],'sha256'=>$alpha['sha256']],
                'sectionId'=>$section->id,'editView'=>$section->view,'editViewAuthority'=>'exact server extraction pose; not independent calibration','maskOrigin'=>'model_segmentation','selectionOrigin'=>'machine',
                'registration'=>$transfer['report']['diagnostics'],'operatorInterventions'=>0,'manualAcceptance'=>false,'crossViewVerified'=>false,'styleFaithfulnessVerified'=>false,
                'limitations'=>self::REVIEW_CONSTRAINTS]];
        [$composite]=app(LabComposites::class)->add(IguideLabProject::findOrFail($item->project_id),$case,$preview['path'],$manifest,$nativeCanvas?$native['path']:null);return $composite;
    }
    public function verifyComposite(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabWorkflowStep $step,IguideLabRun $run): void
    {
        parent::verifyComposite($item,$composite,$step,$run);$lineage=$composite->manifest['lineage'];
        app(LabInteriorDesignWorkflow::class)->validateSnapshot($lineage['interiorRecipe']);
        app(LabInteriorDesignMaterials::class)->validate($lineage['materials']);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($lineage['materials'])===LabMachineSegmentationPlan::canonicalHash($step->input['materials'])
            &&$lineage['candidateAssessmentSha256']===$step->input['candidateAssessmentSha256']&&$lineage['cutAssessmentSha256']===$step->input['cutAssessmentSha256'],422);
        $candidate=app(LabInteriorDesignAssessments::class)->completed($item,$lineage['candidateAssessmentId'],'candidate');
        abort_unless($candidate->result_sha256===$lineage['candidateAssessmentSha256']&&$candidate->data['contextSnapshot']['candidateRunId']===$run->id,422);
        $current=app(LabInteriorDesignMaterials::class)->transfer($item,$candidate,$lineage['materials'],$step->request_id);
        abort_unless($current['reportSha256']===$lineage['materialReport']['sha256']&&$current['reportPath']===$lineage['materialReport']['path'],422,'Material transfer checkpoint changed.');
    }
    public function materialEvidence(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabWorkflowStep $step,IguideLabRun $run): array
    {
        $this->verifyComposite($item,$composite,$step,$run);$case=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $lineage=$composite->manifest['lineage'];$snapshot=$lineage['recipe'];$disk=Storage::disk(LabService::DISK);$loader=app(LabInteriorDesignContext::class);
        $section=IguideLabSection::where('project_id',$item->project_id)->findOrFail($lineage['sectionId']);
        $kind=$composite->native_path?'native':'preview';$path=$kind==='native'?$composite->native_path:$composite->preview_path;
        $out='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/interior-evidence-'.$composite->id;
        $request=['schemaVersion'=>1,'operation'=>'build-evidence','privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),
            'source'=>['path'=>$disk->path($case->source_path),'sha256'=>$case->source_sha256],'candidate'=>['path'=>$disk->path($path),'sha256'=>$composite->manifest[$kind]['sha256']],
            'preset'=>'interior-design','outputDirectory'=>$disk->path($out),'editScope'=>'masked',
            'effectiveAlpha'=>['path'=>$loader->file($item->project_id,$lineage['effectiveAlpha']['path'],$lineage['effectiveAlpha']['sha256'],128*1024*1024),'sha256'=>$lineage['effectiveAlpha']['sha256']],
            'details'=>[['geometry'=>['path'=>$loader->file($item->project_id,$section->data['geometryPath'],$section->data['geometrySha256'],65536),'sha256'=>$section->data['geometrySha256']]]]];
        $report=$this->execute($out.'-request.json',$request,$snapshot);
        abort_unless(array_column($report['evidence']??[],'id')===[...LabVisualQualityProtocol::VIEW_IDS,'detail_1'],422);
        $views=$assets=[];$total=0;foreach($report['evidence'] as $board){$a=$this->asset($board,$disk->path($out),[2048,1024]);$total+=filesize($a['path']);
            abort_unless($a['path']===$disk->path($out.'/'.$board['id'].'.png')&&($board['sourceLeftCandidateRight']??false)===true
                &&$board['halves'][0]['originalAssetSha256']===$case->source_sha256&&$board['halves'][1]['originalAssetSha256']===$composite->manifest[$kind]['sha256'],422);
            $views[]=['id'=>$board['id'],'sha256'=>$a['sha256'],'width'=>2048,'height'=>1024];$assets[]=['path'=>$out.'/'.$board['id'].'.png','sha256'=>$a['sha256']];}
        abort_if($total>24*1024*1024,422);$blockers=array_values(array_map(fn($r)=>$r['code'],array_filter($report['qa']['reviewReasons']??[],fn($r)=>$r['blocking']??false)));
        $intent=app(LabWorkflowEvaluations::class)->intent($item);
        $requirements=[...self::REVIEW_CONSTRAINTS,'Inspect detail_1 and cardinal views for the original requested style '.$item->style.'. Judge the final native composition, not the raw donor or planner assertions.',
            ...$this->evaluationReviewRequirements(['reviewChecks'=>[]],$report['qa'])];
        $context=['sourceSha256'=>$case->source_sha256,'candidateSha256'=>$composite->manifest[$kind]['sha256'],'intentSha256'=>LabAnalysisRecipes::hash($intent),
            'evidenceManifestSha256'=>hash_file('sha256',$disk->path($out.'/report.json')),'projection'=>'equirectangular','preset'=>'interior-design','style'=>$item->style,
            'intent'=>json_encode($intent,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'views'=>$views,
            'mechanicalQa'=>['passed'=>($report['qa']['numericIntegrity']??null)==='pass'&&!$blockers,'blockingReasons'=>$blockers],
            'protectedFeatures'=>['Original architecture, floor, wall, ceiling and fixed finishes.','Existing furniture silhouettes, layout, large folds, contacts and foreground objects.'],
            'sourceCaveats'=>['Source/candidate assessments and machine mask associations are fallible claims, not optical or semantic ground truth.','Native pixel count is not native generated material detail.'],
            'opticalDependencies'=>[],'reviewRequirements'=>$requirements];
        return ['compositeId'=>$composite->id,'manifestPath'=>$out.'/report.json','context'=>$context,'assets'=>$assets];
    }
}
