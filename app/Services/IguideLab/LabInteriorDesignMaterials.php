<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabInteriorAssessment,IguideLabWorkflowItem};
use App\Support\LockedWrite;
use Illuminate\Support\Facades\{DB,Storage};
use Symfony\Component\Process\Process;

/** Source-only eligibility and measured donor transfer use distinct immutable requests. */
class LabInteriorDesignMaterials
{
    public const VERSION='interior-material-file-adapter/1';
    private static array $runtimeProbes=[];
    public function snapshot(): array
    {
        $files=[];foreach(['interior_material_worker.py','interior_material_transfer.py','pano_geometry.py'] as $name)$files[$name]=hash_file('sha256',resource_path('iguide-lab/'.$name));
        $path=(string)config('iguide-lab.interior_material_runtime_manifest');$python=(string)config('iguide-lab.interior_material_python_path');
        return ['version'=>self::VERSION,'adapterSha256'=>hash_file('sha256',__FILE__),'maskAuthoritySha256'=>hash_file('sha256',app_path('Services/IguideLab/LabInteriorDesignMasks.php')),
            'contextSha256'=>hash_file('sha256',app_path('Services/IguideLab/LabInteriorDesignContext.php')),'pythonPath'=>$python,'pythonExecutableSha256'=>is_file($python)?hash_file('sha256',$python):null,
            'files'=>$files,'runtimeManifestSha256'=>is_file($path)?hash_file('sha256',$path):null];
    }
    public function ready(): bool
    {
        try{$path=(string)config('iguide-lab.interior_material_runtime_manifest');$python=(string)config('iguide-lab.interior_material_python_path');
            abort_unless(is_file($path)&&!is_link($path)&&filesize($path)<=65536&&is_executable($python),503);
            $m=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
            LabInteriorDesignProtocol::keys($m,['version','validationStatus','python','numpy','pillow','opencv','files']);
            abort_unless($m['version']==='interior-material-runtime/1'&&$m['validationStatus']==='passed'
                &&$m['python']==='3.12.3'&&$m['numpy']==='2.5.4'&&$m['pillow']==='13.0.0'&&$m['opencv']==='5.0.0'
                &&LabMachineSegmentationPlan::canonicalHash($m['files'])===LabMachineSegmentationPlan::canonicalHash($this->snapshot()['files']),503);
            $snapshot=$this->snapshot();$key=LabAnalysisRecipes::hash($snapshot);
            if(!isset(self::$runtimeProbes[$key])){
                $probe=new Process([$python,resource_path('iguide-lab/interior_material_worker.py'),'--runtime-manifest',$path,'--check-runtime',$snapshot['runtimeManifestSha256']],null,$this->environment(),null,15);
                $probe->run();$r=json_decode($probe->getOutput(),true);
                abort_unless($probe->isSuccessful()&&($r['status']??null)==='ready'&&($r['runtimeManifestSha256']??null)===$snapshot['runtimeManifestSha256']
                    &&LabMachineSegmentationPlan::canonicalHash($r['files']??[])===LabMachineSegmentationPlan::canonicalHash($snapshot['files'])
                    &&LabAnalysisRecipes::hash($snapshot)===LabAnalysisRecipes::hash($this->snapshot()),503);
                if(count(self::$runtimeProbes)>8)self::$runtimeProbes=[];self::$runtimeProbes[$key]=true;
            }return true;
        }catch(\Throwable){return false;}
    }
    public function validate(array $snapshot): void
    {abort_unless($this->ready()&&LabMachineSegmentationPlan::canonicalHash($snapshot)===LabMachineSegmentationPlan::canonicalHash($this->snapshot()),503,'The Interior material runtime changed or is unavailable.');}

