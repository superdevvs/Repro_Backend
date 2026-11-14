<?php
namespace App\Services\IguideLab;
use App\Services\IguideLab\LabInteriorDesignProtocol as V;
use Illuminate\Support\Str;

/** Original floor or actual profile objects. Returned mask order is never identity. */
class LabRoomFurnishingMaskPlan
{
    public const PROTOCOL='automatic-interior-furnishing-segmentation/1';
    public function bind(string $requestId,string $role,string $profileId,array $selections,string $sourceSha,string $assessmentSha,string $contextSha): array
    {
        $expected=$role==='source-floor'?['floor']:LabInteriorRoomProfiles::get($profileId)['roles'];
        $boxes=[];foreach($expected as $i=>$id){$b=$selections[$i]['box'];$boxes[]=['x_min'=>$b[0],'y_min'=>$b[1],'x_max'=>$b[2],'y_max'=>$b[3],'object_id'=>$i+1];}
        $plan=['stepKey'=>$role,'profileId'=>$profileId,'requestId'=>$requestId,'reservationState'=>'server_reserved','model'=>LabMachineSegmentationTransport::MODEL,
            'inputSha256'=>$sourceSha,'width'=>2048,'height'=>2048,'payload'=>['prompt'=>$role==='source-floor'?'floor':'furniture','box_prompts'=>$boxes,
                'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,'max_masks'=>count($expected),'include_scores'=>true,'include_boxes'=>true],
            'expectedIdentities'=>$expected,'selectionOrigin'=>'machine','assessmentProposalSha256'=>$assessmentSha,'contextSha256'=>$contextSha,
            'maximumPostAttempts'=>1,'automaticRetry'=>false,'semanticClassVerified'=>false];
        $plan['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($plan);$this->validate($plan,$requestId);return $plan;
    }
    public function validate(array $plan,string $requestId): void
    {
        V::keys($plan,['stepKey','profileId','requestId','reservationState','model','inputSha256','width','height','payload','expectedIdentities','selectionOrigin','assessmentProposalSha256','contextSha256','maximumPostAttempts','automaticRetry','semanticClassVerified','inputFingerprint']);
        $profile=LabInteriorRoomProfiles::get($plan['profileId']);
        abort_unless($profile['kind']==='new_furnishings'&&Str::isUuid($requestId)&&$plan['requestId']===$requestId&&$plan['reservationState']==='server_reserved'
            &&$plan['model']===LabMachineSegmentationTransport::MODEL&&[$plan['width'],$plan['height']]===[2048,2048]
            &&$plan['selectionOrigin']==='machine'&&$plan['maximumPostAttempts']===1&&$plan['automaticRetry']===false&&$plan['semanticClassVerified']===false,422);
        $ids=match($plan['stepKey']){'source-floor'=>['floor'],'candidate-furniture'=>$profile['roles'],default=>abort(422,'Unsupported furnishing mask role.')};
        abort_unless($plan['expectedIdentities']===$ids,422);
        foreach(['inputSha256','assessmentProposalSha256','contextSha256','inputFingerprint'] as $key)V::hash($plan[$key]);
        $unhashed=$plan;unset($unhashed['inputFingerprint']);abort_unless($plan['inputFingerprint']===LabMachineSegmentationPlan::canonicalHash($unhashed),422);
        $p=$plan['payload'];V::keys($p,['prompt','box_prompts','apply_mask','output_format','return_multiple_masks','max_masks','include_scores','include_boxes']);
        abort_unless($p['prompt']===($plan['stepKey']==='source-floor'?'floor':'furniture')&&$p['apply_mask']===false&&$p['output_format']==='png'&&$p['return_multiple_masks']===true
            &&$p['max_masks']===count($ids)&&$p['include_scores']===true&&$p['include_boxes']===true&&is_array($p['box_prompts'])&&array_is_list($p['box_prompts'])&&count($p['box_prompts'])===count($ids),422);
        foreach($p['box_prompts'] as $i=>$box){V::keys($box,['x_min','y_min','x_max','y_max','object_id']);abort_unless($box['object_id']===$i+1,422);
            V::box([$box['x_min'],$box['y_min'],$box['x_max'],$box['y_max']],$plan['stepKey']!=='source-floor');}
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
        foreach(['requestId','model','inputFingerprint','inputSha256'] as $key)abort_unless($receipt[$key]===$plan[$key],422);
        V::hash($receipt['rawResponseSha256']);abort_unless(is_string($receipt['providerRequestId'])&&$receipt['providerRequestId']!=='',422);
        $held=['status'=>'needs_review','reviewReasons'=>['FURNISHING_MASK_IDENTITY_OR_COVERAGE_UNCERTAIN'],'associations'=>[]];
        if($receipt['status']!=='completed'||!is_array($receipt['masks'])||!array_is_list($receipt['masks'])||count($receipt['masks'])!==count($plan['expectedIdentities']))return $held;
        $associations=[];$used=[];
        foreach($plan['expectedIdentities'] as $i=>$id){$s=$plan['payload']['box_prompts'][$i];$selected=[$s['x_min'],$s['y_min'],$s['x_max']+1,$s['y_max']+1];$matches=[];
            foreach($receipt['masks'] as $index=>$mask){V::keys($mask,['sha256','width','height','bounds','positivePixels']);V::hash($mask['sha256']);
                if([$mask['width'],$mask['height']]!==[2048,2048]||!is_int($mask['positivePixels'])||$mask['positivePixels']<128||$mask['positivePixels']>4194304)return $held;
                $b=$mask['bounds'];if(!is_array($b)||!array_is_list($b)||count($b)!==4)return $held;foreach($b as $n)if(!is_int($n))return $held;
                if($b[0]<0||$b[1]<0||$b[2]>2048||$b[3]>2048||$b[0]>=$b[2]||$b[1]>=$b[3])return $held;
                if($plan['stepKey']!=='source-floor'&&($b[0]<205||$b[1]<205||$b[2]>1843||$b[3]>1843))return $held;
                $intersection=max(0,min($b[2],$selected[2])-max($b[0],$selected[0]))*max(0,min($b[3],$selected[3])-max($b[1],$selected[1]));
                $area=($b[2]-$b[0])*($b[3]-$b[1]);$selectedArea=($selected[2]-$selected[0])*($selected[3]-$selected[1]);$iou=$intersection/max(1,$area+$selectedArea-$intersection);
                if($mask['positivePixels']>$area)return $held;
                if($iou>=.55&&$b[0]>=$selected[0]-32&&$b[1]>=$selected[1]-32&&$b[2]<=$selected[2]+32&&$b[3]<=$selected[3]+32)$matches[]=[$index,$mask,$iou];}
            if(count($matches)!==1||isset($used[$matches[0][0]]))return $held;
            [$index,$mask,$iou]=$matches[0];$used[$index]=true;$associations[]=['targetId'=>$id,'role'=>$plan['stepKey'],'maskIndex'=>$index,'maskSha256'=>$mask['sha256'],
                'selectionBoxIoU'=>$iou,'positivePixels'=>$mask['positivePixels'],'semanticClassVerified'=>false];}
        return ['status'=>'ready_for_room_support','reviewReasons'=>[],'associations'=>$associations,'humanReviewed'=>false,'automaticAcceptance'=>false];
    }
}
