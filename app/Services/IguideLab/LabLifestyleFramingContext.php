<?php
namespace App\Services\IguideLab;
use App\Models\IguideLabWorkflowItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Original native and its exact GD preparation only; no generated or operator guide inputs. */
class LabLifestyleFramingContext
{
    public function snapshot(): array
    {
        return ['contextSha256'=>hash_file('sha256',__FILE__),
            'fileAuthoritySha256'=>hash_file('sha256',(new \ReflectionClass(LabLifestyleAssessmentContext::class))->getFileName()),
            'preparerSha256'=>hash_file('sha256',(new \ReflectionClass(LabImages::class))->getFileName()),
            'diagnosticSha256'=>hash_file('sha256',resource_path('iguide-lab/analysis_views.py')),'geometrySha256'=>hash_file('sha256',resource_path('iguide-lab/pano_geometry.py'))];
    }
    public function capture(IguideLabWorkflowItem $item): array
    {
        abort_unless($item->preset==='lifestyle',422);$native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $disk=Storage::disk(LabService::DISK);app(LabStorage::class)->prepareWorkflow($item->project_id,$item->id);
        $base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/lifestyle-framing';
        $png=LabImages::prepared($disk->get($native->source_path));$sha=hash('sha256',$png);$size=getimagesizefromstring($png);
        abort_unless([$size[0],$size[1],$size['mime']] === [2048,1024,'image/png'],422);
        $this->put($base.'/source.png',$png);
        $request=['schemaVersion'=>1,'source'=>['path'=>$disk->path($base.'/source.png'),'sha256'=>$sha],'outputDirectory'=>$disk->path($base.'/views')];
        $this->put($base.'/request.json',json_encode($request,JSON_THROW_ON_ERROR));
        $env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);
        $env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $process=new Process([(string)config('iguide-lab.pipeline_python_path'),resource_path('iguide-lab/analysis_views.py'),'--request',$disk->path($base.'/request.json')],null,$env,null,90);
        $process->run();$result=json_decode($process->getOutput(),true);abort_unless($process->isSuccessful()&&($result['status']??null)==='completed',422,'Framing source preparation failed.');
        $manifest=$result['manifest'];$code=$this->snapshot();
        abort_unless(($manifest['source']['sha256']??null)===$sha&&($manifest['adapterSha256']??null)===$code['diagnosticSha256']
            &&($manifest['geometrySha256']??null)===$code['geometrySha256']&&array_column($manifest['views']??[],'id')===array_slice(LabLifestyleFramingProtocol::IDS,1),422);
        $views=[['id'=>'source','sha256'=>$sha,'width'=>2048,'height'=>1024]];$assets=[['id'=>'source','path'=>$base.'/source.png','sha256'=>$sha]];
        $angles=[[0,0],[90,0],[180,0],[270,0],[0,90],[0,-90]];
        foreach($manifest['views'] as $i=>$view){$path=$base.'/views/views/'.$view['id'].'.png';$file=$this->file($item->project_id,$path,$view['sha256']);$s=getimagesize($file);
            abort_unless(realpath($view['path'])===$file&&[(float)$view['yaw_degrees'],(float)$view['pitch_degrees'],(float)$view['hfov_degrees']]=== [(float)$angles[$i][0],(float)$angles[$i][1],100.0]
                &&[$s[0],$s[1],$s['mime']] === [1024,1024,'image/png'],422,'Framing diagnostic geometry changed.');
            $views[]=['id'=>$view['id'],'sha256'=>$view['sha256'],'width'=>1024,'height'=>1024];$assets[]=['id'=>$view['id'],'path'=>$path,'sha256'=>$view['sha256']];}
        $intent=['sourceSha256'=>$native->source_sha256,'preset'=>'lifestyle','style'=>$item->style,'instructions'=>$item->instructions];
        return ['context'=>['version'=>LabLifestyleFramingProtocol::VERSION,'sourceSha256'=>$native->source_sha256,'sourceAuthoritySha256'=>LabAnalysisRecipes::hash($item->source),
            'intentSha256'=>LabAnalysisRecipes::hash($intent),'style'=>$item->style,'instructions'=>$item->instructions,'views'=>$views],
            'assets'=>$assets,'preparedSourceSha256'=>$sha,'implementation'=>$code,'geometryManifestSha256'=>hash_file('sha256',$disk->path($base.'/views/manifest.json'))];
    }
    public function file(string $project,string $path,string $sha,int $max=24*1024*1024): string
    {return app(LabLifestyleAssessmentContext::class)->file($project,$path,$sha,$max);}
    public function put(string $path,string $bytes): void
    {
        $disk=Storage::disk(LabService::DISK);
        if($disk->exists($path)){abort_unless(!is_link($disk->path($path))&&hash_file('sha256',$disk->path($path))===hash('sha256',$bytes),422,'Immutable framing file changed.');return;}
        abort_unless($disk->put($path,$bytes,['visibility'=>'private']),503,'Framing storage unavailable.');
    }
}
