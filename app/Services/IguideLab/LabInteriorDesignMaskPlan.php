<?php
namespace App\Services\IguideLab;
use Illuminate\Support\Str;
use App\Services\IguideLab\LabInteriorDesignProtocol as V;

/** One explicit material/foreground identity per SAM call. Returned order is never identity. */
class LabInteriorDesignMaskPlan
{
    public function bind(string $requestId,string $role,array $target,string $inputSha,string $assessmentSha,string $contextSha): array
    {
        $id=$target['id'];$selection=$target['maskPlan'];
        $plan=['stepKey'=>$role.'-'.$id,'maskRole'=>$role,'targetId'=>$id,'requestId'=>$requestId,'reservationState'=>'server_reserved',
            'model'=>LabMachineSegmentationTransport::MODEL,'inputSha256'=>$inputSha,'width'=>2048,'height'=>2048,
            'payload'=>['prompt'=>$selection['prompt'],'box_prompts'=>[['x_min'=>$selection['box'][0],'y_min'=>$selection['box'][1],'x_max'=>$selection['box'][2],'y_max'=>$selection['box'][3],'object_id'=>1]],
                'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,'max_masks'=>1,'include_scores'=>true,'include_boxes'=>true],
            'expectedIdentities'=>[$id],'selectionOrigin'=>'machine','assessmentProposalSha256'=>$assessmentSha,'contextSha256'=>$contextSha,
            'maximumPostAttempts'=>1,'automaticRetry'=>false,'semanticClassVerified'=>false];
        $plan['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($plan);$this->validate($plan,$requestId);return $plan;
    }
    public function validate(array $plan,string $requestId): void
    {
        V::keys($plan,['stepKey','maskRole','targetId','requestId','reservationState','model','inputSha256','width','height','payload','expectedIdentities','selectionOrigin',
            'assessmentProposalSha256','contextSha256','maximumPostAttempts','automaticRetry','semanticClassVerified','inputFingerprint']);
        abort_unless(Str::isUuid($requestId)&&$plan['requestId']===$requestId&&$plan['reservationState']==='server_reserved'&&$plan['model']===LabMachineSegmentationTransport::MODEL
            &&[$plan['width'],$plan['height']]===[2048,2048]&&$plan['selectionOrigin']==='machine'&&$plan['maximumPostAttempts']===1&&$plan['automaticRetry']===false&&$plan['semanticClassVerified']===false,422);
        $role=$plan['maskRole'];$id=$plan['targetId'];
        abort_unless(is_string($role)&&in_array($role,['source-material','candidate-material','source-foreground'],true)&&is_string($id)
            &&preg_match($role==='source-foreground'?'/\Aforeground_[1-5]\z/D':'/\Amaterial_[1-5]\z/D',$id)
            &&$plan['stepKey']===$role.'-'.$id&&$plan['expectedIdentities']===[$id],422,'Invalid material mask identity.');
        foreach(['inputSha256','assessmentProposalSha256','contextSha256','inputFingerprint'] as $key)V::hash($plan[$key]);
        $unhashed=$plan;unset($unhashed['inputFingerprint']);abort_unless($plan['inputFingerprint']===LabMachineSegmentationPlan::canonicalHash($unhashed),422);
        $p=$plan['payload'];V::keys($p,['prompt','box_prompts','apply_mask','output_format','return_multiple_masks','max_masks','include_scores','include_boxes']);V::text($p['prompt'],160);
        abort_unless($p['apply_mask']===false&&$p['output_format']==='png'&&$p['return_multiple_masks']===true&&$p['max_masks']===1&&$p['include_scores']===true&&$p['include_boxes']===true
            &&is_array($p['box_prompts'])&&array_is_list($p['box_prompts'])&&count($p['box_prompts'])===1,422);
        $box=$p['box_prompts'][0];V::keys($box,['x_min','y_min','x_max','y_max','object_id']);abort_unless($box['object_id']===1,422);
        V::box([$box['x_min'],$box['y_min'],$box['x_max'],$box['y_max']],$role!=='source-foreground');
    }
    public function payload(array $plan,string $bytes): array
    {
        $this->validate($plan,$plan['requestId']??'');abort_unless(strlen($bytes)<=30*1024*1024&&hash('sha256',$bytes)===$plan['inputSha256'],422);
        $size=@getimagesizefromstring($bytes);abort_unless($size&&[$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);
        $im=@imagecreatefromstring($bytes);abort_unless($im!==false,422);imagedestroy($im);return ['image_url'=>'data:image/png;base64,'.base64_encode($bytes)]+$plan['payload'];
    }
    public function associate(array $plan,array $receipt): array
    {
        $this->validate($plan,$plan['requestId']);V::keys($receipt,['requestId','providerRequestId','model','inputFingerprint','inputSha256','rawResponseSha256','status','masks']);
        foreach(['requestId','model','inputFingerprint','inputSha256'] as $key)abort_unless($receipt[$key]===$plan[$key],422,'Interior mask receipt association changed.');
        V::hash($receipt['rawResponseSha256']);abort_unless(is_string($receipt['providerRequestId'])&&$receipt['providerRequestId']!=='',422);
        $held=['status'=>'needs_review','reviewReasons'=>['MATERIAL_MASK_ASSOCIATION_UNCERTAIN'],'associations'=>[]];
        if($receipt['status']!=='completed'||!is_array($receipt['masks'])||!array_is_list($receipt['masks'])||count($receipt['masks'])!==1)return $held;
        $mask=$receipt['masks'][0];V::keys($mask,['sha256','width','height','bounds','positivePixels']);V::hash($mask['sha256']);
        if([$mask['width'],$mask['height']]!==[2048,2048]||!is_int($mask['positivePixels'])||$mask['positivePixels']<128||$mask['positivePixels']>700000)return $held;
        $b=$mask['bounds'];if(!is_array($b)||!array_is_list($b)||count($b)!==4)return $held;
        foreach($b as $number)if(!is_int($number))return $held;
        if($b[0]<0||$b[1]<0||$b[2]>2048||$b[3]>2048||$b[0]>=$b[2]||$b[1]>=$b[3])return $held;
        if($plan['maskRole']!=='source-foreground'&&($b[0]<205||$b[1]<205||$b[2]>1843||$b[3]>1843))return $held;
        $s=$plan['payload']['box_prompts'][0];$selected=[$s['x_min'],$s['y_min'],$s['x_max']+1,$s['y_max']+1];
        $overlap=max(0,min($b[2],$selected[2])-max($b[0],$selected[0]))*max(0,min($b[3],$selected[3])-max($b[1],$selected[1]));
        $area=($b[2]-$b[0])*($b[3]-$b[1]);$wanted=($selected[2]-$selected[0])*($selected[3]-$selected[1]);$iou=$overlap/max(1,$area+$wanted-$overlap);
        if($mask['positivePixels']>$area||$iou<.55||$b[0]<$selected[0]-32||$b[1]<$selected[1]-32||$b[2]>$selected[2]+32||$b[3]>$selected[3]+32)return $held;
        return ['status'=>'ready_for_material_support','reviewReasons'=>[],'associations'=>[['targetId'=>$plan['targetId'],'role'=>$plan['maskRole'],'maskIndex'=>0,'maskSha256'=>$mask['sha256'],
            'selectionBoxIoU'=>$iou,'positivePixels'=>$mask['positivePixels'],'semanticClassVerified'=>false]],'humanReviewed'=>false,'automaticAcceptance'=>false];
    }
}
