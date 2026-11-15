<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabRoomAssessment,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Original-floor/actual-object support; this adapter cannot accept or compose a panorama. */
class LabRoomFurnishingSupport
{
    public const VERSION='interior-profile-support-file-adapter/1';
    public function implementation(): array
    {
        $hashes=['adapterSha256'=>hash_file('sha256',__FILE__)];
        foreach(['worker'=>'room_furnishing_support_worker.py','floorWitness'=>'room_floor_witness.py','floorUnion'=>'room_floor_union.py','bedroom'=>'bedroom_support.py','living'=>'staging_support.py','fileDecoder'=>'staging_support_worker.py'] as $name=>$file)$hashes[$name.'Sha256']=hash_file('sha256',resource_path('iguide-lab/'.$file));
        foreach(['masks'=>LabRoomFurnishingMasks::class,'maskPlan'=>LabRoomFurnishingMaskPlan::class,'floorWitnessPlan'=>LabRoomFloorWitness::class,'floorUnionPlan'=>LabRoomFloorWitnessUnion::class,'context'=>LabInteriorRoomContext::class] as $name=>$class)$hashes[$name.'Sha256']=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        return $hashes;
    }
    public function snapshot(): array
    {
        $path=(string)config('iguide-lab.interior_room_support_runtime_manifest');$python=(string)config('iguide-lab.interior_room_support_python_path');
        abort_unless($path!==''&&is_file($path)&&!is_link($path)&&filesize($path)<=65536&&$python!==''&&is_file($python),503,'Validated room support runtime is unavailable.');
        $m=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        abort_unless(($m['version']??null)===self::VERSION&&($m['validationStatus']??null)==='passed'&&($m['pythonPath']??null)===$python&&($m['pythonSha256']??null)===hash_file('sha256',$python)
            &&($m['pythonVersion']??null)==='3.12.3'&&($m['numpyVersion']??null)==='2.5.4'&&($m['pillowVersion']??null)==='13.0.0'&&($m['opencvVersion']??null)==='5.0.0'
            &&is_string($m['opencvBuildSha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$m['opencvBuildSha256'])
            &&LabMachineSegmentationPlan::canonicalHash($m['implementation']??[])===LabMachineSegmentationPlan::canonicalHash($this->implementation()),503,'Room support runtime changed.');
        return ['version'=>self::VERSION,'runtimeManifestSha256'=>hash_file('sha256',$path),'pythonPath'=>$python,'pythonSha256'=>$m['pythonSha256'],
            'opencvBuildSha256'=>$m['opencvBuildSha256'],'implementation'=>$this->implementation()];
    }
    public function ready(): bool {try{$this->snapshot();return true;}catch(\Throwable){return false;}}
    public function validate(array $snapshot): void {abort_unless(LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),422,'Reserved room support changed.');}

    public function derive(IguideLabWorkflowItem $item,IguideLabRoomAssessment $candidate,array $snapshot): array
    {
        $this->validate($snapshot);$service=app(LabInteriorRoomAssessments::class);$candidate=$service->completed($item,$candidate->id,'candidate');
        abort_unless($candidate->result['status']==='ready_for_masks',422);$b=$candidate->data['contextSnapshot'];$layout=$service->completed($item,$b['layoutId'],'layout');
        $route=app(LabInteriorRoomRoutes::class)->verify($item);$profile=LabInteriorRoomProfiles::get($route['profileId']);$masks=app(LabRoomFurnishingMasks::class);
        $floor=$masks->floor($item,$layout->id);$objectStep=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('step_key','room-candidate-furniture')->sole();
        abort_unless(($objectStep->input['assessmentId']??null)===$candidate->id,422);$objects=$masks->verifiedReceipt($objectStep);
        $disk=Storage::disk(LabService::DISK);$loader=app(LabInteriorRoomContext::class);$base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/room-support-'.$candidate->id;
        app(LabStorage::class)->prepareWorkflow($item->project_id,$item->id);
        $maskRecord=function(array $verified,string $id,string $role)use($item,$base,$loader,$disk){
            $association=collect($verified['association']['associations'])->firstWhere('targetId',$id);abort_unless(is_array($association),422);
            $a=$verified['artifacts'][$association['maskIndex']];abort_unless($a['sha256']===$association['maskSha256'],422);
            $e=['generationOrigin'=>'model_segmentation','selectionOrigin'=>'machine','inputSha256'=>$verified['plan']['inputSha256'],'maskSha256'=>$a['sha256'],
                'requestId'=>$verified['receipt']['requestId'],'providerRequestId'=>$verified['receipt']['providerRequestId'],'rawResponseSha256'=>$verified['receipt']['rawResponseSha256']];
            $bytes=json_encode($e,JSON_THROW_ON_ERROR);$path=$base.'/'.$id.'-evidence.json';$loader->put($path,$bytes);
            return ['id'=>$id,'role'=>$role,'image'=>['path'=>$loader->file($item->project_id,$a['path'],$a['sha256'],LabMachineSegmentationTransport::MAX_MASK_BYTES),'sha256'=>$a['sha256']],
                'evidence'=>['path'=>$disk->path($path),'sha256'=>hash('sha256',$bytes)]];
        };
        $case=IguideLabCase::where('project_id',$item->project_id)->findOrFail($b['cutCaseId']);$source=['path'=>$loader->file($item->project_id,$case->source_path,$case->source_sha256,32*1024*1024),'sha256'=>$case->source_sha256];
        $sourceContext=$layout->data['contextSnapshot']['assets'][1];abort_unless($sourceContext['id']==='parent_source',422);
        $contacts=[];if($profile['id']===LabInteriorRoomProfiles::BEDROOM)foreach($candidate->result['objects'] as $object)$contacts[$object['id']]=$object['visibleBaseContacts'];
        $authority=['version'=>'interior-room-support-authority/1','profileId'=>$profile['id'],'nativeSourceSha256'=>$item->source['sha256'],'sourceCutSha256'=>$case->source_sha256,
            'preparedSourceSha256'=>$b['context']['preparedSourceSha256'],'candidateSha256'=>$b['context']['candidateSha256'],'sourceContextSha256'=>$sourceContext['sha256'],
            'geometrySha256'=>$b['geometry']['sha256'],'routeSha256'=>LabMachineSegmentationPlan::canonicalHash($route),'layoutResultSha256'=>$layout->result_sha256,'candidateResultSha256'=>$candidate->result_sha256,
            'sourceEmptyRoom'=>$b['context']['route']['result']['occupancy']['status']==='empty','foregroundStatus'=>$candidate->result['foregroundStatus'],
            'opticalStatus'=>$candidate->result['opticalStatus'],'contactShadowStatus'=>$candidate->result['contactShadowStatus'],'selectionOrigin'=>'machine','contacts'=>$contacts];
        $witness=$floor['association']['floorWitness']??null;
        if($witness!==null){
            abort_unless(LabRoomFloorWitness::selected($layout->data['recipe'])!==null&&$witness['assessmentSha256']===$layout->result_sha256
                &&$witness['sourceSha256']===$case->source_sha256&&$witness['nativeSourceSha256']===$item->source['sha256'],422);
            $authority['version']=($witness['version']??null)===LabRoomFloorWitnessUnion::VERSION?'interior-room-support-authority/3':'interior-room-support-authority/2';$authority['sourceFloorWitness']=$witness;
        }
        $bytes=json_encode($authority,JSON_THROW_ON_ERROR);$authorityPath=$base.'/assessment.json';$loader->put($authorityPath,$bytes);
        $toAsset=function(array $a)use($item,$loader){return ['path'=>$loader->file($item->project_id,$a['path'],$a['sha256'],32*1024*1024),'sha256'=>$a['sha256']];};
        $request=['schemaVersion'=>1,'operation'=>'derive-room-furnishing-support','profileId'=>$profile['id'],'sourceCut'=>$source,'candidateCut'=>$toAsset($b['assets'][1]),
            'sourceContext'=>$toAsset($sourceContext),'sourceGeometry'=>$toAsset($b['geometry']),'assessment'=>['path'=>$disk->path($authorityPath),'sha256'=>hash('sha256',$bytes)],
            'sourceFloor'=>$maskRecord($floor,'floor','source_floor'),'candidateObjects'=>array_map(fn($id)=>$maskRecord($objects,$id,'candidate_object'),$profile['roles']),
            'pose'=>$b['context']['framing']['result']['view'],'outputDirectory'=>$disk->path($base.'/result')];
        $requestPath=$base.'/request.json';$loader->put($requestPath,json_encode($request,JSON_THROW_ON_ERROR));
        $env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);$env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $process=new Process([$snapshot['pythonPath'],resource_path('iguide-lab/room_furnishing_support_worker.py'),$disk->path($requestPath),'--allowed-root',$disk->path('iguide-lab/'.$item->project_id)],null,$env,null,110);
        $process->run();$summary=json_decode($process->getOutput(),true);abort_unless($process->isSuccessful()&&in_array($summary['status']??null,['needs_review','ready_for_unreviewed_composition'],true),422,'Room support worker did not complete.');
        $path=$base.'/result/report.json';$file=$disk->path($path);abort_unless(is_file($file)&&!is_link($file)&&filesize($file)<=1024*1024,422);$report=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        abort_unless(($report['adapter']??null)===self::VERSION&&($report['status']??null)===$summary['status']&&($report['automaticAcceptance']??null)===false&&($report['nativeCompositionPerformed']??null)===false
            &&($report['providerCalls']??null)===0&&($report['nativeSourceSha256']??null)===$item->source['sha256']&&($report['sourceCutSha256']??null)===$case->source_sha256
            &&($report['candidateSha256']??null)===$b['context']['candidateSha256']&&($report['sourceGeometrySha256']??null)===$b['geometry']['sha256']
            &&($report['identity']['runtime']['opencvBuildSha256']??null)===$snapshot['opencvBuildSha256'],422,'Room support result association changed.');
        if($witness!==null){
            abort_unless(LabMachineSegmentationPlan::canonicalHash($report['floorDomain']['witness']??[])===LabMachineSegmentationPlan::canonicalHash($witness)
                &&($report['floorDomain']['semanticClassVerified']??null)===false&&($report['floorDomain']['restrictionUsage']??null)==='support_eligibility_only'
                &&($report['floorDomain']['objectAlphaRestrictedByWitness']??null)===false,422,'Floor witness support provenance changed.');
            $usable=$report['assets']['usableFloor']??[];abort_unless(($usable['file']??null)==='usable-floor.png',422);
            $loader->file($item->project_id,$base.'/result/usable-floor.png',$usable['sha256']??'',LabMachineSegmentationTransport::MAX_MASK_BYTES);
        }
        return ['report'=>$report,'reportPath'=>$path,'reportSha256'=>hash_file('sha256',$file),'directory'=>$base.'/result'];
    }
}