    public function sourceSupport(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $cut,array $snapshot): array
    {
        $this->validate($snapshot);$cut=app(LabInteriorDesignAssessments::class)->completed($item,$cut->id,'cut');
        abort_unless($cut->result['status']==='ready_for_source_masks',422);
        $request=$this->request($item,$cut,null,$snapshot,$cut->request_id);
        $result=$this->execute($item,$request,$snapshot);
        abort_unless(($result['report']['operation']??null)==='source-support'&&in_array($result['report']['status'],['eligible_for_donor_request','needs_review'],true)
            &&($result['report']['imageGenerationAuthorized']??null)===false&&($result['report']['materialTransferAuthorized']??null)===false,422);
        // Retain an authoritative DB digest in addition to the worker's private commit.
        // A changed report+commit pair cannot become a new image authorization on replay.
        LockedWrite::run(fn()=>DB::transaction(function()use($cut,$result){
            $fresh=IguideLabInteriorAssessment::where('project_id',$cut->project_id)->findOrFail($cut->id);$data=$fresh->data;
            $binding=['reportPath'=>$result['reportPath'],'reportSha256'=>$result['reportSha256'],'requestSha256'=>$result['requestSha256']];
            if(isset($data['sourceSupport']))abort_unless($data['sourceSupport']===$binding,422,'The retained source support changed.');
            else{$data['sourceSupport']=$binding;$fresh->update(['data'=>$data]);}
        }),'iguide-lab.interior-source-support');
        return $result;
    }
    public function transfer(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $candidate,array $snapshot,string $requestId): array
    {
        $this->validate($snapshot);$candidate=app(LabInteriorDesignAssessments::class)->completed($item,$candidate->id,'candidate');
        abort_unless($candidate->result['status']==='ready_for_candidate_masks',422);
        $cut=app(LabInteriorDesignAssessments::class)->completed($item,$candidate->data['contextSnapshot']['cutAssessmentId'],'cut');
        $support=$this->sourceSupport($item,$cut,$snapshot);abort_unless($support['report']['status']==='eligible_for_donor_request',422,'Original material support is not eligible.');
        return $this->execute($item,$this->request($item,$cut,$candidate,$snapshot,$requestId),$snapshot);
    }
    protected function request(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $cut,?IguideLabInteriorAssessment $candidate,array $snapshot,string $requestId): array
    {
        $loader=app(LabInteriorDesignContext::class);$masks=app(LabInteriorDesignMasks::class);$bundle=$cut->data['contextSnapshot'];
        $sourceReceipts=$masks->verifiedFor($item,$cut);$candidateReceipts=$candidate?$masks->verifiedFor($item,$candidate):[];
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $case=IguideLabCase::where('project_id',$item->project_id)->findOrFail($bundle['cutCaseId']);
        $source=$bundle['assets'][0];abort_unless($source['id']==='source',422);
        $disk=Storage::disk(LabService::DISK);$base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/interior-'.($candidate?'transfer-'.$candidate->id:'source-support-'.$cut->id);
        app(LabStorage::class)->prepareWorkflow($item->project_id,$item->id);
        $unionPath=$base.'-protected.png';$foreground=[];
        foreach($cut->result['foreground'] as $t)$foreground[]=['target'=>$t,'verified'=>$sourceReceipts['source-foreground:'.$t['id']]];
        $this->union($item->project_id,$unionPath,$foreground);$union=$this->asset($item->project_id,$unionPath,hash_file('sha256',$disk->path($unionPath)));
        $geometry=$this->asset($item->project_id,$bundle['geometry']['path'],$bundle['geometry']['sha256']);
        $targets=[];foreach($cut->result['targets'] as $t){$a=$sourceReceipts['source-material:'.$t['id']]['artifacts'][0];
            $record=['id'=>$t['id'],'sourceMaskSha256'=>$a['sha256'],'registrationSurface'=>$t['registrationSurface']];
            if($candidate)$record['candidateMaskSha256']=$candidateReceipts['candidate-material:'.$t['id']]['artifacts'][0]['sha256'];$targets[]=$record;}
        $assessment=['version'=>$candidate?'interior-material-assessment/1':'interior-material-source-assessment/1','status'=>$candidate?'eligible_for_transfer':'eligible_for_source_support',
            'authorOrigin'=>'machine','nativeSourceSha256'=>$native->source_sha256,'sourceCutSha256'=>$case->source_sha256,'sourceSha256'=>$source['sha256'],
            'sourceGeometrySha256'=>$geometry['sha256'],'protectedForegroundSha256'=>$union['sha256'],
            'foregroundFindings'=>['source'=>$foreground?'observed':'none_observed'],
            'checks'=>array_fill_keys(['sourceGeometry','completeTargets','opaqueMatte','foreground','offCutOptics','styleNonColour'],'supported'),'targets'=>$targets];
        // Both typed parsers already require every original/candidate scope check to pass.
        if($candidate){$candidateAsset=$candidate->data['contextSnapshot']['assets'][1];abort_unless($candidateAsset['id']==='candidate',422);
            $assessment['candidateSha256']=$candidateAsset['sha256'];$assessment['foregroundFindings']['candidate']=$foreground?'observed':'none_observed';}
        $assessmentAsset=$this->json($item->project_id,$base.'-assessment.json',$assessment);
        $records=[];foreach($cut->result['targets'] as $t){$id=$t['id'];$record=['id'=>$id,'sourceMask'=>$this->mask($item,$base,$id,'source-material',$source['sha256'],$assessmentAsset['sha256'],$sourceReceipts['source-material:'.$id])];
            if($candidate)$record['candidateMask']=$this->mask($item,$base,$id,'candidate-material',$candidateAsset['sha256'],$assessmentAsset['sha256'],$candidateReceipts['candidate-material:'.$id]);$records[]=$record;}
        $fgEvidence=['version'=>'interior-machine-mask-association/1','targetId'=>'protected_foreground','role'=>'source-protected-foreground','inputSha256'=>$source['sha256'],
            'maskSha256'=>$union['sha256'],'generationOrigin'=>$foreground?'machine_union':'machine_assessed_absence','selectionOrigin'=>'machine','assessmentSha256'=>$assessmentAsset['sha256']];
        if($foreground)$fgEvidence['members']=array_map(fn($f)=>$this->mask($item,$base,$f['target']['id'],'source-foreground',$source['sha256'],$assessmentAsset['sha256'],$f['verified']),$foreground);
        $request=['version'=>self::VERSION,'requestId'=>$requestId,'outputDirectory'=>$disk->path($base),'runtimeManifestSha256'=>$snapshot['runtimeManifestSha256'],
            'sourceCut'=>$this->asset($item->project_id,$case->source_path,$case->source_sha256),'source'=>$this->asset($item->project_id,$source['path'],$source['sha256']),
            'native'=>$this->asset($item->project_id,$native->source_path,$native->source_sha256,192*1024*1024),'geometry'=>$geometry,'assessment'=>$assessmentAsset,
            'protectedForeground'=>['id'=>'protected_foreground','role'=>'source-protected-foreground','image'=>$union,'evidence'=>$this->json($item->project_id,$base.'-foreground-evidence.json',$fgEvidence)],'targets'=>$records];
        if($candidate)$request['candidate']=$this->asset($item->project_id,$candidateAsset['path'],$candidateAsset['sha256']);else $request['operation']='source-support';
        $this->json($item->project_id,$base.'-lineage.json',['cutAssessment'=>['id'=>$cut->id,'resultSha256'=>$cut->result_sha256,'receipt'=>$cut->data['providerReceipt']],
            'candidateAssessment'=>$candidate?['id'=>$candidate->id,'resultSha256'=>$candidate->result_sha256,'receipt'=>$candidate->data['providerReceipt']]:null,
            'sourceMaskReceipts'=>$sourceReceipts,'candidateMaskReceipts'=>$candidateReceipts,'sourceAuthority'=>$item->source,'sourceContext'=>$bundle['sourceContextSnapshot']]);
        return $request;
    }
    private function mask(IguideLabWorkflowItem $item,string $base,string $id,string $role,string $sourceSha,string $assessmentSha,array $verified): array
    {
        $a=$verified['artifacts'][0];$e=['version'=>'interior-machine-mask-association/1','targetId'=>$id,'role'=>$role,'inputSha256'=>$sourceSha,'maskSha256'=>$a['sha256'],
            'generationOrigin'=>'model_segmentation','selectionOrigin'=>'machine','assessmentSha256'=>$assessmentSha,'providerReceiptSha256'=>$verified['receipt']['rawResponseSha256']];
        return ['id'=>$id,'role'=>$role,'image'=>$this->asset($item->project_id,$a['path'],$a['sha256']),
            'evidence'=>$this->json($item->project_id,$base.'-'.$role.'-'.$id.'.json',$e)];
    }
    protected function union(string $project,string $path,array $foreground): void
    {
        $image=imagecreatetruecolor(2048,2048);abort_unless($image!==false,503);imagefill($image,0,0,0);
        try{foreach($foreground as $f){$a=$f['verified']['artifacts'][0];$file=$this->asset($project,$a['path'],$a['sha256']);$mask=imagecreatefrompng($file['path']);abort_unless($mask!==false,422);
            try{[$x0,$y0,$x1,$y1]=$f['verified']['receipt']['masks'][0]['bounds'];
                for($y=$y0;$y<$y1;$y++)for($x=$x0;$x<$x1;$x++){$rgb=imagecolorsforindex($mask,imagecolorat($mask,$x,$y));$v=$rgb['red'];
                    if($v>(imagecolorat($image,$x,$y)&255))imagesetpixel($image,$x,$y,($v<<16)|($v<<8)|$v);}
            }finally{imagedestroy($mask);}}
            ob_start();imagepng($image);$bytes=ob_get_clean();abort_unless(is_string($bytes),503);app(LabInteriorDesignContext::class)->put($path,$bytes);
        }finally{imagedestroy($image);}
    }
    public function json(string $project,string $path,array $value): array
    {$bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);app(LabInteriorDesignContext::class)->put($path,$bytes);return $this->asset($project,$path,hash('sha256',$bytes),8*1024*1024);}
    public function asset(string $project,string $path,string $sha,int $max=32*1024*1024): array
    {return ['path'=>app(LabInteriorDesignContext::class)->file($project,$path,$sha,$max),'sha256'=>$sha];}
    protected function execute(IguideLabWorkflowItem $item,array $request,array $snapshot): array
    {
        $disk=Storage::disk(LabService::DISK);$root=$disk->path('iguide-lab/'.$item->project_id);$relative=substr($request['outputDirectory'],strlen($disk->path('')));
        $relative=ltrim(str_replace('\\','/',$relative),'/');abort_unless(str_starts_with($relative,'iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/interior-'),422);
        $input=$this->json($item->project_id,$relative.'-request.json',$request);
        $process=new Process([(string)config('iguide-lab.interior_material_python_path'),resource_path('iguide-lab/interior_material_worker.py'),'--request',$input['path'],'--allowed-root',$root,
            '--runtime-manifest',(string)config('iguide-lab.interior_material_runtime_manifest')],null,$this->environment(),null,200);
        $process->run();abort_unless($process->isSuccessful(),422,'The bounded material worker held or failed.');
        $path=$relative.'/report.json';abort_unless($disk->exists($path)&&!is_link($disk->path($path))&&filesize($disk->path($path))<=1024*1024,422);
        $report=json_decode($disk->get($path),true,flags:JSON_THROW_ON_ERROR);
        abort_unless(($report['version']??null)===self::VERSION&&($report['requestId']??null)===$request['requestId']&&($report['runtimeManifestSha256']??null)===$snapshot['runtimeManifestSha256']
            &&($report['manualAcceptance']??null)===false&&($report['crossViewVerified']??null)===false
            &&($report['sourceGeometrySha256']??null)===$request['geometry']['sha256']&&($report['assessmentSha256']??null)===$request['assessment']['sha256']
            &&LabMachineSegmentationPlan::canonicalHash($report['codeHashes']??[])===LabMachineSegmentationPlan::canonicalHash($snapshot['files']),422);
        $sourceOnly=($request['operation']??null)==='source-support';
        abort_unless(in_array($report['status']??null,$sourceOnly?['eligible_for_donor_request','needs_review']:['unreviewed','needs_review'],true)
            &&is_array($report['inputHashes']??null)&&count($report['inputHashes'])>=6&&is_array($report['outputs']??null),422);
        $expected=$report['status']==='unreviewed'?['native','preview','effectiveAlpha','section','sectionAlpha']:[];
        abort_unless(array_keys($report['outputs'])===$expected||$this->sameKeys($report['outputs'],$expected),422);
        foreach($report['inputHashes']??[] as $absolute=>$sha)abort_unless(is_file($absolute)&&!is_link($absolute)&&str_starts_with($absolute,$root.DIRECTORY_SEPARATOR)&&hash_file('sha256',$absolute)===$sha,422);
        foreach($report['outputs']??[] as $a){abort_unless(basename($a['file'])===$a['file'],422);$this->asset($item->project_id,$relative.'/'.$a['file'],$a['sha256'],192*1024*1024);}
        $this->validate($snapshot);
        return ['directory'=>$relative,'reportPath'=>$path,'reportSha256'=>hash_file('sha256',$disk->path($path)),'requestSha256'=>$input['sha256'],'report'=>$report];
    }
    private function sameKeys(array $value,array $keys): bool {$actual=array_keys($value);sort($actual);sort($keys);return $actual===$keys;}
    private function environment(): array
    {$env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);return array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1','MKL_NUM_THREADS'=>'1','NUMEXPR_NUM_THREADS'=>'1']);}
}
