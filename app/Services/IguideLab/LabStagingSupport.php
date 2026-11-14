<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabMachineSegmentation,IguideLabStagingAssessment,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Machine-only support; a model mask never becomes a user-reviewed mask. */
class LabStagingSupport
{
    public function snapshot(): array
    {
        $manifest=(string) config('iguide-lab.staging_support_runtime_manifest');
        return ['version'=>'automatic-staging-support/1','adapterSha256'=>hash_file('sha256',__FILE__),
            'coreSha256'=>hash_file('sha256',resource_path('iguide-lab/staging_support.py')),
            'workerSha256'=>hash_file('sha256',resource_path('iguide-lab/staging_support_worker.py')),
            'runtimeManifestSha256'=>is_file($manifest)?hash_file('sha256',$manifest):null];
    }
    public function ready(): bool
    {
        try {
            $path=(string)config('iguide-lab.staging_support_runtime_manifest'); $python=(string)config('iguide-lab.staging_support_python_path');
            abort_unless(is_file($path)&&!is_link($path)&&filesize($path)<=65536&&is_executable($python),503);
            $m=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR); $s=$this->snapshot();
            abort_unless(($m['validationStatus']??null)==='passed'&&($m['pythonPath']??null)===$python
                &&($m['pythonVersion']??null)==='3.12.3'&&($m['numpyVersion']??null)==='2.5.4'
                &&($m['pillowVersion']??null)==='13.0.0'&&($m['opencvVersion']??null)==='5.0.0'
                &&preg_match('/\A[a-f0-9]{64}\z/D',$m['opencvBuildSha256']??''),503);
            foreach(['adapterSha256','coreSha256','workerSha256'] as $key) abort_unless(($m['validated'.ucfirst($key)]??null)===$s[$key],503);
            return true;
        } catch(\Throwable) { return false; }
    }
    public function validate(array $snapshot): void
    {
        abort_unless(LabAnalysisRecipes::hash($snapshot)===LabAnalysisRecipes::hash($this->snapshot())&&$this->ready(),503,'Validated staging support changed or is unavailable.');
    }
    public function derive(IguideLabWorkflowItem $item,IguideLabStagingAssessment $assessment,array $snapshot): array
    {
        $this->validate($snapshot); $service=app(LabStagingAssessments::class); $bridge=app(LabStagingAssessmentBridge::class);
        $disk=Storage::disk(LabService::DISK); $plans=$receipts=$masks=[];
        foreach(['source-floor','candidate-furniture'] as $key) {
            $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('step_key',$key.'-'.$assessment->id)->sole();
            $trusted=$service->forSegmentation($step); // Revalidate native, cut, candidate, context, recipe and cancellation.
            abort_unless($trusted['assessmentId']===$assessment->id,422);
            $record=IguideLabMachineSegmentation::where('project_id',$item->project_id)->where('step_id',$step->id)->sole();
            abort_unless($record->status==='completed'&&$record->id===$step->child_id&&$record->request_id===$step->request_id
                &&$record->request_hash===$step->input_sha256&&$record->data['plan']===$trusted['boundPlan'],422,'Segmentation receipt binding changed.');
            $receipt=$record->result['receipt']; $association=$service->associate($step,$receipt);
            abort_unless($association['status']==='ready_for_support_validation'
                &&LabAnalysisRecipes::hash($association)===LabAnalysisRecipes::hash($record->result['association']),422);
            $plans[]=$trusted['boundPlan']; $receipts[]=$receipt;
            foreach($association['associations'] as $bound) {
                $artifact=collect($record->result['artifacts'])->firstWhere('index',$bound['maskIndex']);
                $expected='iguide-lab/'.$item->project_id.'/machine-segmentations/'.$record->id.'/mask-'.$bound['maskIndex'].'.png';
                abort_unless($artifact&&$artifact['path']===$expected&&$artifact['sha256']===$bound['maskSha256'],422);
                $path=app(LabStagingAssessmentContext::class)->file($item->project_id,$expected,$bound['maskSha256'],LabMachineSegmentationTransport::MAX_MASK_BYTES);
                $pixels=app(LabMachineMaskPixels::class)->describe(file_get_contents($path));
                abort_unless($pixels===$receipt['masks'][$bound['maskIndex']],422,'Decoded support mask changed.');
                $masks[$bound['identity']]=['image'=>['path'=>$path,'sha256'=>$bound['maskSha256']],
                    'evidence'=>['inputSha256'=>$bound['sourceSha256'],'maskSha256'=>$bound['maskSha256'],'generationOrigin'=>'model_segmentation',
                        'selectionOrigin'=>'machine','providerRequestId'=>$bound['providerRequestId']]];
            }
        }
        $base=$assessment->data['base']; $final=$bridge->run($item->project_id,$base.'/finalize-support-request.json',[
            'schemaVersion'=>1,'operation'=>'finalize-support','outputDirectory'=>$disk->path($base.'/support-authority'),
            'assessment'=>$assessment->data['resultAsset'],'plans'=>$bridge->json($base.'/final-support-plans.json',$plans),
            'receipts'=>$bridge->json($base.'/final-support-receipts.json',$receipts)],$assessment->data['recipe']);
        abort_unless($final['status']==='ready_for_support_validation',422,'Machine support authority requires review.');
        $supportAssessment=$bridge->output($item->project_id,$final,'support-assessment');
        $context=$assessment->data['contextSnapshot'];
        $asset=function(string $id)use($context,$item){$a=collect($context['assets'])->firstWhere('id',$id); abort_unless($a,422);
            return ['path'=>app(LabStagingAssessmentContext::class)->file($item->project_id,$a['path'],$a['sha256'],32*1024*1024),'sha256'=>$a['sha256']];};
        $out='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/support-'.$assessment->id;
        $mask=function(string $id,string $role)use($masks,$bridge,$out){abort_unless(isset($masks[$id]),422);
            return ['id'=>$id,'role'=>$role,'image'=>$masks[$id]['image'],'evidence'=>$bridge->json($out.'-'.$id.'-evidence.json',$masks[$id]['evidence'])];};
        $g=$context['geometry']; $geometry=['path'=>app(LabStagingAssessmentContext::class)->file($item->project_id,$g['path'],$g['sha256'],65536),'sha256'=>$g['sha256']];
        $pose=array_intersect_key($context['context']['pose'],array_flip(['yaw','pitch','hFov']));
        $request=['schemaVersion'=>1,'operation'=>'derive-staging-support','sourceCut'=>$asset('source_cut'),'candidateCut'=>$asset('candidate_cut'),
            'sourceContext'=>$asset('parent_overview'),'sourceGeometry'=>$geometry,'assessment'=>$supportAssessment,
            'sourceFloor'=>$mask('floor','source_floor'),'candidateObjects'=>array_map(fn($id)=>$mask($id,'candidate_object'),['sofa','chair','coffee_table']),
            'sourceOccluders'=>[],'sourceProtectedFeatures'=>[],'pose'=>$pose,'outputDirectory'=>$disk->path($out)];
        $saved=$bridge->json($out.'-request.json',$request);
        $env=array_fill_keys(array_keys(array_merge($_SERVER,$_ENV,getenv()?:[])),false);
        $env=array_merge($env,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONNOUSERSITE'=>'1','OMP_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1']);
        $process=new Process([(string)config('iguide-lab.staging_support_python_path'),resource_path('iguide-lab/staging_support_worker.py'),$saved['path'],
            '--allowed-root',$disk->path('iguide-lab/'.$item->project_id)],null,$env,null,100);
        $process->run(); abort_unless($process->isSuccessful(),422,'Automatic support validation failed.');
        $path=$out.'/report.json'; abort_unless($disk->exists($path)&&!is_link($disk->path($path))&&filesize($disk->path($path))<=1024*1024,422);
        $report=json_decode($disk->get($path),true,flags:JSON_THROW_ON_ERROR);
        abort_unless(in_array($report['status']??null,['needs_review','ready_for_unreviewed_composition'],true)&&($report['automaticAcceptance']??true)===false
            &&($report['providerCalls']??-1)===0&&($report['nativeCompositionPerformed']??true)===false
            &&$report['sourceCutSha256']===$request['sourceCut']['sha256']&&$report['candidateCutSha256']===$request['candidateCut']['sha256']
            &&$report['nativeSourceSha256']===$item->source['sha256']&&$report['sourceGeometrySha256']===$geometry['sha256'],422);
        $this->validate($snapshot);
        return ['report'=>$report,'reportPath'=>$path,'reportSha256'=>hash_file('sha256',$disk->path($path)),'directory'=>$out,
            'assessmentId'=>$assessment->id,'assessmentSha256'=>$assessment->result_sha256];
    }
}
