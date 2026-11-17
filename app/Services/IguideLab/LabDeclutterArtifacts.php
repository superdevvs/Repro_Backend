<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabComposite,IguideLabDeclutterRemovalPlan,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Source-only removal support, exact-domain solve, then one native replacement. */
class LabDeclutterArtifacts extends LabWorkflowArtifacts
{
    public const REVIEW_CONSTRAINTS=[
        'Remove exactly the source-selected opaque decorative object and its explicitly attributable local shadow. Retain every other object, finish, original lighting and geometry.',
        'Inspect the removed silhouette, shadow boundary and entire receiver for object remnants, ghost edges, invented texture, tonal seams or damage to nearby protected detail. The hidden surface is an unobserved generated estimate, never recovered ground truth.',
        'Inspect all original parent directions, poles, wrap and edit boundary for unresolved reflection, occlusion or shadow dependencies. Machine masks and source-plan claims are not proof that a dependency is absent.',
    ];
    public const MATERIALS_REVIEW_CONSTRAINTS=[
        'Remove only the selected small loose portable countertop or tabletop items (one item or one tight touching group, decorative or functional) and only their attributable short local shadows. Preserve all other objects and fixed features. This is not full-room decluttering.',
        'Inspect the entire replacement for target/shadow remnants, ghost outlines, pasted or repeated stone texture, local contrast/noise and scale mismatch, tonal seams, disrupted broad illumination, and damage to counter edges, joints, regular patterns or directional veins. The hidden receiver is a plausible generated estimate, never observed ground truth.',
        'Inspect original and candidate context for coherent target reflections, uncertain occlusion or attributable shadows. Nearby receiver samples and machine masks are supporting evidence, not proof of hidden geometry or optical correctness. Unresolved dependencies require review.',
    ];
    public function runHash(IguideLabRun $run): string
    {
        abort_unless($run->status==='completed'&&$run->output_path==='iguide-lab/'.$run->project_id.'/runs/'.$run->id.'.png'
            &&[$run->output_width,$run->output_height]===[2048,2048]&&is_string($run->output_sha256),422);
        $path=app(LabDeclutterRemovalContext::class)->file($run->project_id,$run->output_path,$run->output_sha256,32*1024*1024);
        $size=@getimagesize($path);abort_unless($size&&[$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);return $run->output_sha256;
    }
    public function composeRemoval(IguideLabWorkflowItem $item,IguideLabWorkflowStep $step,IguideLabRun $run,IguideLabAnalysis $sourcePlan,IguideLabDeclutterRemovalPlan $plan,array $support): ?IguideLabComposite
    {
        $this->validateSnapshot($step->input['recipe']);$this->runtime();
        app(LabDeclutterWorkflow::class)->validateSnapshot($step->input['declutterRecipe']);
        [$planStep,$owned]=app(LabDeclutterRemovalPlans::class)->verified($plan);abort_unless($owned->id===$item->id,422);
        $support=app(LabDeclutterSupport::class)->derive($item,$plan,$step->input['declutterRecipe']['support']);
        $case=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $section=IguideLabSection::where('project_id',$item->project_id)->where('status','completed')->findOrFail($planStep->input['sectionId']);
        $cut=IguideLabCase::where('project_id',$item->project_id)->findOrFail($section->derived_case_id);
        app(LabDeclutterImageDraft::class)->verifyRun($run,$cut);
        abort_unless($planStep->input['sourceAnalysisId']===$sourcePlan->id&&$step->input['outputSha256']===$this->runHash($run)
            &&$step->input['supportSha256']===$support['reportSha256']&&$step->input['removalPlanSha256']===$plan->result_sha256
            &&$support['report']['status']==='ready_for_generation',422);
        $disk=Storage::disk(LabService::DISK);$loader=app(LabDeclutterRemovalContext::class);
        $mask=$support['report']['support'];$loader->file($item->project_id,$support['directory'].'/support.png',$mask['sha256'],16*1024*1024);
        $mask=['path'=>$mask['path'],'sha256'=>$mask['sha256']];
        $base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id;$out=$base.'/removal-'.$step->id;
        $harmonic=['schemaVersion'=>1,'privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),
            'source'=>['path'=>$loader->file($item->project_id,$cut->source_path,$cut->source_sha256,32*1024*1024),'sha256'=>$cut->source_sha256],
            'candidate'=>['path'=>$disk->path($run->output_path),'sha256'=>$run->output_sha256],'mask'=>$mask,
            'recipe'=>$step->input['harmonization'],'outputDirectory'=>$disk->path($out.'-harmonized')];
        $this->putOnce($out.'-harmonic-request.json',json_encode($harmonic,JSON_THROW_ON_ERROR));
        // Blending failure never discards a paid image: composite the raw draft inside the
        // same support mask and let the final check judge it, with the fallback on record.
        try{$solved=app(LabHarmonization::class)->run($disk->path($out.'-harmonic-request.json'),$step->input['harmonization']);}
        catch(\RuntimeException){$solved=null;}
        $raw=($solved['status']??null)!=='completed';
        $donor=$raw?['path'=>$loader->file($item->project_id,$run->output_path,$run->output_sha256,32*1024*1024),'sha256'=>$run->output_sha256]
            :$this->asset($solved['candidate'],$disk->path($out.'-harmonized'),[2048,2048]);
        if($solved!==null){
            $harmonicReport=$out.'-harmonized/report.json';
            abort_unless($disk->exists($harmonicReport)&&LabMachineSegmentationPlan::canonicalHash(json_decode($disk->get($harmonicReport),true))===LabMachineSegmentationPlan::canonicalHash($solved),422);
        }else{
            $solved=['status'=>'needs_attention','errorCode'=>'harmonization_execution_failed','automaticAcceptance'=>false];
            $harmonicReport=$out.'-harmonization-failure.json';$this->putOnce($harmonicReport,json_encode($solved,JSON_THROW_ON_ERROR));
        }
        $geometry=['path'=>$loader->file($item->project_id,$section->data['geometryPath'],$section->data['geometrySha256'],65536),'sha256'=>$section->data['geometrySha256']];
        $request=['schemaVersion'=>1,'operation'=>'recompose-section','privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),
            'source'=>['path'=>$disk->path($case->source_path),'sha256'=>$case->source_sha256],'candidate'=>$donor,
            'preset'=>'declutter','outputDirectory'=>$disk->path($out),'geometry'=>$geometry,'objectAlpha'=>$mask,'mode'=>'replacement'];
        $report=$this->execute($out.'-request.json',$request,$step->input['recipe']);
        $preview=$this->asset($report['artifacts']['preview'],$disk->path($out),[2048,1024]);
        $native=$this->asset($report['artifacts']['native'],$disk->path($out),[$case->width,$case->height]);
        $alpha=$this->asset($report['artifacts']['effective-alpha'],$disk->path($out),[$case->width,$case->height]);
        $nativeCanvas=$case->width>2048;$view=$section->view+['vFov'=>$section->view['hFov'],'coordinateConvention'=>'raw panorama yaw zero at centre, positive yaw right, positive pitch up'];
        $manifest=['requestId'=>$step->request_id,'label'=>'Automatic isolated-object removal candidate','schemaVersion'=>1,'kind'=>'locally_composited_panorama',
            'projection'=>'equirectangular','preset'=>'declutter','style'=>null,'resolutionStrategy'=>$nativeCanvas?'native_canvas':'preview_only','compositionMode'=>'perspective_sections',
            'source'=>['caseId'=>$case->id,'sourceSha256'=>$case->source_sha256,'originalSha256'=>$case->source_sha256,'originalWidth'=>$case->width,'originalHeight'=>$case->height],
            'preview'=>['sha256'=>$preview['sha256'],'width'=>2048,'height'=>1024],'native'=>$nativeCanvas?['sha256'=>$native['sha256'],'width'=>$case->width,'height'=>$case->height]:null,
            'generatedDetail'=>['width'=>2048,'height'=>2048,'description'=>($plan->result['schemaVersion']===LabDeclutterRemovalPolicy::MATERIALS_VERSION
                ?'One generated square section estimates hidden continuous receiver material, including stochastic stone, through source-only machine support and exact-domain boundary correction. Native canvas is not native generated detail.'
                :'One generated square section supplies estimated hidden matte receiver detail through source-only machine support after exact-domain boundary correction. Native canvas is not native generated detail.')],
            'recipeSha256'=>LabAnalysisRecipes::hash($step->input),'geometryManifestSha256'=>$geometry['sha256'],'scenePlanSha256'=>$sourcePlan->proposal_sha256,
            'qaManifestSha256'=>hash_file('sha256',$disk->path($out.'/report.json')),
            'children'=>[['runId'=>$run->id,'requestId'=>$run->request_id,'outputSha256'=>$run->output_sha256,'projection'=>'rectilinear','editScope'=>'masked','maskSha256'=>$mask['sha256'],'view'=>$view]],
            'lineage'=>['executionContext'=>'unattended_workflow','workflowInvocation'=>app(LabWorkflowSteps::class)->invocation($step),'recipe'=>$step->input['recipe'],
                'declutterRecipe'=>$step->input['declutterRecipe'],'removalPlanId'=>$plan->id,'removalPlanSha256'=>$plan->result_sha256,'supportReportSha256'=>$support['reportSha256'],
                'harmonization'=>$step->input['harmonization'],'harmonizationReport'=>['path'=>$harmonicReport,'sha256'=>hash_file('sha256',$disk->path($harmonicReport))],
                'harmonizedCandidateSha256'=>$donor['sha256'],'effectiveAlpha'=>['path'=>$out.'/effective-alpha.png','sha256'=>$alpha['sha256']],
                'composition'=>$report['composition']??null,'numericalQa'=>$report['qa'],'operatorInterventions'=>0,'manualAcceptance'=>false,
                'sourceAnalysisId'=>$sourcePlan->id,'sectionId'=>$section->id,'editView'=>$section->view,'editViewAuthority'=>'exact server extraction pose; not independent camera calibration',
                'maskOrigin'=>'model_segmentation','selectionOrigin'=>'machine','hiddenSurfaceVerified'=>false,'crossViewVerified'=>false,
                'limitations'=>['Hidden receiver detail is a plausible generated estimate, not ground truth.','Machine masks are approximate; full-sphere independent checks are still required.','Single panorama only.',
                    ...($raw?['Boundary blending did not pass ('.mb_substr((string)($solved['errorCode']??'unknown'),0,80).'); the raw generated draft was composited inside the support mask.']:[])]]];
        [$composite]=app(LabComposites::class)->add(IguideLabProject::findOrFail($item->project_id),$case,$preview['path'],$manifest,$nativeCanvas?$native['path']:null,app(LabWorkflowSteps::class)->invocation($step));return $composite;
    }
    public function verifyComposite(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabWorkflowStep $step,IguideLabRun $run): void
    {
        parent::verifyComposite($item,$composite,$step,$run);
        $cut=IguideLabCase::where('project_id',$item->project_id)->findOrFail($run->case_id);
        app(LabDeclutterImageDraft::class)->verifyRun($run,$cut);
        $lineage=$composite->manifest['lineage'];
        abort_unless(($lineage['removalPlanSha256']??null)===$step->input['removalPlanSha256']
            &&($lineage['supportReportSha256']??null)===$step->input['supportSha256']
            &&LabMachineSegmentationPlan::canonicalHash($lineage['declutterRecipe'])===LabMachineSegmentationPlan::canonicalHash($step->input['declutterRecipe'])
            &&LabMachineSegmentationPlan::canonicalHash($lineage['harmonization'])===LabMachineSegmentationPlan::canonicalHash($step->input['harmonization']),422);
        app(LabHarmonization::class)->validate($step->input['harmonization']);
    }
    protected function validateEvidenceAnalysis(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabAnalysis $analysis): void
    {
        parent::validateEvidenceAnalysis($item,$composite,$analysis);
        $lineage=$composite->manifest['lineage'];app(LabDeclutterWorkflow::class)->validateSnapshot($lineage['declutterRecipe']);
        $plan=IguideLabDeclutterRemovalPlan::where('project_id',$item->project_id)->findOrFail($lineage['removalPlanId']);
        [$step,$owned]=app(LabDeclutterRemovalPlans::class)->verified($plan);
        abort_unless($owned->id===$item->id&&$plan->result_sha256===$lineage['removalPlanSha256']&&$step->input['sourceAnalysisId']===$analysis->id,422);
        $support=app(LabDeclutterSupport::class)->derive($item,$plan,$lineage['declutterRecipe']['support']);
        abort_unless($support['reportSha256']===$lineage['supportReportSha256'],422);
        app(LabDeclutterRemovalContext::class)->file($item->project_id,$lineage['harmonizationReport']['path'],$lineage['harmonizationReport']['sha256'],1024*1024);
    }
    protected function evidenceSupport(IguideLabWorkflowItem $item,IguideLabComposite $composite): array
    {
        $a=$composite->manifest['lineage']['effectiveAlpha'];$path=app(LabDeclutterRemovalContext::class)->file($item->project_id,$a['path'],$a['sha256'],128*1024*1024);
        return ['editScope'=>'masked','effectiveAlpha'=>['path'=>$path,'sha256'=>$a['sha256']]];
    }
    protected function evidenceReviewContext(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabAnalysis $analysis,array $qa): array
    {
        $lineage=$composite->manifest['lineage'];
        $plan=IguideLabDeclutterRemovalPlan::where('project_id',$item->project_id)->findOrFail($lineage['removalPlanId']);
        app(LabDeclutterRemovalPlans::class)->verified($plan);
        $support=app(LabDeclutterSupport::class)->derive($item,$plan,$lineage['declutterRecipe']['support']);
        $raw=($lineage['harmonizedCandidateSha256']??null)===($composite->manifest['children'][0]['outputSha256']??false);
        return $this->removalReviewContext($analysis->proposal,$plan->result['proposal'],$qa,$plan->result['schemaVersion']===LabDeclutterRemovalPolicy::MATERIALS_VERSION,
            self::caveatLines($plan->result,$support['report'],$raw));
    }
    /** Recorded doubts reach the final check as caveats; they are never silently dropped. */
    public static function caveatLines(array $planResult,array $supportReport,bool $rawDraft): array
    {
        $codes=array_values(array_unique([...array_filter($planResult['caveats']??[],'is_string'),...array_filter($supportReport['caveats']??[],'is_string')]));
        return [...($codes?[mb_substr('Recorded removal caveats (inspect closely; not proof of failure): '.implode(', ',$codes).'.',0,600)]:[]),
            ...($rawDraft?['Boundary blending did not pass, so the raw generated draft was composited directly inside the support mask. Inspect the replacement boundary for tonal seams.']:[])];
    }
    /** Packs overflowing caveat text into the evaluator's bounded entries (13 x 600) in order; nothing is dropped while it fits. */
    private static function pack(array $texts,int $limit): array
    {
        if(count($texts)<=$limit)return $texts;
        $chunks=mb_str_split(implode(' | ',$texts),600);if(count($chunks)<=$limit)return $chunks;
        $note=' [further caveats are kept on the item record]';
        return [...array_slice($chunks,0,$limit-1),mb_substr($chunks[$limit-1],0,600-mb_strlen($note)).$note];
    }
    public function removalReviewContext(array $source,array $plan,array $qa,bool $materials=false,array $caveats=[]): array
    {
        $unique=fn(array $a)=>array_values(array_unique($a));
        $samples=$materials?array_map(fn($b)=>[(int)floor($b['xMin']*2048),(int)floor($b['yMin']*2048),(int)ceil($b['xMax']*2048),(int)ceil($b['yMax']*2048)],$plan['receiver']['samples']):[];
        $result=['protectedFeatures'=>$unique([...array_column($source['protectedFeatures'],'description'),...array_map(fn($v)=>LabProseText::clip($v['id'].': '.$v['notes'],600),$plan['protectedFeatures'])]),
            'sourceCaveats'=>self::pack($unique([...($source['uncertainties']??[]),...($source['sourceArtifacts']??[]),
                ...(($source['workflow']['mode']??null)==='manual_review'?[LabProseText::clip('The source analysis chose manual review; the section was derived from the overview its target cites. Model reason: '.($source['workflow']['reason']??''),600)]:[]),
                ...$plan['uncertainties'],...$caveats,
                'The receiver behind the removed object is unobserved. Generated continuation is an estimate, not source restoration or ground truth.']),13),
            'opticalDependencies'=>[],
            'reviewRequirements'=>$unique([...($materials?self::MATERIALS_REVIEW_CONSTRAINTS:self::REVIEW_CONSTRAINTS),
                'Selected target: '.$plan['target']['description'].'. Source shadow finding: '.$plan['shadow']['status'].'.'
                    .($materials?' Receiver: '.$plan['receiver']['material'].'; source sample boxes in the 2048 cut [x1,y1,x2,y2]: '.json_encode($samples,JSON_THROW_ON_ERROR).'. No hidden-surface verification.':''),
                ...$this->evaluationReviewRequirements($source,$qa)])];
        foreach(['protectedFeatures'=>20,'sourceCaveats'=>13,'reviewRequirements'=>12] as $key=>$limit){
            abort_unless(count($result[$key])<=$limit,422,'Complete removal constraints exceed bounded evaluation.');
            foreach($result[$key] as $text)abort_unless(is_string($text)&&mb_strlen($text)<=600,422,'A removal constraint exceeds bounded evaluation.');
        }return $result;
    }
}
