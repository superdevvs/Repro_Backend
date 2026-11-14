<?php
namespace App\Services\IguideLab;

use App\Models\{IguideLabCase,IguideLabImport,IguideLabRoomAssessment,IguideLabRun,IguideLabSection,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Original-only classifier preparation reuses the unchanged seven-view byte transform. */
class LabInteriorRoomContext extends LabInteriorDesignContext
{
    public function snapshot(): array
    {
        return parent::snapshot()+['roomContextSha256'=>hash_file('sha256',__FILE__),
            'registrySha256'=>hash_file('sha256',__DIR__.'/LabInteriorRoomProfiles.php')];
    }

    public function capture(IguideLabWorkflowItem $item,string $phase,array $parents): array
    {
        if($phase!=='route') return $this->furnishing($item,$phase,$parents);
        abort_unless($parents===[],422,'Unsupported Interior room assessment context.');
        $bundle=parent::original($item,[]);$metadata=$this->metadata($item);
        $bundle['context']['version']=LabInteriorRoomRouteProtocol::VERSION;
        $bundle['context']['roomMetadata']=$metadata['value'];
        $bundle['context']['metadataSha256']=LabMachineSegmentationPlan::canonicalHash($metadata['value']);
        $bundle['metadataAuthority']=$metadata['authority'];
        $bundle['implementation']=$this->snapshot();LabInteriorRoomRouteProtocol::context($bundle['context']);
        return $bundle;
    }

    private function furnishing(IguideLabWorkflowItem $item,string $phase,array $parents): array
    {
        $service=app(LabInteriorRoomAssessments::class);$protocol=$service->protocol($phase);
        $route=app(LabInteriorRoomRoutes::class)->verify($item);
        abort_unless(LabInteriorRoomProfiles::get($route['profileId'])['kind']==='new_furnishings',422);
        $routeRecord=$service->completed($item,$route['assessmentId'],'route');$original=$routeRecord->data['contextSnapshot'];
        $native=app(LabWorkflowSources::class)->verify($item->project_id,$item->source);
        $c=['version'=>$protocol::VERSION,'phase'=>$phase,'nativeSourceSha256'=>$native->source_sha256,'sourceSha256'=>$native->source_sha256,
            'preparedSourceSha256'=>$original['context']['preparedSourceSha256'],'sourceAuthoritySha256'=>$original['context']['sourceAuthoritySha256'],
            'intentSha256'=>$original['context']['intentSha256'],'style'=>$item->style,'instructions'=>$item->instructions,
            'route'=>['binding'=>$route,'result'=>$routeRecord->result],'framing'=>null,'layout'=>null,'candidateRunId'=>null,'candidateSha256'=>null,'views'=>$original['context']['views']];
        if($phase==='framing') {
            LabInteriorDesignProtocol::keys($parents,['routeId']);abort_unless($parents['routeId']===$routeRecord->id,422);$protocol::context($c);
            return ['context'=>$c,'assets'=>$original['assets'],'routeId'=>$routeRecord->id,'implementation'=>$this->snapshot()];
        }
        if($phase==='layout') {
            LabInteriorDesignProtocol::keys($parents,['framingId','sectionId']);$framing=$service->completed($item,$parents['framingId'],'framing');
            abort_unless($framing->result['status']==='framing_candidate'&&$framing->data['contextSnapshot']['routeId']===$routeRecord->id,422);
            $section=IguideLabSection::where('project_id',$item->project_id)->where('status','completed')->findOrFail($parents['sectionId']);
            $step=$this->ownedChild($item,'section',$section->id);
            abort_unless($section->case_id===$native->id&&$section->source_sha256===$native->source_sha256&&$section->request_id===$step->request_id
                &&($step->input['framingId']??null)===$framing->id&&($step->input['routeSha256']??null)===LabMachineSegmentationPlan::canonicalHash($route),422,'Room section is not owned by this original framing.');
            foreach(['yaw','pitch','hFov'] as $key)abort_unless((float)$section->view[$key]===(float)$framing->result['view'][$key],422);
            $cut=IguideLabCase::where('project_id',$item->project_id)->findOrFail($section->derived_case_id);$sourceContext=app(LabAnalysisSourceContext::class)->capture($cut);
            abort_unless($sourceContext!==null&&$sourceContext['binding']['nativeCaseId']===$native->id,422);
            $disk=Storage::disk(LabService::DISK);$base='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/room-cut-'.$section->id;
            $png=self::prepared($disk->get($cut->source_path));$sha=hash('sha256',$png);$size=getimagesizefromstring($png);
            abort_unless([$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);$this->put($base.'/source.png',$png);
            $c['sourceSha256']=$cut->source_sha256;$c['preparedSourceSha256']=$sha;$c['framing']=$this->receipt($framing);
            $c['views']=[['id'=>'source','sha256'=>$sha,'width'=>2048,'height'=>2048]];$assets=[['id'=>'source','path'=>$base.'/source.png','sha256'=>$sha]];
            foreach(array_slice($original['assets'],0,5) as $i=>$asset){$this->file($item->project_id,$asset['path'],$asset['sha256']);$id=$i===0?'parent_source':'parent_'.['front','right','back','left'][$i-1];
                $c['views'][]=['id'=>$id,'sha256'=>$asset['sha256'],'width'=>$i===0?2048:1024,'height'=>1024];$assets[]=['id'=>$id,'path'=>$asset['path'],'sha256'=>$asset['sha256']];}
            $geometry=['version'=>'interior-furnishing-source-geometry/1','nativeSourceSha256'=>$native->source_sha256,'sourceCutSha256'=>$cut->source_sha256,'preparedSourceSha256'=>$sha,
                'width'=>2048,'height'=>2048,'projection'=>'rectilinear','view'=>array_map('floatval',$framing->result['view']),'convention'=>'raw-yaw-zero-panorama-centre-positive-right-pitch-up'];
            $bytes=json_encode($geometry,JSON_THROW_ON_ERROR);$this->put($base.'/geometry.json',$bytes);$protocol::context($c);
            return ['context'=>$c,'assets'=>$assets,'routeId'=>$routeRecord->id,'framingId'=>$framing->id,'sectionId'=>$section->id,'cutCaseId'=>$cut->id,'nativeCaseId'=>$native->id,
                'sourceContextSnapshot'=>$sourceContext,'geometry'=>['path'=>$base.'/geometry.json','sha256'=>hash('sha256',$bytes)],'implementation'=>$this->snapshot()];
        }
        abort_unless($phase==='candidate',422);LabInteriorDesignProtocol::keys($parents,['layoutId','runId']);$layout=$service->completed($item,$parents['layoutId'],'layout');
        abort_unless($layout->result['status']==='ready_for_image'&&$layout->data['contextSnapshot']['routeId']===$routeRecord->id,422);
        $bundle=$layout->data['contextSnapshot'];$c=$bundle['context'];$c['version']=$protocol::VERSION;$c['phase']=$phase;$c['layout']=$this->receipt($layout);
        $run=IguideLabRun::where('project_id',$item->project_id)->where('status','completed')->findOrFail($parents['runId']);$step=$this->ownedChild($item,'image',$run->id);
        abort_unless($run->request_id===$step->request_id&&$run->case_id===$bundle['cutCaseId']&&($step->input['roomPlanId']??null)===$layout->id
            &&($run->options['sourceMachinePlan']['version']??null)===LabRoomFurnishingImageDraft::VERSION&&($run->options['sourceMachinePlan']['id']??null)===$layout->id
            &&$run->preset==='interior-design'&&$run->style===$item->style,422,'Candidate does not belong to the selected room profile.');
        app(LabRoomFurnishingImageDraft::class)->verifyRun($run,IguideLabCase::where('project_id',$item->project_id)->findOrFail($run->case_id));
        $path='iguide-lab/'.$item->project_id.'/runs/'.$run->id.'.png';abort_unless($run->output_path===$path&&is_string($run->output_sha256),422);
        $file=$this->file($item->project_id,$path,$run->output_sha256,32*1024*1024);$size=getimagesize($file);abort_unless([$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);
        $c['candidateRunId']=$run->id;$c['candidateSha256']=$run->output_sha256;
        array_splice($c['views'],1,0,[['id'=>'candidate','sha256'=>$run->output_sha256,'width'=>2048,'height'=>2048]]);$assets=$bundle['assets'];
        array_splice($assets,1,0,[['id'=>'candidate','path'=>$path,'sha256'=>$run->output_sha256]]);$protocol::context($c);
        return ['context'=>$c,'assets'=>$assets,'routeId'=>$routeRecord->id,'layoutId'=>$layout->id,'candidateRunId'=>$run->id,
            'sectionId'=>$bundle['sectionId'],'cutCaseId'=>$bundle['cutCaseId'],'nativeCaseId'=>$native->id,'geometry'=>$bundle['geometry'],'implementation'=>$this->snapshot()];
    }

    public function receipt(IguideLabRoomAssessment $record): array
    {return ['id'=>$record->id,'resultSha256'=>$record->result_sha256,'responseSha256'=>$record->data['providerReceipt']['response']['sha256'],'result'=>$record->result];}
    private function ownedChild(IguideLabWorkflowItem $item,string $kind,string $id): IguideLabWorkflowStep
    {
        $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('kind',$kind)->where('child_id',$id)->sole();
        abort_unless($step->input_sha256===LabAnalysisRecipes::hash($step->input),422);return $step;
    }

    private function metadata(IguideLabWorkflowItem $item): array
    {
        $source=$item->source;app(LabWorkflowSources::class)->verify($item->project_id,$source);
        $value=array_fill_keys(['roomId','roomName','floorId','floorName','panoId'],null);
        if(($source['authority']['kind']??null)!=='scan-import') return ['value'=>$value,'authority'=>null];
        $import=IguideLabImport::where('project_id',$item->project_id)->where('status','completed')->findOrFail($source['authority']['importId']);
        $mapping=collect($import->sources)->firstWhere('caseId',$source['caseId']);
        abort_unless(is_array($mapping)&&($mapping['sourceSha256']??null)===$source['sha256']
            &&($mapping['importSourceKey']??null)===$source['authority']['importSourceKey']
            &&[$mapping['width']??null,$mapping['height']??null]===[$source['width'],$source['height']],422,'Original room metadata no longer belongs to its creating import.');
        foreach(array_keys($value) as $key) {
            $entry=$mapping[$key]??null;
            abort_unless($entry===null||(is_string($entry)&&$entry!==''&&mb_strlen($entry)<=220),422,'Invalid retained room metadata.');
            $value[$key]=$entry;
        }
        return ['value'=>$value,'authority'=>['version'=>'completed-import-room-metadata/1','importId'=>$import->id,
            'importSourceKey'=>$mapping['importSourceKey'],'mappingSha256'=>LabMachineSegmentationPlan::canonicalHash($mapping)]];
    }
}
