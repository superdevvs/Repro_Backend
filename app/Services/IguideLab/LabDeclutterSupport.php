<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabDeclutterRemovalPlan,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Bounded original-source mask union. Never creates a user-reviewed LabMask. */
class LabDeclutterSupport
{
    public function snapshot(): array
    {
        $path=(string)config('iguide-lab.declutter_support_runtime_manifest');
        return ['version'=>'automatic-declutter-support/1','adapterSha256'=>hash_file('sha256',__FILE__),
            'workerSha256'=>hash_file('sha256',resource_path('iguide-lab/declutter_support.py')),
            'protocolSha256'=>hash_file('sha256',resource_path('iguide-lab/declutter_removal_protocol.py')),
            'sharedProtocolSha256'=>hash_file('sha256',resource_path('iguide-lab/staging_assessment_protocol.py')),
            'runtimeManifestSha256'=>is_file($path)?hash_file('sha256',$path):null];
    }
    public function ready(): bool
    {
        try{
            $path=(string)config('iguide-lab.declutter_support_runtime_manifest');$python=(string)config('iguide-lab.harmonization_python_path');
            abort_unless(is_file($path)&&!is_link($path)&&filesize($path)<=65536&&is_executable($python)&&app(LabHarmonization::class)->ready(),503);
            $m=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);$s=$this->snapshot();
            abort_unless(($m['validationStatus']??null)==='passed'&&($m['pythonPath']??null)===$python
                &&($m['pythonVersion']??null)==='3.12.3'&&($m['numpyVersion']??null)==='2.5.4'
                &&($m['pillowVersion']??null)==='13.0.0'&&($m['scipyVersion']??null)==='1.18.1',503);
            foreach(['adapterSha256','workerSha256','protocolSha256','sharedProtocolSha256'] as $key)abort_unless(($m['validated'.ucfirst($key)]??null)===$s[$key],503);
            return true;
        }catch(\Throwable){return false;}
    }
    public function validate(array $snapshot): void
    {
        abort_unless($this->ready()&&LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),503,'The removal support runtime changed or is unavailable.');
    }
    public function derive(IguideLabWorkflowItem $item,IguideLabDeclutterRemovalPlan $assessment,array $snapshot): array
    {
        $this->validate($snapshot);[$planStep,$ownedItem]=app(LabDeclutterRemovalPlans::class)->verified($assessment);
        abort_unless($ownedItem->id===$item->id&&$item->preset==='declutter',422);
        $bridge=app(LabDeclutterRemovalBridge::class);$loader=app(LabDeclutterRemovalContext::class);$disk=Storage::disk(LabService::DISK);
        $plans=$receipts=$masks=[];$out='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/removal-support-'.$assessment->id;
        foreach($assessment->result['segmentationPlan'] as $logical){
            $key=$logical['stepKey'];$step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('kind','segmentation')->where('step_key',$key.'-'.$assessment->id)->sole();
            $verified=app(LabDeclutterSegmentationAuthority::class)->verifiedReceipt($step);$plans[]=$verified['plan'];$receipts[]=$verified['receipt'];
            $a=$verified['association']['associations'];abort_unless(count($a)===1&&$a[0]['identity']===$key,422);
            $asset=collect($verified['artifacts'])->firstWhere('index',$a[0]['maskIndex']);abort_unless($asset&&$asset['sha256']===$a[0]['maskSha256'],422);
            $masks[]=['role'=>$key,'image'=>['path'=>$loader->file($item->project_id,$asset['path'],$asset['sha256'],16*1024*1024),'sha256'=>$asset['sha256']]];
        }
        $source=collect($assessment->data['contextSnapshot']['assets'])->firstWhere('id','source_cut');abort_unless($source,422);
        $request=['schemaVersion'=>1,'privateRoot'=>$disk->path('iguide-lab/'.$item->project_id),
            'source'=>['path'=>$loader->file($item->project_id,$source['path'],$source['sha256'],30*1024*1024),'sha256'=>$source['sha256']],
            'assessment'=>$assessment->data['resultAsset'],'plans'=>$bridge->json($out.'-plans.json',$plans),
            'receipts'=>array_map(fn($value,$i)=>$bridge->json($out.'-receipt-'.$i.'.json',$value),$receipts,array_keys($receipts)),
            'masks'=>$masks,'outputDirectory'=>$disk->path($out)];
        $input=$bridge->json($out.'-request.json',$request);$env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);
        $env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $process=new Process([(string)config('iguide-lab.harmonization_python_path'),resource_path('iguide-lab/declutter_support.py'),'--request',$input['path']],null,$env,null,65);
        $process->run();$report=json_decode($process->getOutput(),true);$path=$out.'/report.json';
        abort_unless($process->isSuccessful()&&is_array($report)&&$disk->exists($path)&&filesize($disk->path($path))<=1024*1024
            &&LabMachineSegmentationPlan::canonicalHash($report)===LabMachineSegmentationPlan::canonicalHash(json_decode($disk->get($path),true)),422,'Removal support processing failed.');
        $runtime=$report['runtime']??[];
        abort_unless(($runtime['python']??null)==='3.12.3'&&($runtime['numpy']??null)==='2.5.4'&&($runtime['pillow']??null)==='13.0.0'&&($runtime['scipy']??null)==='1.18.1',422);
        foreach(['workerSha256','protocolSha256','sharedProtocolSha256'] as $key)abort_unless(($runtime[$key]??null)===$snapshot[$key],422);
        abort_unless(in_array($report['status']??null,['ready_for_generation','needs_review'],true)&&($report['automaticAcceptance']??true)===false
            &&($report['humanReviewed']??true)===false&&($report['providerCalls']??-1)===0
            &&($report['sourceCutSha256']??null)===$source['sha256']&&($report['nativeSourceSha256']??null)===$item->source['sha256']
            &&($report['sourceGeometrySha256']??null)===$assessment->data['contextSnapshot']['context']['binding']['sourceGeometrySha256'],422);
        if($report['status']==='ready_for_generation'){
            $a=$report['support']??[];abort_unless(($a['path']??null)===$disk->path($out.'/support.png')&&[$a['width']??null,$a['height']??null]===[2048,2048],422);
            $loader->file($item->project_id,$out.'/support.png',$a['sha256'],16*1024*1024);
            $size=@getimagesize($a['path']);abort_unless($size&&[$size[0],$size[1],$size['mime']]===[2048,2048,'image/png']&&($report['reviewReasons']??null)===[],422);
        }
        $this->validate($snapshot);
        return ['directory'=>$out,'reportPath'=>$path,'reportSha256'=>hash_file('sha256',$disk->path($path)),'report'=>$report,
            'assessmentId'=>$assessment->id,'assessmentSha256'=>$assessment->result_sha256];
    }
}
