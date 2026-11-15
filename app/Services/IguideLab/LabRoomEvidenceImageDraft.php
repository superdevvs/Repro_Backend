<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabRoomAssessment,IguideLabRun,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Internal typed source layout. Public Interior/style remain unchanged. */
class LabRoomEvidenceImageDraft
{
    public const VERSION='machine-interior-evidence-furnishing-plan/1';
    public const LAYOUT_VERSION=LabRoomEvidenceLayoutProtocol::VERSION;
    public const CANDIDATE_VERSION=LabRoomEvidenceCandidateProtocol::VERSION;
    public function prompt(IguideLabRoomAssessment $layout,?IguideLabRoomAssessment $prior=null): string
    {
        abort_unless(($layout->result['version']??null)===static::LAYOUT_VERSION
            &&in_array($layout->result['draftDisposition']['disposition']??null,['standard_draft','evidence_draft'],true),422);
        $text=app(LabRoomFurnishingImageDraft::class)->prompt($layout);
        $text=str_replace('Keep every fixed finish, floor texture, fixture, opening, vent, hearth and existing object.',
            'Preserve original geometry, finishes, floor texture and fixed-feature positions outside legitimate added-object occlusion. Never remove, relocate, repaint or warp source features to make furniture fit. Natural opaque furniture overlap with ordinary wall finish, trim or an outlet is not architectural alteration. Keep doors, closets, stair approaches, hearths, vents and circulation clear, plus every explicit source-owned keep-visible or keep-clear exclusion. This does not certify hidden structure or access.',$text);
        if($layout->result['profileId']===LabInteriorRoomProfiles::LIVING)$text.="\nUse separated narrow legs/terminal supports on EACH sofa, table and chair, with 2 to 4 distinct visible floor-contact ends. No continuous floor-level plinth, broad pedestal or wide closed base. Do not invent hidden feet; retain ordinary natural self-occlusion only when sufficient visible supported ends remain.";
        $text.="\nKeep every complete object and visible contact inside pixels205..1842 on both axes. The original pose, floor witness and known exclusions stay fixed. This is a draft, not a clearance certificate.";
        foreach($layout->result['draftEvidence']['anchors'] as $anchor)$text.="\nSource anchor for ".$anchor['objectId'].': '.$anchor['sourceAnchor'].' Orientation: '.$anchor['orientation'].' Exclusions: '.$anchor['knownExclusions'];
        if($prior!==null){
            abort_unless(($prior->result['repairDecision']['eligible']??null)===true,422);
            $text.="\nOne correction of a completed failed draft, using this ORIGINAL image again. Preserve the original room; correct every listed observed issue. Do not copy generated floor or alter the original camera.";
            foreach($prior->result['correction']['defects'] as $d)$text.="\nCorrection ".$d['checkId'].' ('.implode(', ',$d['objectIds']).'): '.$prior->result['checks'][$d['checkId']]['reason'].' Original-source alternative: '.$d['sourceAlternative'];
        }
        abort_unless(mb_strlen($text)<=8000,422,'The complete bounded correction prompt exceeds8000characters.');return $text;
    }
    public function evidenceBinding(IguideLabWorkflowItem $item,IguideLabRoomAssessment $layout,int $ordinal,?string $priorId): array
    {
        abort_unless(LabRoomEvidenceDraftPolicy::selected($layout->data['recipe'])!==null&&in_array($ordinal,[1,2],true),422);
        $out=['version'=>LabRoomEvidenceDraftPolicy::VERSION,'ordinal'=>$ordinal,'sourceLayoutId'=>$layout->id,'sourceLayoutSha256'=>$layout->result_sha256,
            'priorCandidateId'=>null,'priorCandidateSha256'=>null,'priorRunId'=>null,'priorOutputSha256'=>null];
        if($ordinal===1){abort_unless($priorId===null,422);return $out;}
        abort_unless(is_string($priorId),422);$prior=app(LabInteriorRoomAssessments::class)->completed($item,$priorId,'candidate');$b=$prior->data['contextSnapshot'];
        abort_unless(($b['context']['imageOrdinal']??null)===1&&($b['layoutId']??null)===$layout->id
            &&($prior->result['version']??null)===static::CANDIDATE_VERSION&&$prior->result['status']==='needs_review'
            &&($prior->result['repairDecision']['eligible']??null)===true,422,'Only the exact completed first failure can reserve one correction.');
        $run=IguideLabRun::where('project_id',$item->project_id)->where('status','completed')->findOrFail($b['candidateRunId']);
        $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->where('step_key','room-image')->where('child_id',$run->id)->sole();
        abort_unless($step->request_id===$run->request_id&&($step->input['roomEvidenceDraft']['ordinal']??null)===1
            &&$run->output_sha256===$b['context']['candidateSha256'],422);
        return array_replace($out,['priorCandidateId'=>$prior->id,'priorCandidateSha256'=>$prior->result_sha256,'priorRunId'=>$run->id,'priorOutputSha256'=>$run->output_sha256]);
    }
    public function apply(IguideLabRoomAssessment $record,IguideLabCase $case,array $input,?array $invocation): array
    {
        abort_unless(config('iguide-lab.interior_room_routing_enabled',false)===true&&$invocation!==null,503);
        $item=IguideLabWorkflowItem::where('project_id',$case->project_id)->findOrFail($invocation['itemId']);$route=app(LabInteriorRoomRoutes::class)->verify($item);
        $record=app(LabInteriorRoomAssessments::class)->completed($item,$record->id,'layout');$b=$record->data['contextSnapshot'];
        $saved=$input['roomEvidenceDraft']??[];LabInteriorDesignProtocol::keys($saved,['version','ordinal','sourceLayoutId','sourceLayoutSha256','priorCandidateId','priorCandidateSha256','priorRunId','priorOutputSha256']);
        $draft=$this->evidenceBinding($item,$record,$saved['ordinal'],$saved['priorCandidateId']);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($saved)===LabMachineSegmentationPlan::canonicalHash($draft),422,'Correction parent or original source layout drifted.');
        $prior=$draft['priorCandidateId']===null?null:app(LabInteriorRoomAssessments::class)->completed($item,$draft['priorCandidateId'],'candidate');
        $step=IguideLabWorkflowStep::where('project_id',$item->project_id)->where('item_id',$item->id)->findOrFail($invocation['stepId']);
        abort_unless($step->step_key===($draft['ordinal']===1?'room-image':'room-image-2')&&$step->kind==='image',422);
        abort_unless($record->result['status']==='ready_for_image'&&$invocation['kind']==='image'&&($input['roomPlanId']??null)===$record->id
            &&$input['modelId']==='gpt-image-2.6-sunburst'&&$input['preset']==='interior-design'&&$input['style']===$item->style
            &&$input['prompt']===$this->prompt($record,$prior)&&$case->id===$b['cutCaseId'],422,'Original furnishing layout or intent changed.');
        $options=$input['options']??[];LabInteriorDesignProtocol::keys($options,['sectionId','complexity','promptMode']);
        abort_unless($options['sectionId']===$b['sectionId']&&$options['complexity']==='low'&&$options['promptMode']==='machine-interior-furnishing-plan',422);
        $floor=app(LabRoomFurnishingMasks::class)->floor($item,$record->id);
        abort_unless($input['floorReceiptSha256']===LabMachineSegmentationPlan::canonicalHash($floor),422,'Original floor receipt changed before image.');
        $source=$b['assets'][0];$binding=$b['sourceContextSnapshot']['binding'];
        abort_unless($case->projection==='rectilinear'&&[$case->width,$case->height]===[2048,2048]&&$case->source_sha256===$b['context']['sourceSha256']
            &&($case->metadata['sectionId']??null)===$b['sectionId'],422);
        $bytes=Storage::disk(LabService::DISK)->get($case->source_path);abort_unless(hash('sha256',$bytes)===$case->source_sha256
            &&hash('sha256',LabInteriorRoomContext::prepared($bytes))===$source['sha256'],422);
        return ['version'=>static::VERSION,'id'=>$record->id,'stepId'=>$record->step_id,'requestId'=>$record->request_id,'resultSha256'=>$record->result_sha256,
            'sourceSha256'=>$case->source_sha256,'preparedSourceSha256'=>$source['sha256'],'nativeSourceSha256'=>$item->source['sha256'],
            'sourceGeometrySha256'=>$b['geometry']['sha256'],'sourceContext'=>$binding,'sourceContextBindingSha256'=>$b['sourceContextSnapshot']['bindingSha256'],
            'evidenceDraft'=>$draft,'promptSha256'=>hash('sha256',$input['prompt']),'floorReceiptSha256'=>$input['floorReceiptSha256'],'route'=>$route,'profileId'=>$route['profileId'],
            'targetIds'=>array_column($record->result['objects'],'id'),'automaticGeneration'=>true,'operatorInterventions'=>0,'humanReviewed'=>false];
    }
    public function verifyRun(IguideLabRun $run,IguideLabCase $case): void
    {
        $saved=$run->options['sourceMachinePlan']??null;abort_unless(is_array($saved)&&($saved['version']??null)===static::VERSION,422);
        $record=IguideLabRoomAssessment::where('project_id',$run->project_id)->findOrFail($saved['id']);
        $step=IguideLabWorkflowStep::where('project_id',$run->project_id)->where('request_id',$run->request_id)->where('kind','image')->sole();
        abort_unless(in_array($step->child_id,[null,$run->id],true)&&$step->input_sha256===LabAnalysisRecipes::hash($step->input)&&$run->prompt===$step->input['prompt']
            &&($run->options['promptMode']??null)==='machine-interior-furnishing-plan'&&$run->case_id===$case->id&&$run->model_id==='gpt-image-2.6-sunburst'
            &&$run->preset==='interior-design'&&$run->style===$step->input['style']&&($run->options['quality']??null)==='high'&&($run->options['complexity']??null)==='low'
            &&[$run->options['width']??null,$run->options['height']??null]===[2048,2048]&&empty($run->options['sourceAnalysis'])&&empty($run->options['analysisId'])
            &&empty($run->options['sectionMaskId'])&&empty($run->options['referenceCaseIds'])&&empty($run->options['referenceRunIds'])&&empty($run->options['referenceCompositeIds'])
            &&($run->options['sectionId']??null)===$step->input['options']['sectionId'],422,'Reserved furnishing image changed.');
        $current=$this->apply($record,$case,$step->input,$run->options['workflowInvocation']??null);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($saved)===LabMachineSegmentationPlan::canonicalHash($current),422,'Typed room source authority changed.');
    }
}
