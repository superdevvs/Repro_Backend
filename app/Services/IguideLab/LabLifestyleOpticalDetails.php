<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabLifestyleFraming,IguideLabWorkflow,IguideLabWorkflowItem};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Private, original-native evidence only. No public crop or paid-write authority. */
class LabLifestyleOpticalDetails
{
    public function implementation(): array
    {
        $out=app(LabLifestyleFramings::class)->implementation();
        $out['opticalServiceSha256']=hash_file('sha256',__FILE__);
        $out['opticalProtocolSha256']=hash_file('sha256',(new \ReflectionClass(LabLifestyleOpticalProtocol::class))->getFileName());
        $out['opticalWorkerSha256']=hash_file('sha256',resource_path('iguide-lab/lifestyle_optical_details_v2.py'));
        $out['publicAssetReaderSha256']=hash_file('sha256',(new \ReflectionClass(\App\Http\Controllers\API\IguideLabController::class))->getFileName());
        foreach(['opticalCut'=>LabLifestyleOpticalCutProtocol::class,'opticalCutViews'=>LabLifestyleOpticalCutViews::class,'sampling'=>LabLifestyleOpticalSampling::class,'canonicalBinding'=>LabMachineSegmentationPlan::class,'recipes'=>LabAnalysisRecipes::class,'analyses'=>LabAnalyses::class,'analysisProvider'=>LabAnalysisProvider::class,'cutReadiness'=>LabLifestyleCutReadiness::class,'policy'=>LabWorkflowPolicy::class,'steps'=>LabWorkflowSteps::class,'sourceAuthority'=>LabWorkflowSources::class] as $key=>$class)$out[$key.'Sha256']=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        return $out;
    }
    public function snapshot(): array
    {
        $path=(string)config('iguide-lab.lifestyle_optical_runtime_manifest');$python=(string)config('iguide-lab.pipeline_python_path');clearstatcache(true,$python);
        abort_unless($path!==''&&is_file($path)&&!is_link($path)&&filesize($path)<=65536&&is_file($python)&&is_executable($python)&&realpath($python)!==false,503,'Validated Lifestyle optical detail runtime is unavailable.');
        $m=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);$code=$this->implementation();$resolved=realpath($python);$pythonSha=hash_file('sha256',$resolved);
        abort_unless(($m['version']??null)===LabLifestyleOpticalProtocol::VERSION&&($m['validationStatus']??null)==='passed'
            &&($m['pythonPath']??null)===$python&&($m['pythonResolvedPath']??null)===$resolved&&($m['pythonSha256']??null)===$pythonSha&&($m['pythonVersion']??null)==='3.12.3'
            &&($m['numpyVersion']??null)==='2.5.4'&&($m['pillowVersion']??null)==='13.0.0'&&($m['implementation']??null)===$code,503,'Optical detail runtime is not validated.');
        return ['version'=>LabLifestyleOpticalProtocol::VERSION,'implementation'=>$code,'runtimeManifestSha256'=>hash_file('sha256',$path),
            'pythonPath'=>$python,'pythonResolvedPath'=>$resolved,'pythonSha256'=>$pythonSha,'cutRecipe'=>app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_OPTICAL_CUT),'protocol'=>LabLifestyleOpticalProtocol::snapshot(),'maxFollowups'=>1,'maxDetails'=>2,
            'analysisSteps'=>4,'imageSteps'=>1,'segmentationSteps'=>2,'sectionSteps'=>1,'compositionSteps'=>1,'evaluationSteps'=>1];
    }
    public function ready(): bool {try{$this->snapshot();return true;}catch(\Throwable){return false;}}
    public function validate(array $snapshot): void
    {abort_unless(config('iguide-lab.lifestyle_optical_details_enabled',false)===true&&LabAnalysisRecipes::hash($snapshot)===LabAnalysisRecipes::hash($this->snapshot()),422,'Reserved optical detail policy changed or disabled.');}

    public function capture(IguideLabWorkflowItem $item,?string $parentId=null): array
    {
        $workflow=IguideLabWorkflow::where('project_id',$item->project_id)->findOrFail($item->workflow_id);
        $this->validate($workflow->policy['lifestyleOpticalDetails']??[]);
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        // completed() reparses the retained receipt and freshly verifies all seven originals.
        // Reuse that verified bundle, avoiding a duplicate diagnostic subprocess on every attempt.
        $parent=$parentId===null?null:app(LabLifestyleFramings::class)->completed($item,$parentId);
        $bundle=$parent===null?app(LabLifestyleFramingContext::class)->capture($item):$parent->data['contextSnapshot'];$c=&$bundle['context'];
        $c['version']=LabLifestyleOpticalProtocol::VERSION;$c['nativeDimensions']=['width'=>$native->width,'height'=>$native->height];$c['followupOrdinal']=$parentId===null?0:1;$c['parent']=null;
        if($parentId===null)return $bundle;
        $result=$parent->result;
        abort_unless(($result['version']??null)===LabLifestyleOpticalProtocol::VERSION&&($result['status']??null)==='detail_required'&&($result['followupOrdinal']??null)===0,422,'Only one initial resolution enquiry may request details.');
        $responseSha=$parent->data['providerReceipt']['response']['sha256'];
        $c['parent']=['id'=>$parent->id,'resultSha256'=>$parent->result_sha256,'responseSha256'=>$responseSha,'result'=>$result];
        $details=$this->extract($item,$parent,$result,$native,$workflow->policy);
        $c['views']=[...$c['views'],...$details['views']];$bundle['assets']=[...$bundle['assets'],...$details['assets']];$bundle['nativeDetailManifestSha256']=$details['manifestSha256'];return $bundle;
    }
    protected function extract(IguideLabWorkflowItem $item,IguideLabLifestyleFraming $parent,array $result,$native,array $policy): array
    {
        $files=app(LabLifestyleFramingContext::class);$disk=Storage::disk(LabService::DISK);
        abort_unless($native->width>2048&&$native->width===2*$native->height,422,'A prepared 2K panorama cannot provide native detail evidence.');
        $source=$files->file($item->project_id,$native->source_path,$native->source_sha256,128*1024*1024);
        $base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/lifestyle-native-details/'.$parent->id;
        $request=['schemaVersion'=>1,'source'=>['path'=>$source,'sha256'=>$native->source_sha256,'width'=>$native->width,'height'=>$native->height],
            'authority'=>['parentFramingId'=>$parent->id,'resultSha256'=>$parent->result_sha256,'responseSha256'=>$parent->data['providerReceipt']['response']['sha256'],
                'policySha256'=>LabAnalysisRecipes::hash($policy),'requestId'=>$parent->request_id],
            'requests'=>$result['detailRequests'],'outputDirectory'=>$disk->path($base)];
        $files->put($base.'-request.json',json_encode($request,JSON_THROW_ON_ERROR));
        $report=$this->execute($disk->path($base.'-request.json'),$disk->path('iguide-lab/'.$item->project_id));
        abort_unless(($report['status']??null)==='completed'&&($report['sameCaptureOnly']??null)===true&&($report['editScopeExpanded']??null)===false
            &&($report['independentCameraEvidence']??null)===false&&($report['providerCalls']??null)===0&&LabMachineSegmentationPlan::canonicalHash($report['source']??[])===LabMachineSegmentationPlan::canonicalHash($request['source'])
            &&LabMachineSegmentationPlan::canonicalHash($report['authority']??[])===LabMachineSegmentationPlan::canonicalHash($request['authority'])&&count($report['views']??[])===count($request['requests']),422,'Native optical detail report changed.');
        $r=$report['runtime']??[];
        abort_unless(($r['version']??null)==='lifestyle-original-native-details/2'&&($r['python']??null)==='3.12.3'&&($r['numpy']??null)==='2.5.4'&&($r['pillow']??null)==='13.0.0'
            &&($r['workerSha256']??null)===hash_file('sha256',resource_path('iguide-lab/lifestyle_optical_details_v2.py'))
            &&($r['geometrySha256']??null)===hash_file('sha256',resource_path('iguide-lab/pano_geometry.py')),422,'Optical detail runtime changed.');
        $manifest=$files->file($item->project_id,$base.'/manifest.json',$report['manifest']['sha256']??'',65536);
        abort_unless(realpath($report['manifest']['path']??'')===$manifest,422);$views=$assets=[];
        foreach($report['views'] as $i=>$v){$id=LabLifestyleOpticalProtocol::DETAILS[$i];$pose=$request['requests'][$i]['pose'];$path=$base.'/views/'.$id.'.png';
            $file=$files->file($item->project_id,$path,$v['sha256']??'',16*1024*1024);$s=getimagesize($file);
            abort_unless(($v['id']??null)===$id&&realpath($v['path']??'')===$file&&[$s[0],$s[1],$s['mime']] === [2048,2048,'image/png']
                &&LabMachineSegmentationPlan::canonicalHash($v['pose']??[])===LabMachineSegmentationPlan::canonicalHash($pose+['width'=>2048,'height'=>2048]),422,'Native detail bytes or pose changed.');
            LabLifestyleOpticalSampling::validate($v['sourceSampling']??null,$native->width,$native->height);
            $views[]=['id'=>$id,'sha256'=>$v['sha256'],'width'=>2048,'height'=>2048,'nativeSourceSha256'=>$native->source_sha256,'pose'=>$pose,'sourceSampling'=>$v['sourceSampling']];
            $assets[]=['id'=>$id,'path'=>$path,'sha256'=>$v['sha256']];}
        return ['views'=>$views,'assets'=>$assets,'manifestSha256'=>$report['manifest']['sha256']];
    }
    protected function execute(string $request,string $root): array
    {
        $env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);
        $env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $p=new Process([(string)config('iguide-lab.pipeline_python_path'),resource_path('iguide-lab/lifestyle_optical_details_v2.py'),'--request',$request,'--allowed-root',$root],null,$env,null,130);
        $p->run();$report=json_decode($p->getOutput(),true);abort_unless($p->isSuccessful()&&is_array($report),422,'Original native detail extraction failed.');return $report;
    }
}
