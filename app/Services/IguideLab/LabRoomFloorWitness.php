<?php
namespace App\Services\IguideLab;

use Illuminate\Support\Str;

/** An interior source witness grants support eligibility, never whole-mask semantics. */
final class LabRoomFloorWitness
{
    public const VERSION='interior-source-floor-witness/2';
    public const LABEL='opaque matte target-room floor interior witness';
    public static function snapshot(): array
    {return ['version'=>self::VERSION,'implementationSha256'=>hash_file('sha256',__FILE__),'semantics'=>'interior_support_witness',
        'minimumSidePixels'=>16,'coverage'=>'every_witness_pixel_255','restrictionUsage'=>'support_eligibility_only','semanticClassVerified'=>false];}
    public static function selected(array $recipe): ?array
    {
        $contract=$recipe['implementation']['workflow']['floorContract']??null;
        if($contract===null)return null;
        abort_unless(config('iguide-lab.interior_floor_witness_enabled',false)===true
            &&LabMachineSegmentationPlan::canonicalHash($contract)===LabMachineSegmentationPlan::canonicalHash(self::snapshot()),422,'Reserved floor witness contract changed or disabled.');
        return $contract;
    }
    public static function request(array $wire,array $contract,string $phase): array
    {
        abort_unless(LabMachineSegmentationPlan::canonicalHash($contract)===LabMachineSegmentationPlan::canonicalHash(self::snapshot()),422);if(!in_array($phase,['layout','candidate'],true))return $wire;
        $wire['instructions'].="\nSource floor contract ".self::VERSION.'. sourceFloor.box is an inclusive INTERIOR SUPPORT WITNESS, not the bounding box of the whole visible floor and not a SAM box prompt. Every pixel inside it must be clearly visible original opaque matte floor on the target room plane, excluding all walls, baseboards, stairs, other levels, fixtures, rugs, reflections and uncertain boundaries. Each side must be at least 16 pixels. Plan all prospective contacts and their tiny contact attenuation safely inside this witness with inward clearance; the backend restricts usable support to it and retains the ordinary contact erosion. A text-only floor mask may be larger, but its outside pixels grant no support authority. Do not shrink or move furniture to conceal a failed witness. If no such source-attested rectangle supports the complete group, hold. sourceFloor.prompt must be exactly: '.self::LABEL.'. Candidate copies the original witness unchanged. Pixel coverage is not semantic certification, physical floor calibration or hidden footprint clearance.';
        $wire['text']['format']['schema']['properties']['sourceFloor']['anyOf'][0]['properties']['prompt']=['type'=>'string','enum'=>[self::LABEL]];
        $binding=json_decode($wire['input'][0]['content'][1]['text'],true,flags:JSON_THROW_ON_ERROR);
        $binding['requestVersion']='interior-room-floor-witness-request/1';$binding['floorContract']=$contract;
        $wire['input'][0]['content'][1]['text']=json_encode($binding,JSON_THROW_ON_ERROR);return $wire;
    }
    public static function result(array $result,string $phase): array
    {
        if(in_array($phase,['layout','candidate'],true)&&$result['status']!=='needs_review')self::selection($result['sourceFloor']);
        return $result;
    }
    public static function selection(array $selection): array
    {
        LabInteriorDesignProtocol::keys($selection,['status','prompt','box']);
        abort_unless($selection['status']==='opaque_matte'&&$selection['prompt']===self::LABEL,422,'A legacy floor selection is not an interior witness.');
        $box=$selection['box'];LabInteriorDesignProtocol::box($box,false);
        abort_unless($box[2]-$box[0]+1>=16&&$box[3]-$box[1]+1>=16,422,'Floor witness is too small.');return $box;
    }
    public function bind(string $requestId,string $profileId,array $selection,string $sourceSha,string $nativeSha,string $assessmentSha,string $contextSha): array
    {
        $plan=['stepKey'=>'source-floor','profileId'=>$profileId,'requestId'=>$requestId,'reservationState'=>'server_reserved','model'=>LabMachineSegmentationTransport::MODEL,
            'inputSha256'=>$sourceSha,'width'=>2048,'height'=>2048,'payload'=>['prompt'=>'floor','box_prompts'=>[],
                'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,'max_masks'=>1,'include_scores'=>true,'include_boxes'=>true],
            'expectedIdentities'=>['floor'],'selectionOrigin'=>'machine','assessmentProposalSha256'=>$assessmentSha,'contextSha256'=>$contextSha,
            'maximumPostAttempts'=>1,'automaticRetry'=>false,'semanticClassVerified'=>false,
            'witness'=>['version'=>self::VERSION,'semantics'=>'interior_support_witness','box'=>self::selection($selection),'nativeSourceSha256'=>$nativeSha]];
        $plan['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($plan);$this->validate($plan,$requestId);return $plan;
    }
    public function validate(array $p,string $requestId): void
    {
        LabInteriorDesignProtocol::keys($p,['stepKey','profileId','requestId','reservationState','model','inputSha256','width','height','payload','expectedIdentities','selectionOrigin','assessmentProposalSha256','contextSha256','maximumPostAttempts','automaticRetry','semanticClassVerified','witness','inputFingerprint']);
        abort_unless(LabInteriorRoomProfiles::get($p['profileId'])['kind']==='new_furnishings'&&Str::isUuid($requestId)&&$p['requestId']===$requestId
            &&$p['stepKey']==='source-floor'&&$p['reservationState']==='server_reserved'&&$p['model']===LabMachineSegmentationTransport::MODEL
            &&[$p['width'],$p['height']]===[2048,2048]&&$p['expectedIdentities']===['floor']&&$p['selectionOrigin']==='machine'
            &&$p['maximumPostAttempts']===1&&$p['automaticRetry']===false&&$p['semanticClassVerified']===false,422);
        foreach(['inputSha256','assessmentProposalSha256','contextSha256','inputFingerprint'] as $key)LabInteriorDesignProtocol::hash($p[$key]);
        $w=$p['witness'];LabInteriorDesignProtocol::keys($w,['version','semantics','box','nativeSourceSha256']);LabInteriorDesignProtocol::hash($w['nativeSourceSha256']);
        abort_unless($w['version']===self::VERSION&&$w['semantics']==='interior_support_witness',422);
        self::selection(['status'=>'opaque_matte','prompt'=>self::LABEL,'box'=>$w['box']]);
        $payload=['prompt'=>'floor','box_prompts'=>[],'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,'max_masks'=>1,'include_scores'=>true,'include_boxes'=>true];
        abort_unless(LabMachineSegmentationPlan::canonicalHash($p['payload'])===LabMachineSegmentationPlan::canonicalHash($payload),422,'Floor v2 is text-only.');
        $q=$p;unset($q['inputFingerprint']);abort_unless($p['inputFingerprint']===LabMachineSegmentationPlan::canonicalHash($q),422);
    }
    public function payload(array $plan,string $bytes): array
    {
        $this->validate($plan,$plan['requestId']);abort_unless(strlen($bytes)<=30*1024*1024&&hash('sha256',$bytes)===$plan['inputSha256'],422);
        $s=@getimagesizefromstring($bytes);abort_unless($s&&[$s[0],$s[1],$s['mime']]===[2048,2048,'image/png'],422);
        $im=@imagecreatefromstring($bytes);abort_unless($im!==false,422);imagedestroy($im);return ['image_url'=>'data:image/png;base64,'.base64_encode($bytes)]+$plan['payload'];
    }
    public function associate(array $plan,array $receipt,string $bytes): array
    {
        $this->validate($plan,$plan['requestId']);LabInteriorDesignProtocol::keys($receipt,['requestId','providerRequestId','model','inputFingerprint','inputSha256','rawResponseSha256','status','masks']);
        foreach(['requestId','model','inputFingerprint','inputSha256'] as $key)abort_unless($receipt[$key]===$plan[$key],422);
        LabInteriorDesignProtocol::hash($receipt['rawResponseSha256']);abort_unless(is_string($receipt['providerRequestId'])&&$receipt['providerRequestId']!=='',422);
        $held=['status'=>'needs_review','reviewReasons'=>['SOURCE_FLOOR_WITNESS_PIXELS_UNCONFIRMED'],'associations'=>[]];
        if($receipt['status']!=='completed'||!is_array($receipt['masks'])||!array_is_list($receipt['masks'])||count($receipt['masks'])!==1)return $held;
        $descriptor=app(LabMachineMaskPixels::class)->describe($bytes);
        abort_unless(LabMachineSegmentationPlan::canonicalHash($descriptor)===LabMachineSegmentationPlan::canonicalHash($receipt['masks'][0]),422,'Floor mask bytes do not match their receipt.');
        $im=imagecreatefromstring($bytes);if(!imageistruecolor($im))imagepalettetotruecolor($im);$box=$plan['witness']['box'];$covered=true;$binary=true;
        try{for($y=0;$y<2048;$y++)for($x=0;$x<2048;$x++){
            $v=imagecolorat($im,$x,$y)&255;if($v!==0&&$v!==255)$binary=false;
            if($x>=$box[0]&&$x<=$box[2]&&$y>=$box[1]&&$y<=$box[3]&&$v!==255)$covered=false;
        }}finally{imagedestroy($im);}
        if(!$binary||!$covered)return $held;
        $pixels=hash_init('sha256');$empty=str_repeat("\0",2048);$row=str_repeat("\0",$box[0]).str_repeat("\xff",$box[2]-$box[0]+1).str_repeat("\0",2047-$box[2]);
        for($y=0;$y<2048;$y++)hash_update($pixels,$y>=$box[1]&&$y<=$box[3]?$row:$empty);
        $w=['version'=>self::VERSION,'semantics'=>'interior_support_witness','box'=>$box,'sourceSha256'=>$plan['inputSha256'],
            'nativeSourceSha256'=>$plan['witness']['nativeSourceSha256'],'rawMaskSha256'=>$descriptor['sha256'],'planSha256'=>$plan['inputFingerprint'],
            'assessmentSha256'=>$plan['assessmentProposalSha256'],'contextSha256'=>$plan['contextSha256'],'usablePixelsSha256'=>hash_final($pixels)];
        return ['status'=>'ready_for_room_support','reviewReasons'=>[],'associations'=>[['targetId'=>'floor','role'=>'source-floor','maskIndex'=>0,
            'maskSha256'=>$descriptor['sha256'],'positivePixels'=>$descriptor['positivePixels'],'witnessPixels'=>($box[2]-$box[0]+1)*($box[3]-$box[1]+1),'semanticClassVerified'=>false]],
            'floorWitness'=>$w,'restrictionUsage'=>'support_eligibility_only','humanReviewed'=>false,'automaticAcceptance'=>false];
    }
}
