<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabCase,IguideLabRun,IguideLabSection,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Exact original source, extracted cut and actual donor contexts. No client evidence is accepted. */
class LabInteriorDesignContext
{
    public function snapshot(): array
    {
        return ['contextSha256'=>hash_file('sha256',__FILE__),'preparerSha256'=>hash_file('sha256',__DIR__.'/LabImages.php'),
            'sourceAuthoritySha256'=>hash_file('sha256',__DIR__.'/LabWorkflowSources.php'),'sectionAuthoritySha256'=>hash_file('sha256',__DIR__.'/LabAnalysisSourceContext.php'),
            'diagnosticSha256'=>hash_file('sha256',resource_path('iguide-lab/analysis_views.py')),'geometrySha256'=>hash_file('sha256',resource_path('iguide-lab/pano_geometry.py'))];
    }
    public function capture(IguideLabWorkflowItem $item,string $phase,array $parents): array
    {
        abort_unless($item->preset==='interior-design'&&isset(LabCatalog::STYLES[$item->style]),422);
        return match($phase){'framing'=>$this->original($item,$parents),'cut'=>$this->cut($item,$parents),'candidate'=>$this->candidate($item,$parents),default=>abort(422,'Unknown Interior assessment phase.')};
    }
    protected function original(IguideLabWorkflowItem $item,array $parents): array
    {
        abort_unless($parents===[],422);$native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $disk=Storage::disk(LabService::DISK);app(LabStorage::class)->prepareWorkflow($item->project_id,$item->id);
        $base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/interior-original';
        $png=LabImages::prepared($disk->get($native->source_path));$sha=hash('sha256',$png);$size=getimagesizefromstring($png);
        abort_unless([$size[0],$size[1],$size['mime']]===[2048,1024,'image/png'],422);$this->put($base.'/source.png',$png);
        $request=['schemaVersion'=>1,'source'=>['path'=>$disk->path($base.'/source.png'),'sha256'=>$sha],'outputDirectory'=>$disk->path($base.'/views')];
        $this->put($base.'/request.json',json_encode($request,JSON_THROW_ON_ERROR));
        $env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);
        $env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $p=new Process([(string)config('iguide-lab.pipeline_python_path'),resource_path('iguide-lab/analysis_views.py'),'--request',$disk->path($base.'/request.json')],null,$env,null,90);
        $p->run();$result=json_decode($p->getOutput(),true);abort_unless($p->isSuccessful()&&($result['status']??null)==='completed',422,'Interior original diagnostics failed.');
        $m=$result['manifest'];$code=$this->snapshot();
        abort_unless(($m['source']['sha256']??null)===$sha&&($m['adapterSha256']??null)===$code['diagnosticSha256']&&($m['geometrySha256']??null)===$code['geometrySha256']
            &&array_column($m['views']??[],'id')===array_slice(LabInteriorDesignFramingProtocol::IDS,1),422);
        $views=[['id'=>'source','sha256'=>$sha,'width'=>2048,'height'=>1024]];$assets=[['id'=>'source','path'=>$base.'/source.png','sha256'=>$sha]];$angles=[[0,0],[90,0],[180,0],[270,0],[0,90],[0,-90]];
        foreach($m['views'] as $i=>$view){$path=$base.'/views/views/'.$view['id'].'.png';$file=$this->file($item->project_id,$path,$view['sha256']);$s=getimagesize($file);
            abort_unless(realpath($view['path'])===$file&&[(float)$view['yaw_degrees'],(float)$view['pitch_degrees'],(float)$view['hfov_degrees']] === [(float)$angles[$i][0],(float)$angles[$i][1],100.0]
                &&[$s[0],$s[1],$s['mime']]===[1024,1024,'image/png'],422,'Interior parent diagnostic geometry changed.');
            $views[]=['id'=>$view['id'],'sha256'=>$view['sha256'],'width'=>1024,'height'=>1024];$assets[]=['id'=>$view['id'],'path'=>$path,'sha256'=>$view['sha256']];}
        $intent=['sourceSha256'=>$native->source_sha256,'preset'=>'interior-design','style'=>$item->style,'instructions'=>$item->instructions];
        return ['context'=>['version'=>LabInteriorDesignFramingProtocol::VERSION,'nativeSourceSha256'=>$native->source_sha256,'preparedSourceSha256'=>$sha,
            'sourceAuthoritySha256'=>LabAnalysisRecipes::hash($item->source),'intentSha256'=>LabAnalysisRecipes::hash($intent),'style'=>$item->style,'instructions'=>$item->instructions,'views'=>$views],
            'assets'=>$assets,'implementation'=>$code,'geometryManifestSha256'=>hash_file('sha256',$disk->path($base.'/views/manifest.json'))];
    }
    protected function cut(IguideLabWorkflowItem $item,array $parents): array
    {
        LabInteriorDesignProtocol::keys($parents,['framingId','sectionId']);$framing=app(LabInteriorDesignAssessments::class)->completed($item,$parents['framingId'],'framing');
        abort_unless($framing->result['status']==='framing_candidate',422);
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $section=IguideLabSection::where('project_id',$item->project_id)->findOrFail($parents['sectionId']);$step=$this->child($item,'section',$section->id);
        abort_unless($section->status==='completed'&&$section->case_id===$native->id&&$section->source_sha256===$native->source_sha256
            &&$section->request_id===$step->request_id&&($step->input['framingId']??null)===$framing->id,422,'Interior section is not the reserved original framing.');
        foreach(['yaw','pitch','hFov'] as $key)abort_unless((float)$section->view[$key]===(float)$framing->result['view'][$key],422);
        $cut=IguideLabCase::where('project_id',$item->project_id)->findOrFail($section->derived_case_id);$sourceContext=app(LabAnalysisSourceContext::class)->capture($cut);
        abort_unless($sourceContext!==null&&$sourceContext['binding']['nativeCaseId']===$native->id,422);
        $disk=Storage::disk(LabService::DISK);$base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/interior-cut-'.$section->id;
        $png=LabImages::prepared($disk->get($cut->source_path));$sha=hash('sha256',$png);$size=getimagesizefromstring($png);
        abort_unless([$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);$this->put($base.'/source.png',$png);
        $views=[['id'=>'source','sha256'=>$sha,'width'=>2048,'height'=>2048,'projection'=>'rectilinear','role'=>'authoritative_edit_cut']+$framing->result['view']];
        $assets=[['id'=>'source','path'=>$base.'/source.png','sha256'=>$sha]];$bundle=$framing->data['contextSnapshot'];
        foreach(array_slice($bundle['assets'],0,5) as $i=>$a){$id=$i===0?'parent_source':'parent_'.['front','right','back','left'][$i-1];$this->file($item->project_id,$a['path'],$a['sha256']);
            $view=['id'=>$id,'sha256'=>$a['sha256'],'width'=>$i===0?2048:1024,'height'=>1024,'projection'=>$i===0?'equirectangular':'rectilinear','role'=>'same_capture_context_only'];
            if($i>0)$view+=['yaw'=>($i-1)*90,'pitch'=>0,'hFov'=>100];$views[]=$view;$assets[]=['id'=>$id,'path'=>$a['path'],'sha256'=>$a['sha256']];}
        $manifest=['version'=>LabAnalysisSourceContext::VERSION]+$sourceContext+['views'=>$views];
        $context=['sourceSha256'=>$cut->source_sha256,'projection'=>'rectilinear','preset'=>'interior-design','style'=>$item->style,'instructions'=>$item->instructions,'viewIds'=>LabAnalysisSourceContext::IDS,
            'sourceContext'=>$manifest,'interiorFraming'=>['id'=>$framing->id,'context'=>$bundle['context'],'result'=>$framing->result,'resultSha256'=>$framing->result_sha256]];
        LabInteriorDesignCutProtocol::binding($context);
        $geometry=['version'=>'interior-source-geometry/1','nativeSourceSha256'=>$native->source_sha256,'sourceCutSha256'=>$cut->source_sha256,'preparedSourceSha256'=>$sha,'projection'=>'rectilinear','width'=>2048,'height'=>2048,
            'view'=>array_map('floatval',$framing->result['view']),'convention'=>'raw-yaw-zero-panorama-centre-positive-right-pitch-up'];
        $geometryBytes=json_encode($geometry,JSON_THROW_ON_ERROR);$this->put($base.'/geometry.json',$geometryBytes);
        return ['context'=>$context,'assets'=>$assets,'sectionId'=>$section->id,'cutCaseId'=>$cut->id,'nativeCaseId'=>$native->id,'sourceContextSnapshot'=>$sourceContext,
            'geometry'=>['path'=>$base.'/geometry.json','sha256'=>hash('sha256',$geometryBytes)],'implementation'=>$this->snapshot()];
    }
    protected function candidate(IguideLabWorkflowItem $item,array $parents): array
    {
        LabInteriorDesignProtocol::keys($parents,['cutId','runId']);$cut=app(LabInteriorDesignAssessments::class)->completed($item,$parents['cutId'],'cut');
        abort_unless($cut->result['status']==='ready_for_source_masks',422);$bundle=$cut->data['contextSnapshot'];
        $run=IguideLabRun::where('project_id',$item->project_id)->findOrFail($parents['runId']);$step=$this->child($item,'image',$run->id);
        abort_unless($run->status==='completed'&&$run->request_id===$step->request_id&&$run->case_id===$bundle['cutCaseId']&&($step->input['interiorPlanId']??null)===$cut->id
            &&($run->options['sourceMachinePlan']['id']??null)===$cut->id&&($run->options['sourceMachinePlan']['version']??null)==='machine-interior-material-plan/1'
            &&$run->preset==='interior-design'&&$run->style===$item->style,422,'Interior candidate does not belong to its actual source plan.');
        app(LabInteriorDesignImageDraft::class)->verifyRun($run,IguideLabCase::where('project_id',$item->project_id)->findOrFail($run->case_id));
        $path='iguide-lab/'.$item->project_id.'/runs/'.$run->id.'.png';abort_unless($run->output_path===$path&&is_string($run->output_sha256),422);
        $file=$this->file($item->project_id,$path,$run->output_sha256,32*1024*1024);$size=getimagesize($file);abort_unless([$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);
        $views=[];foreach($bundle['context']['sourceContext']['views'] as $view)$views[]=array_intersect_key($view,array_flip(['id','sha256','width','height']));
        $assets=$bundle['assets'];array_splice($views,1,0,[['id'=>'candidate','sha256'=>$run->output_sha256,'width'=>2048,'height'=>2048]]);
        array_splice($assets,1,0,[['id'=>'candidate','path'=>$path,'sha256'=>$run->output_sha256]]);
        $context=['version'=>LabInteriorDesignCandidateProtocol::VERSION,'cutContext'=>$bundle['context'],'cutResult'=>$cut->result,'cutResultSha256'=>$cut->result_sha256,
            'candidateSha256'=>$run->output_sha256,'candidateRunId'=>$run->id,'views'=>$views];LabInteriorDesignCandidateProtocol::context($context);
        return ['context'=>$context,'assets'=>$assets,'cutAssessmentId'=>$cut->id,'candidateRunId'=>$run->id,'geometry'=>$bundle['geometry'],'implementation'=>$this->snapshot()];
    }
    private function child(IguideLabWorkflowItem $item,string $kind,string $id): IguideLabWorkflowStep
    {$step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('kind',$kind)->where('child_id',$id)->sole();abort_unless($step->input_sha256===LabAnalysisRecipes::hash($step->input),422);return $step;}
    public function file(string $project,string $path,string $sha,int $max=24*1024*1024): string
    {return app(LabStagingAssessmentContext::class)->file($project,$path,$sha,$max);}
    public function put(string $path,string $bytes): void
    {
        $disk=Storage::disk(LabService::DISK);if($disk->exists($path)){abort_unless(!is_link($disk->path($path))&&hash_file('sha256',$disk->path($path))===hash('sha256',$bytes),422,'Immutable Interior evidence changed.');return;}
        abort_unless($disk->put($path,$bytes,['visibility'=>'private']),503,'Interior private storage unavailable.');
    }
}
