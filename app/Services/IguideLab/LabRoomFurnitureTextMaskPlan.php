<?php
namespace App\Services\IguideLab;
use App\Services\IguideLab\LabInteriorDesignProtocol as V;
use Illuminate\Support\Str;

/**
 * Text-only "furniture" masks of one paid staging draft (no planner boxes, 1-3 masks).
 *
 * The masks are only support for compositing the draft; they claim no object identity, contact or
 * clearance. The provider may return several instances; the valid ones among 1-3 returned masks are
 * used and invalid fragments are dropped. With none left, the draft is still composited inside the
 * layout's planned region (see room_object_worker.py) and the item ends Needs review.
 */
class LabRoomFurnitureTextMaskPlan
{
    public const PROTOCOL='automatic-interior-furniture-text/1';
    public const ROLE='candidate-furniture-text';
    public const MAX_MASKS=3;
    public const MIN_PIXELS=128;
    private const PAYLOAD=['prompt'=>'furniture','box_prompts'=>[],'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,
        'max_masks'=>self::MAX_MASKS,'include_scores'=>true,'include_boxes'=>true];

    public function bind(string $requestId,string $profileId,string $sourceSha,string $runSha,string $contextSha): array
    {
        $plan=['stepKey'=>self::ROLE,'profileId'=>$profileId,'requestId'=>$requestId,'reservationState'=>'server_reserved','model'=>LabMachineSegmentationTransport::MODEL,
            'inputSha256'=>$sourceSha,'width'=>2048,'height'=>2048,'payload'=>self::PAYLOAD,'expectedIdentities'=>['furniture'],'selectionOrigin'=>'machine',
            'assessmentProposalSha256'=>$runSha,'contextSha256'=>$contextSha,'maximumPostAttempts'=>1,'automaticRetry'=>false,'semanticClassVerified'=>false,'textProtocol'=>self::PROTOCOL];
        $plan['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($plan);$this->validate($plan,$requestId);return $plan;
    }
    public function validate(array $plan,string $requestId): void
    {
        V::keys($plan,['stepKey','profileId','requestId','reservationState','model','inputSha256','width','height','payload','expectedIdentities','selectionOrigin','assessmentProposalSha256','contextSha256','maximumPostAttempts','automaticRetry','semanticClassVerified','textProtocol','inputFingerprint']);
        $profile=LabInteriorRoomProfiles::get($plan['profileId']);
        abort_unless($profile['kind']==='new_furnishings'&&$plan['stepKey']===self::ROLE&&$plan['textProtocol']===self::PROTOCOL&&Str::isUuid($requestId)&&$plan['requestId']===$requestId
            &&$plan['reservationState']==='server_reserved'&&$plan['model']===LabMachineSegmentationTransport::MODEL&&[$plan['width'],$plan['height']]===[2048,2048]
            &&$plan['expectedIdentities']===['furniture']&&$plan['selectionOrigin']==='machine'&&$plan['maximumPostAttempts']===1&&$plan['automaticRetry']===false&&$plan['semanticClassVerified']===false
            &&LabMachineSegmentationPlan::canonicalHash($plan['payload'])===LabMachineSegmentationPlan::canonicalHash(self::PAYLOAD),422,'The draft furniture mask is text-only.');
        foreach(['inputSha256','assessmentProposalSha256','contextSha256','inputFingerprint'] as $key)V::hash($plan[$key]);
        $unhashed=$plan;unset($unhashed['inputFingerprint']);abort_unless($plan['inputFingerprint']===LabMachineSegmentationPlan::canonicalHash($unhashed),422);
    }
    public function payload(array $plan,string $bytes): array
    {
        $this->validate($plan,$plan['requestId']??'');abort_unless(strlen($bytes)<=30*1024*1024&&hash('sha256',$bytes)===$plan['inputSha256'],422);
        $size=@getimagesizefromstring($bytes);abort_unless($size&&[$size[0],$size[1],$size['mime']]===[2048,2048,'image/png'],422);
        $im=@imagecreatefromstring($bytes);abort_unless($im!==false,422);imagedestroy($im);return ['image_url'=>'data:image/png;base64,'.base64_encode($bytes)]+$plan['payload'];
    }
    /** Runs only when the provider returned exactly one mask; 2-3 masks are judged by usable() on the retained receipt. */
    public function associate(array $plan,array $receipt): array
    {
        $this->validate($plan,$plan['requestId']);V::keys($receipt,['requestId','providerRequestId','model','inputFingerprint','inputSha256','rawResponseSha256','status','masks']);
        foreach(['requestId','model','inputFingerprint','inputSha256'] as $key)abort_unless($receipt[$key]===$plan[$key],422);
        V::hash($receipt['rawResponseSha256']);abort_unless(is_string($receipt['providerRequestId'])&&$receipt['providerRequestId']!=='',422);
        if($receipt['status']!=='completed'||!is_array($receipt['masks'])||!$this->usable($receipt['masks']))
            return ['status'=>'needs_review','reviewReasons'=>['DRAFT_FURNITURE_MASK_UNUSABLE'],'associations'=>[]];
        $associations=[];foreach($receipt['masks'] as $index=>$mask)$associations[]=['targetId'=>'furniture','role'=>self::ROLE,'maskIndex'=>$index,
            'maskSha256'=>$mask['sha256'],'positivePixels'=>$mask['positivePixels'],'semanticClassVerified'=>false];
        return ['status'=>'ready_for_room_support','reviewReasons'=>[],'associations'=>$associations,'humanReviewed'=>false,'automaticAcceptance'=>false];
    }
    /** @param list<array<string,mixed>> $masks receipt mask descriptors */
    public function usable(array $masks): bool
    {
        if(!array_is_list($masks)||count($masks)<1||count($masks)>self::MAX_MASKS)return false;
        foreach($masks as $mask)if(!$this->valid($mask))return false;
        return true;
    }
    /**
     * Indexes of the valid masks of a 1-3 mask receipt, in receipt order. Invalid fragments (for example
     * under 128 px) are dropped instead of making the whole set unusable; an empty list means no usable outline.
     * @return list<int>
     */
    public function validIndexes(array $masks): array
    {
        if(!array_is_list($masks)||count($masks)<1||count($masks)>self::MAX_MASKS)return [];
        return array_keys(array_filter($masks,fn($mask)=>$this->valid($mask)));
    }
    private function valid(mixed $mask): bool
    {
        if(!is_array($mask)||($mask['width']??null)!==2048||($mask['height']??null)!==2048||!is_int($mask['positivePixels']??null)
            ||$mask['positivePixels']<self::MIN_PIXELS||!is_string($mask['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$mask['sha256']))return false;
        $b=$mask['bounds']??null;if(!is_array($b)||!array_is_list($b)||count($b)!==4)return false;foreach($b as $n)if(!is_int($n))return false;
        return !($b[0]<0||$b[1]<0||$b[2]>2048||$b[3]>2048||$b[0]>=$b[2]||$b[1]>=$b[3]||$mask['positivePixels']>($b[2]-$b[0])*($b[3]-$b[1]));
    }
}
