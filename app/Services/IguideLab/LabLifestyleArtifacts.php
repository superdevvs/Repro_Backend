<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabAnalysis,IguideLabComposite,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabLifestyleAssessment,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Native source plus separately projected objects and scalar source-floor shadows. */
class LabLifestyleArtifacts extends LabWorkflowArtifacts
{
    public const REVIEW_CONSTRAINTS = [
        'Exactly two fictional adults must stand separately and converse naturally. No sitting, props, person-person overlap, furniture interaction, extra people or accessories.',
        'Preserve every original object, architecture, finish and detail. Inspect adult scale, plausible anatomy, hands/hair, both shoes per adult, exact source-floor contacts, natural fine boundaries and holes, routes and openings.',
        'Inspect the whole sphere, section boundaries and original parent context for occlusion, reflection or shadow dependencies. A proposed framing or model assessment is not measured physical clearance or optical proof.',
    ];
    public function runHash(IguideLabRun $run): string
    {
        abort_unless($run->status==='completed'&&$run->output_path==='iguide-lab/'.$run->project_id.'/runs/'.$run->id.'.png'
            &&[$run->output_width,$run->output_height]===[2048,2048]&&is_string($run->output_sha256),422);
        $path=app(LabLifestyleAssessmentContext::class)->file($run->project_id,$run->output_path,$run->output_sha256,32*1024*1024);
        $size=@getimagesize($path); abort_unless($size&&[$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);
        return $run->output_sha256;
    }
    public function composeLifestyle(IguideLabWorkflowItem $item,IguideLabWorkflowStep $step,IguideLabRun $run,IguideLabAnalysis $analysis,IguideLabLifestyleAssessment $assessment,array $support): IguideLabComposite
    {
        $this->validateSnapshot($step->input['recipe']); $this->runtime();
        $case=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $section=IguideLabSection::where('project_id',$item->project_id)->where('status','completed')->findOrFail($assessment->data['contextSnapshot']['sectionId']);
        $context=app(LabLifestyleAssessmentContext::class)->capture($item,$section->id,$run->id);
        abort_unless($analysis->id===$context['sourceAnalysisId']&&$step->input['outputSha256']===$this->runHash($run)
            &&$step->input['supportSha256']===$support['reportSha256']&&$step->input['assessmentSha256']===$assessment->result_sha256
            &&($support['report']['status']??null)==='ready_for_unreviewed_composition',422);
        $disk=Storage::disk(LabService::DISK); $root=$disk->path($support['directory']);
        app(LabLifestyleAssessmentContext::class)->file($item->project_id,$support['reportPath'],$support['reportSha256'],1024*1024);
        $inputMask=function($key)use($support,$root){$d=$support['report']['assets'][$key]??[];
            abort_unless(($d['width']??null)===2048&&($d['height']??null)===2048&&basename($d['file']??'')===($d['file']??''),422);
            return $this->asset(['path'=>$root.DIRECTORY_SEPARATOR.$d['file'],'sha256'=>$d['sha256']],$root,[2048,2048]);};
        $objects=$inputMask('objectAlpha');$shadow=$inputMask('scalarShadow');$floor=$inputMask('safeFloorAlpha');
        $geometry=['path'=>app(LabLifestyleAssessmentContext::class)->file($item->project_id,$section->data['geometryPath'],$section->data['geometrySha256'],65536),'sha256'=>$section->data['geometrySha256']];
        $base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id; $out=$base.'/lifestyle-'.$step->id;
        $request=['schemaVersion'=>1,'operation'=>'recompose-section','privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),
            'source'=>['path'=>$disk->path($case->source_path),'sha256'=>$case->source_sha256],
            'candidate'=>['path'=>$disk->path($run->output_path),'sha256'=>$run->output_sha256],'preset'=>'lifestyle','outputDirectory'=>$disk->path($out),
            'geometry'=>$geometry,'objectAlpha'=>$objects,'mode'=>'replacement','scalarShadow'=>$shadow,'floorAllowedAlpha'=>$floor];
        $report=$this->execute($out.'-request.json',$request,$step->input['recipe']);
        $preview=$this->asset($report['artifacts']['preview'],$disk->path($out),[2048,1024]);
        $native=$this->asset($report['artifacts']['native'],$disk->path($out),[$case->width,$case->height]);
        $alpha=$this->asset($report['artifacts']['effective-alpha'],$disk->path($out),[$case->width,$case->height]);
        $sourceAuthority=$this->sourceAuthority($item,$analysis);
        $nativeCanvas=$case->width>2048; $view=$section->view+['vFov'=>$section->view['hFov'],'coordinateConvention'=>'raw panorama yaw zero at centre, positive yaw right, positive pitch up'];
        $manifest=['requestId'=>$step->request_id,'label'=>'Automatic standing-pair Lifestyle candidate','schemaVersion'=>1,'kind'=>'locally_composited_panorama',
            'projection'=>'equirectangular','preset'=>'lifestyle','style'=>$item->style,'resolutionStrategy'=>$nativeCanvas?'native_canvas':'preview_only','compositionMode'=>'perspective_sections',
            'source'=>['caseId'=>$case->id,'sourceSha256'=>$case->source_sha256,'originalSha256'=>$case->source_sha256,'originalWidth'=>$case->width,'originalHeight'=>$case->height],
            'preview'=>['sha256'=>$preview['sha256'],'width'=>2048,'height'=>1024],'native'=>$nativeCanvas?['sha256'=>$native['sha256'],'width'=>$case->width,'height'=>$case->height]:null,
            'generatedDetail'=>['width'=>2048,'height'=>2048,'description'=>'Generated objects from one 2048-square section. Native source floor receives bounded scalar contact shadows; native canvas is not native generated detail.'],
            'recipeSha256'=>LabAnalysisRecipes::hash($step->input),'geometryManifestSha256'=>$geometry['sha256'],'scenePlanSha256'=>$analysis->proposal_sha256,
            'qaManifestSha256'=>hash_file('sha256',$disk->path($out.'/report.json')),
            'children'=>[['runId'=>$run->id,'requestId'=>$run->request_id,'outputSha256'=>$run->output_sha256,'projection'=>'rectilinear','editScope'=>'masked','maskSha256'=>$objects['sha256'],'view'=>$view]],
            'lineage'=>['executionContext'=>'unattended_workflow','workflowInvocation'=>app(LabWorkflowSteps::class)->invocation($step),'recipe'=>$step->input['recipe'],
                'lifestyleRecipe'=>$step->input['lifestyleRecipe'],'assessmentId'=>$assessment->id,'assessmentSha256'=>$assessment->result_sha256,
                'supportReportSha256'=>$support['reportSha256'],'effectiveAlpha'=>['path'=>$out.'/effective-alpha.png','sha256'=>$alpha['sha256']],
                'composition'=>$report['composition']??null,'numericalQa'=>$report['qa'],'operatorInterventions'=>0,'manualAcceptance'=>false,
                'sourceAnalysisId'=>$analysis->id,'sectionId'=>$section->id,'editView'=>$section->view,
                ...$sourceAuthority,
                'editViewAuthority'=>'exact server extraction pose; not independent camera calibration','maskOrigin'=>'model_segmentation','selectionOrigin'=>'machine',
                'limitations'=>['Single panorama only; model placement and mask association do not prove physical geometry.','Unattended candidate requires separate full-sphere visual checking.']]];
        [$composite]=app(LabComposites::class)->add(IguideLabProject::findOrFail($item->project_id),$case,$preview['path'],$manifest,$nativeCanvas?$native['path']:null);
        return $composite;
    }
    protected function validateEvidenceAnalysis(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabAnalysis $analysis): void
    {
        $section=IguideLabSection::where('project_id',$item->project_id)->where('case_id',$item->case_id)->where('status','completed')->findOrFail($composite->manifest['lineage']['sectionId']??'');
        abort_unless($analysis->status==='completed'&&$analysis->project_id===$item->project_id&&$analysis->case_id===$section->derived_case_id
            &&$analysis->source_sha256===$section->data['cutSha256']&&$analysis->proposal_sha256===LabAnalysisRecipes::hash($analysis->proposal)
            &&$analysis->proposal_sha256===$composite->manifest['scenePlanSha256'],422,'The section analysis changed.');
        app(LabLifestyleWorkflow::class)->validateSnapshot($composite->manifest['lineage']['lifestyleRecipe']);
    }
    protected function evidenceSupport(IguideLabWorkflowItem $item,IguideLabComposite $composite): array
    {
        $a=$composite->manifest['lineage']['effectiveAlpha']??[];
        $path=app(LabLifestyleAssessmentContext::class)->file($item->project_id,$a['path']??'',$a['sha256']??'',128*1024*1024);
        return ['editScope'=>'masked','effectiveAlpha'=>['path'=>$path,'sha256'=>$a['sha256']]];
    }
    protected function evidenceReviewContext(IguideLabWorkflowItem $item,IguideLabComposite $composite,IguideLabAnalysis $analysis,array $qa): array
    {
        $authority=$this->sourceAuthority($item,$analysis);
        foreach($authority as $key=>$value)abort_unless(($composite->manifest['lineage'][$key]??null)===$value,422,'Source framing authority changed.');
        $framing=app(LabLifestyleFramings::class)->completed($item,$authority['sourceFramingId']);
        $claims=[];foreach($framing->result['constraints'] as $name=>$check)$claims[]=$name.' ('.$check['status'].'): '.$check['reason'];
        return $this->lifestyleReviewContext(['protectedFeatures'=>[],'uncertainties'=>$claims,'sourceArtifacts'=>[], 'reviewChecks'=>[]],$analysis->proposal,$qa);
    }
    private function sourceAuthority(IguideLabWorkflowItem $item,IguideLabAnalysis $analysis): array
    {
        abort_unless(isset($analysis->data['lifestyleFraming']),422,'Lifestyle requires its original-source framing authority.');
        $gate=app(LabLifestyleCutReadiness::class)->verified($analysis);
        $framing=app(LabLifestyleFramings::class)->completed($item,$analysis->data['lifestyleFraming']['id']);
        return ['sourceFramingId'=>$framing->id,'sourceFramingResultSha256'=>$framing->result_sha256,
            'actualCutReadinessSha256'=>LabAnalysisRecipes::hash($gate)];
    }
    public function lifestyleReviewContext(array $source,array $section,array $qa): array
    {
        $unique=fn(array $values)=>array_values(array_unique($values));
        $result=['protectedFeatures'=>$unique([...array_column($source['protectedFeatures'],'description'),...array_column($section['protectedFeatures'],'description')]),
            'sourceCaveats'=>$unique([...($source['uncertainties']??[]),...($source['sourceArtifacts']??[]),
                ...($section['uncertainties']??[]),...($section['sourceArtifacts']??[]),
                'Source and section plans are same-capture model evidence, not independent calibrated optical or physical geometry proof.']),
            'opticalDependencies'=>[],
            'reviewRequirements'=>$unique([...self::REVIEW_CONSTRAINTS,...$this->evaluationReviewRequirements($source,[]),...$this->evaluationReviewRequirements($section,$qa)])];
        foreach(['protectedFeatures'=>20,'sourceCaveats'=>13,'reviewRequirements'=>12] as $key=>$limit){
            abort_unless(count($result[$key])<=$limit,422,'The complete lifestyle constraints exceed bounded evaluation; review is required.');
            foreach($result[$key] as $text)abort_unless(is_string($text)&&mb_strlen($text)<=600,422,'A lifestyle constraint exceeds bounded evaluation.');
        }
        return $result;
    }
}
