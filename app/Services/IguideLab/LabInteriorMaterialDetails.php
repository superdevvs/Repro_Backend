<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabInteriorAssessment,IguideLabWorkflowItem};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Original-native context only. A completed held v1 record cannot enter this route. */
class LabInteriorMaterialDetails
{
    public const VERSION='interior-material-source-followup/1';
    public static function selected(array $recipe): bool
    {return ($recipe['implementation']['sourceDetails']['version']??null)===self::VERSION;}
    public static function implementation(): array
    {
        $files=[];foreach([self::class,LabInteriorMaterialFramingWire::class,LabWorkflows::class,LabInteriorMaterialSourceProtocol::class,LabInteriorMaterialCandidateProtocol::class,LabInteriorMaterialEvidenceWire::class,LabLifestyleOpticalSampling::class,LabProseText::class] as $c)
            $files[class_basename($c)]=hash_file('sha256',(new \ReflectionClass($c))->getFileName());
        foreach(['interior_source_details.py','pano_geometry.py'] as $name)$files[$name]=hash_file('sha256',resource_path('iguide-lab/'.$name));
        $python=(string)config('iguide-lab.pipeline_python_path');clearstatcache(true,$python);
        return ['version'=>self::VERSION,'files'=>$files,'pythonPath'=>$python,'pythonResolvedPath'=>realpath($python)?:null,
            'pythonSha256'=>is_file($python)?hash_file('sha256',$python):null,'maxFollowups'=>1,'maxDetails'=>2,
            'nativeDetailTransport'=>'original','otherTransport'=>'high','detailCanvas'=>[2048,2048],'materialAnalysisSteps'=>4,'analysisWithClassifier'=>5,'imageSteps'=>1,'segmentationSteps'=>15];
    }
    public function followup(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $parent,array $base): array
    {
        abort_unless(config('iguide-lab.interior_material_source_details_enabled',false)===true
            &&self::selected($parent->data['recipe']??[])&&$parent->phase==='cut'
            &&($parent->result['version']??null)===LabInteriorMaterialSourceProtocol::VERSION
            &&($parent->result['status']??null)==='detail_required'&&($parent->result['followupOrdinal']??null)===0,422,'No source-detail authority.');
        $initial=$base['context'];$initial['sourceInspection']=LabInteriorMaterialSourceProtocol::initial();
        abort_unless(LabMachineSegmentationPlan::canonicalHash($initial)===LabMachineSegmentationPlan::canonicalHash($parent->data['contextSnapshot']['context']),422,'Original cut or framing changed.');
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $files=app(LabInteriorDesignContext::class);$disk=Storage::disk(LabService::DISK);
        $source=$files->file($item->project_id,$native->source_path,$native->source_sha256,128*1024*1024);
        abort_unless($native->width>2048&&$native->width===$native->height*2,422,'Prepared-only sources cannot provide native details.');
        $path='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/interior-native-details/'.$parent->id;
        $requests=array_map(function($q){unset($q['checkIds']);return $q;},$parent->result['detailRequests']);
        $request=['schemaVersion'=>1,'source'=>['path'=>$source,'sha256'=>$native->source_sha256,'width'=>$native->width,'height'=>$native->height],
            'authority'=>['parentCutId'=>$parent->id,'resultSha256'=>$parent->result_sha256,'responseSha256'=>$parent->data['providerReceipt']['response']['sha256'],
                'policySha256'=>LabAnalysisRecipes::hash($parent->data['recipe']),'requestId'=>$parent->request_id],
            'requests'=>$requests,'outputDirectory'=>$disk->path($path)];
        $files->put($path.'-request.json',json_encode($request,JSON_THROW_ON_ERROR));
        $report=$this->execute($disk->path($path.'-request.json'),$disk->path('iguide-lab/'.$item->project_id));
        $code=self::implementation();$r=$report['runtime']??[];
        abort_unless(($report['status']??null)==='completed'&&($report['sameCaptureOnly']??null)===true&&($report['editScopeExpanded']??null)===false
            &&($report['providerCalls']??null)===0&&($report['independentCameraEvidence']??null)===false
            &&LabMachineSegmentationPlan::canonicalHash($report['source']??[])===LabMachineSegmentationPlan::canonicalHash($request['source'])
            &&LabMachineSegmentationPlan::canonicalHash($report['authority']??[])===LabMachineSegmentationPlan::canonicalHash($request['authority'])
            &&count($report['views']??[])===count($requests)&&($r['version']??null)==='interior-original-native-details/1'
            &&($r['python']??null)==='3.12.3'&&($r['numpy']??null)==='2.5.4'&&($r['pillow']??null)==='13.0.0'
            &&($r['workerSha256']??null)===$code['files']['interior_source_details.py']&&($r['geometrySha256']??null)===$code['files']['pano_geometry.py'],422,'Source-detail report changed.');
        $manifest=$files->file($item->project_id,$path.'/manifest.json',$report['manifest']['sha256']??'',65536);
        abort_unless(realpath($report['manifest']['path']??'')===$manifest,422);$views=[];
        foreach($report['views'] as $i=>$v){$id=LabInteriorMaterialSourceProtocol::DETAILS[$i];$relative=$path.'/views/'.$id.'.png';$file=$files->file($item->project_id,$relative,$v['sha256']??'',16*1024*1024);$size=getimagesize($file);
            abort_unless(($v['id']??null)===$id&&realpath($v['path']??'')===$file&&[$size[0],$size[1],$size['mime']]===[2048,2048,'image/png']
                &&LabMachineSegmentationPlan::canonicalHash($v['pose']??[])===LabMachineSegmentationPlan::canonicalHash($requests[$i]['pose']+['width'=>2048,'height'=>2048]),422);
            LabLifestyleOpticalSampling::validate($v['sourceSampling']??null,$native->width,$native->height);
            $views[]=['id'=>$id,'sha256'=>$v['sha256'],'width'=>2048,'height'=>2048,'nativeSourceSha256'=>$native->source_sha256,'pose'=>$requests[$i]['pose'],'sourceSampling'=>$v['sourceSampling']];
            $base['assets'][]=['id'=>$id,'path'=>$relative,'sha256'=>$v['sha256']];
        }
        $base['context']['sourceInspection']=['version'=>LabInteriorMaterialSourceProtocol::VERSION,'followupOrdinal'=>1,
            'parent'=>['id'=>$parent->id,'requestId'=>$parent->request_id,'resultSha256'=>$parent->result_sha256,'responseSha256'=>$parent->data['providerReceipt']['response']['sha256'],
                'contextSha256'=>LabMachineSegmentationPlan::canonicalHash($initial),'result'=>$parent->result],
            'details'=>$views,'manifestSha256'=>$report['manifest']['sha256']];
        $base['nativeDetailReportSha256']=hash_file('sha256',$disk->path($path.'/report.json'));
        LabInteriorMaterialSourceProtocol::binding($base['context']);return $base;
    }
    protected function execute(string $request,string $root): array
    {
        $env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);
        $env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','PYTHONDONTWRITEBYTECODE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $p=new Process([(string)config('iguide-lab.pipeline_python_path'),resource_path('iguide-lab/interior_source_details.py'),'--request',$request,'--allowed-root',$root],null,$env,null,130);
        $p->run();$r=json_decode($p->getOutput(),true);abort_unless($p->isSuccessful()&&is_array($r),422,'Original material detail extraction failed.');return $r;
    }
}
