<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabWorkflowItem};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** At most two model-requested projections of the immutable native, never candidate imagery. */
class LabStagingLayoutViews
{
    public function details(IguideLabWorkflowItem $item,IguideLabAnalysis $prior,array $gate): array
    {
        abort_unless(($gate['decision']??null)==='request_details'&&($gate['followupOrdinal']??null)===0
            &&$prior->project_id===$item->project_id&&($prior->data['workflowInvocation']['itemId']??null)===$item->id,422);
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $files=app(LabStagingFramingContext::class);$disk=Storage::disk(LabService::DISK);
        $nativePath=$files->file($item->project_id,$native->source_path,$native->source_sha256,128*1024*1024);
        $base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/staging-layout/details-'.$prior->id;
        $request=['schemaVersion'=>1,'source'=>['path'=>$nativePath,'sha256'=>$native->source_sha256],
            'requests'=>$gate['detailRequests'],'outputDirectory'=>$disk->path($base)];
        $files->put($base.'-request.json',json_encode($request,JSON_THROW_ON_ERROR));
        $report=$this->execute($disk->path($base.'-request.json'),$disk->path('iguide-lab/'.$item->project_id));
        abort_unless(($report['status']??null)==='completed'&&($report['sameCaptureOnly']??null)===true&&($report['editScopeExpanded']??null)===false
            &&($report['providerCalls']??null)===0&&($report['independentCameraEvidence']??null)===false
            &&($report['source']??null)===$request['source']&&count($report['views']??[])===count($request['requests']),422,'Native detail report changed.');
        $identity=$report['runtime']??[];
        abort_unless(($identity['version']??null)==='staging-original-native-details/1'&&($identity['python']??null)==='3.12.3'
            &&($identity['numpy']??null)==='2.5.4'&&($identity['pillow']??null)==='13.0.0'
            &&($identity['workerSha256']??null)===hash_file('sha256',resource_path('iguide-lab/staging_layout_details.py'))
            &&($identity['geometrySha256']??null)===hash_file('sha256',resource_path('iguide-lab/pano_geometry.py')),422,'Detail runtime changed.');
        $manifest=$files->file($item->project_id,$base.'/manifest.json',$report['manifest']['sha256']??'',65536);
        abort_unless(realpath($report['manifest']['path']??'')===$manifest,422);
        $views=$assets=[];
        foreach($report['views'] as $i=>$view){
            $id=LabStagingLayoutProtocol::DETAILS[$i];$pose=$request['requests'][$i]['pose'];$path=$base.'/views/'.$id.'.png';
            $file=$files->file($item->project_id,$path,$view['sha256']??'',8*1024*1024);$size=getimagesize($file);
            abort_unless(($view['id']??null)===$id&&realpath($view['path']??'')===$file
                &&LabMachineSegmentationPlan::canonicalHash($view['pose']??[])===LabMachineSegmentationPlan::canonicalHash($pose+['width'=>1024,'height'=>1024])
                &&[$size[0],$size[1],$size['mime']] === [1024,1024,'image/png'],422,'Native detail pose or bytes changed.');
            $views[]=['id'=>$id,'sha256'=>$view['sha256'],'nativeSourceSha256'=>$native->source_sha256,'width'=>1024,'height'=>1024,
                'projection'=>'rectilinear','role'=>'same_capture_native_detail','pose'=>$pose];
            $assets[]=['id'=>$id,'path'=>$path,'sha256'=>$view['sha256']];
        }
        return ['views'=>$views,'assets'=>$assets,'manifestSha256'=>$report['manifest']['sha256']];
    }
    protected function execute(string $request,string $root): array
    {
        $env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);
        $env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $process=new Process([(string)config('iguide-lab.pipeline_python_path'),resource_path('iguide-lab/staging_layout_details.py'),
            '--request',$request,'--allowed-root',$root],null,$env,null,45);
        $process->run();$report=json_decode($process->getOutput(),true);
        abort_unless($process->isSuccessful()&&is_array($report),422,'Bounded original detail extraction failed.');return $report;
    }
    public function prepare(IguideLabAnalysis $analysis,IguideLabCase $cut,array $snapshot): array
    {
        $prepared=app(LabSectionAnalysisViews::class)->prepare($analysis,$cut,$snapshot);
        $reference=$analysis->data['stagingLayout'];
        if($reference['followup']['operation']!=='native_details')return $prepared;
        $item=IguideLabWorkflowItem::where('project_id',$analysis->project_id)->findOrFail($analysis->data['workflowInvocation']['itemId']);
        $prior=IguideLabAnalysis::where('project_id',$analysis->project_id)->findOrFail($reference['followup']['previousAnalysisId']);
        $gate=app(LabStagingLayoutReadiness::class)->verified($prior,false);$details=$this->details($item,$prior,$gate);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($details['views'])===LabMachineSegmentationPlan::canonicalHash($reference['detailViews']),422);
        $files=app(LabStagingFramingContext::class);$base='iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id;
        foreach($details['assets'] as $i=>$asset){$bytes=file_get_contents($files->file($analysis->project_id,$asset['path'],$asset['sha256']));
            $path=$base.'/'.$asset['id'].'.png';$files->put($path,$bytes);$v=$details['views'][$i];
            $prepared['views'][]=['id'=>$v['id'],'path'=>$path,'sha256'=>$v['sha256'],'width'=>1024,'height'=>1024,'projection'=>'rectilinear',
                'role'=>'same_capture_native_detail','yaw'=>$v['pose']['yaw'],'pitch'=>$v['pose']['pitch'],'hFov'=>$v['pose']['hFov']];}
        $prepared['stagingDetailManifestSha256']=$details['manifestSha256'];return $prepared;
    }
    public function verifyStored(IguideLabAnalysis $analysis): void
    {
        $data=$analysis->data;$reference=$data['stagingLayout'];$views=$data['views']??[];
        abort_unless(array_column($views,'id')===[...LabAnalysisSourceContext::IDS,...array_column($reference['detailViews'],'id')],422);
        $base=clone $analysis;$baseData=$data;$baseData['views']=array_slice($views,0,6);$base->setAttribute('data',$baseData);
        app(LabSectionAnalysisViews::class)->verifyStored($base);
        foreach($reference['detailViews'] as $i=>$expected){$view=$views[6+$i];$path='iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id.'/'.$expected['id'].'.png';
            abort_unless(($view['path']??null)===$path&&($view['sha256']??null)===$expected['sha256']&&[$view['width'],$view['height']]===[1024,1024]
                &&$view['projection']==='rectilinear'&&$view['role']==='same_capture_native_detail',422);
            foreach(['yaw','pitch','hFov'] as $key)abort_unless((float)$view[$key]===(float)$expected['pose'][$key],422);
            app(LabStagingFramingContext::class)->file($analysis->project_id,$path,$expected['sha256'],8*1024*1024);}
    }
}
