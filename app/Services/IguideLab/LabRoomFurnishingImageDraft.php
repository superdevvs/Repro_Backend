<?php
namespace App\Services\IguideLab;
use App\Models\{IguideLabCase,IguideLabRoomAssessment,IguideLabRun,IguideLabWorkflowItem,IguideLabWorkflowStep};
use Illuminate\Support\Facades\Storage;

/** Internal typed source layout. Public Interior/style remain unchanged. */
class LabRoomFurnishingImageDraft
{
    public const VERSION='machine-interior-furnishing-plan/1';
    public function prompt(IguideLabRoomAssessment $layout): string
    {
        abort_unless($layout->phase==='layout'&&$layout->result['status']==='ready_for_image',422);
        $c=$layout->data['contextSnapshot']['context'];$profile=LabInteriorRoomProfiles::get($c['route']['binding']['profileId']);
        $text='Furnish this exact original room in the selected Interior design style: '.$c['style'].'. '.$profile['scope']."\n"
            .'Use the complete original photograph as geometry, camera and finish authority. Keep every fixed finish, floor texture, fixture, opening, vent, hearth and existing object. Preserve the original lens and perspective; add no other objects or lighting fixtures. Do not copy any guide, label or box into the image. No architecture, floor replacement, broad cast shadows or reflection changes are authorized.';
        foreach($layout->result['objects'] as $object)$text.="\n".$object['id'].': '.$object['description'].' Approximate complete-object source box on the 2048-square view: '.json_encode($object['box'],JSON_THROW_ON_ERROR).'.';
        foreach($layout->result['styleFeatures'] as $feature)$text.="\nStyle ".$feature['kind'].' for '.implode(', ',$feature['objectIds']).': '.$feature['description'];
        if($profile['id']===LabInteriorRoomProfiles::BEDROOM)$text.="\nUse clearly visible low opaque platform/plinth bases for the bed and both bedside tables. Keep simple bedding on the bed. Keep the two tables on opposite sides at the head. Do not introduce narrow hidden legs, bedside lamps or loose accessories.";
        if($c['instructions']!=='')$text.="\nOriginal user instructions, within the verified profile: ".$c['instructions'];
        abort_unless(mb_strlen($text)<=4000,422,'The complete Interior intent exceeds the bounded image prompt.');return $text;
    }
    public function apply(IguideLabRoomAssessment $record,IguideLabCase $case,array $input,?array $invocation): array
    {
        abort_unless(config('iguide-lab.interior_room_routing_enabled',false)===true&&$invocation!==null,503);
        $item=IguideLabWorkflowItem::where('project_id',$case->project_id)->findOrFail($invocation['itemId']);$route=app(LabInteriorRoomRoutes::class)->verify($item);
        $record=app(LabInteriorRoomAssessments::class)->completed($item,$record->id,'layout');$b=$record->data['contextSnapshot'];
        abort_unless($record->result['status']==='ready_for_image'&&$invocation['kind']==='image'&&($input['roomPlanId']??null)===$record->id
            &&$input['modelId']==='gpt-image-2.6-sunburst'&&$input['preset']==='interior-design'&&$input['style']===$item->style
            &&$input['prompt']===$this->prompt($record)&&$case->id===$b['cutCaseId'],422,'Original furnishing layout or intent changed.');
        $options=$input['options']??[];LabInteriorDesignProtocol::keys($options,['sectionId','complexity','promptMode']);
        abort_unless($options['sectionId']===$b['sectionId']&&$options['complexity']==='low'&&$options['promptMode']==='machine-interior-furnishing-plan',422);
        $floor=app(LabRoomFurnishingMasks::class)->floor($item,$record->id);
        abort_unless($input['floorReceiptSha256']===LabMachineSegmentationPlan::canonicalHash($floor),422,'Original floor receipt changed before image.');
        $source=$b['assets'][0];$binding=$b['sourceContextSnapshot']['binding'];
        abort_unless($case->projection==='rectilinear'&&[$case->width,$case->height]===[2048,2048]&&$case->source_sha256===$b['context']['sourceSha256']
            &&($case->metadata['sectionId']??null)===$b['sectionId'],422);
        $bytes=Storage::disk(LabService::DISK)->get($case->source_path);abort_unless(hash('sha256',$bytes)===$case->source_sha256
            &&hash('sha256',LabInteriorRoomContext::prepared($bytes))===$source['sha256'],422);
        return ['version'=>self::VERSION,'id'=>$record->id,'stepId'=>$record->step_id,'requestId'=>$record->request_id,'resultSha256'=>$record->result_sha256,
            'sourceSha256'=>$case->source_sha256,'preparedSourceSha256'=>$source['sha256'],'nativeSourceSha256'=>$item->source['sha256'],
            'sourceGeometrySha256'=>$b['geometry']['sha256'],'sourceContext'=>$binding,'sourceContextBindingSha256'=>$b['sourceContextSnapshot']['bindingSha256'],
            'promptSha256'=>hash('sha256',$input['prompt']),'floorReceiptSha256'=>$input['floorReceiptSha256'],'route'=>$route,'profileId'=>$route['profileId'],
            'targetIds'=>array_column($record->result['objects'],'id'),'automaticGeneration'=>true,'operatorInterventions'=>0,'humanReviewed'=>false];
    }
    public function verifyRun(IguideLabRun $run,IguideLabCase $case): void
    {
        $saved=$run->options['sourceMachinePlan']??null;abort_unless(is_array($saved)&&($saved['version']??null)===self::VERSION,422);
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
