<?php
namespace App\Services\IguideLab;

/** Source-only union geometry. Neither its boxes nor prospective envelopes are actual contacts. */
final class LabRoomFloorWitnessUnion
{
    public const VERSION='interior-source-floor-witness/3';
    public const LABEL='opaque matte target-room floor union interior witnesses';
    public const IDS=['source','parent_source','parent_front','parent_right','parent_back','parent_left'];
    // Exact row half-widths of OpenCV5 MORPH_ELLIPSE(13,13), the existing 2048 contact erosion.
    public const KERNEL=[0,3,4,5,6,6,6,6,6,5,4,3,0];
    // One pure raster value only: no source, selection, mask, receipt or authority is cached.
    // PHP array/string copy-on-write prevents a caller from mutating the retained value.
    private static ?string $domainCacheKey=null;
    private static ?array $domainCache=null;
    public static function snapshot(): array
    {return ['version'=>self::VERSION,'implementationSha256'=>hash_file('sha256',__FILE__),'maximumRegions'=>4,'minimumSidePixels'=>16,
        'coverage'=>'every_union_pixel_255','erosionRadiusPixels'=>6,'erosionKernelHalfWidths'=>self::KERNEL,
        'restrictionUsage'=>'support_eligibility_only','semanticClassVerified'=>false];}
    public static function enabled(): bool
    {return config('iguide-lab.interior_floor_witness_union_enabled',false)===true;}
    public static function selected(array $recipe): bool
    {return (LabRoomFloorWitness::selected($recipe)['version']??null)===self::VERSION;}
    public static function schema(): array
    {
        $p=LabInteriorDesignProtocol::class;$box=['type'=>'array','items'=>['type'=>'integer','minimum'=>0,'maximum'=>2047],'minItems'=>4,'maxItems'=>4];
        return $p::object(['status'=>['type'=>'string','enum'=>['opaque_matte']],'prompt'=>['type'=>'string','enum'=>[self::LABEL]],
            'regions'=>['type'=>'array','items'=>$box,'minItems'=>1,'maxItems'=>4],
            'planeEvidence'=>$p::object(['status'=>['type'=>'string','enum'=>['same_visible_target_floor']],
                'evidenceViewIds'=>['type'=>'array','items'=>['type'=>'string','enum'=>self::IDS],'minItems'=>6,'maxItems'=>6],
                'reason'=>['type'=>'string','minLength'=>1,'maxLength'=>550]]),
            'contactEnvelopes'=>['type'=>'array','minItems'=>3,'maxItems'=>3,'items'=>$p::object([
                'objectId'=>['type'=>'string','enum'=>['sofa','coffee_table','chair','bed','nightstand_left','nightstand_right']], 'box'=>$box])]]);
    }
    public static function instructions(): string
    {return 'Source-floor union contract '.self::VERSION.'. On the exact original 2048x2048 cut, choose 1 to 4 inclusive integer rectangles [xMin,yMin,xMax,yMax], each at least16 pixels per side. Every pixel of every rectangle must be visibly original opaque matte floor on the SAME target-room plane, excluding walls, steps, other levels, entry thresholds, rugs, fixtures, reflections and uncertain boundaries. Cite every original source/parent view in planeEvidence; no candidate may supply source-floor authority. Rectangles may overlap. Their exact union preserves gaps and concavity; the backend never fills its bounding box or bridges separate patches. It erodes that union once using the unchanged6-pixel-radius contact kernel. For each profile role propose one contactEnvelopes box that includes all prospective visible floor contacts AND their tiny local attenuation, wholly inside the eroded union, and intersects the lower part of its full-object planning box. All envelopes must retain the205..1842 frame guard. Use numeric bounds to plan the group before requesting an image; if no ordinary plausible placement fits, hold instead of inventing floor or moving exclusions. These are prospective image-space guides, not masks, metric footprints, calibrated clearance or actual contacts. The candidate copies the ENTIRE sourceFloor object unchanged, including all regions, planeEvidence and contactEnvelopes. Actual contacts/support and all original optical/doorway/native quality checks remain mandatory. sourceFloor.prompt is exactly: '.self::LABEL.'.';}
    public static function selection(array $v,?string $profileId=null,?array $objects=null): array
    {
        $p=LabInteriorDesignProtocol::class;$p::keys($v,['status','prompt','regions','planeEvidence','contactEnvelopes']);
        abort_unless($v['status']==='opaque_matte'&&$v['prompt']===self::LABEL,422);
        $regions=$v['regions'];abort_unless(is_array($regions)&&array_is_list($regions)&&count($regions)>=1&&count($regions)<=4,422);
        $seen=[];foreach($regions as $b){$p::box($b,false);abort_unless($b[2]-$b[0]+1>=16&&$b[3]-$b[1]+1>=16,422);
            $key=implode(',',$b);abort_if(isset($seen[$key]),422,'Duplicate source witness rectangle.');$seen[$key]=true;}
        $e=$v['planeEvidence'];$p::keys($e,['status','evidenceViewIds','reason']);$p::text($e['reason'],550);$p::strings($e['evidenceViewIds'],self::IDS,6);
        abort_unless($e['status']==='same_visible_target_floor'&&!array_diff(self::IDS,$e['evidenceViewIds']),422,'Same original floor plane evidence is required.');
        $env=$v['contactEnvelopes'];abort_unless(is_array($env)&&array_is_list($env)&&count($env)===3,422);
        $roles=array_column($env,'objectId');if($profileId!==null)abort_unless($roles===LabInteriorRoomProfiles::get($profileId)['roles'],422);
        else abort_unless(in_array($roles,[LabInteriorRoomProfiles::get(LabInteriorRoomProfiles::LIVING)['roles'],LabInteriorRoomProfiles::get(LabInteriorRoomProfiles::BEDROOM)['roles']],true),422);
        $domain=self::domain($regions);
        foreach($env as $i=>$row){$p::keys($row,['objectId','box']);$p::box($row['box']);[$x0,$y0,$x1,$y1]=$row['box'];
            for($y=$y0;$y<=$y1;$y++)abort_unless(substr_count(substr($domain['safeRows'][$y],$x0,$x1-$x0+1),"\xff")===$x1-$x0+1,422,'Prospective contact envelope leaves the eroded source-floor union.');
            if($objects!==null){$b=$objects[$i]['box'];abort_unless($objects[$i]['id']===$row['objectId']&&$x1>=$b[0]&&$x0<=$b[2]&&$y1>=intdiv($b[1]+$b[3],2)&&$y0<=$b[3],422,'Source contact envelope does not meet its planned object lower region.');}
        }
        return $domain;
    }
    /** Exact raster union and one erosion; no convex hull, dilation or candidate-informed expansion. */
    public static function domain(array $regions): array
    {
        // Validate the exact integer tuple on every call, including cache hits.
        abort_unless(array_is_list($regions)&&count($regions)>=1&&count($regions)<=4,422);
        $seen=[];foreach($regions as $b){LabInteriorDesignProtocol::box($b,false);abort_unless($b[2]-$b[0]+1>=16&&$b[3]-$b[1]+1>=16,422);
            $identity=implode(',',$b);abort_if(isset($seen[$identity]),422,'Duplicate source witness rectangle.');$seen[$identity]=true;}
        $key=json_encode([self::VERSION,[2048,2048],self::KERNEL,'positive_infinity',$regions],JSON_THROW_ON_ERROR);
        if(self::$domainCacheKey===$key&&self::$domainCache!==null)return self::$domainCache;
        // Bounded across long-lived queue jobs; a miss evicts before allocating the next raster.
        self::$domainCacheKey=null;self::$domainCache=null;
        $rows=[];for($y=0;$y<2048;$y++){$row=str_repeat("\0",2048);foreach($regions as [$x0,$y0,$x1,$y1])if($y>=$y0&&$y<=$y1)$row=substr_replace($row,str_repeat("\xff",$x1-$x0+1),$x0,$x1-$x0+1);$rows[]=$row;}
        $safe=[];$ones=str_repeat("\xff",2048);
        for($y=0;$y<2048;$y++){$row=$ones;foreach(self::KERNEL as $k=>$r){$yy=$y+$k-6;if($yy<0||$yy>=2048)continue; // OpenCV erosion default border is positive infinity.
            $src=$rows[$yy];for($dx=-$r;$dx<=$r;$dx++){$shift=$dx<0?str_repeat("\xff",-$dx).substr($src,0,2048+$dx):substr($src,$dx).str_repeat("\xff",$dx);$row=$row&$shift;}}
            $safe[]=$row;}
        $domain=['rows'=>$rows,'safeRows'=>$safe,'usablePixelsSha256'=>hash('sha256',implode('',$rows)),
            'safePixelsSha256'=>hash('sha256',implode('',$safe)),'usablePositivePixels'=>array_sum(array_map(fn($r)=>substr_count($r,"\xff"),$rows))];
        self::$domainCache=$domain;self::$domainCacheKey=$key;return $domain;
    }
    public static function geometryBinding(array $selection): array
    {$d=self::selection($selection);return ['version'=>self::VERSION,'selectionSha256'=>LabMachineSegmentationPlan::canonicalHash($selection),
        'usablePixelsSha256'=>$d['usablePixelsSha256'],'safePixelsSha256'=>$d['safePixelsSha256'],'erosionRadiusPixels'=>6];}
    public static function prompt(array $selection): string
    {
        self::selection($selection);
        return "\nOriginal floor placement limits on this2048x2048 image, inclusive pixels [left,top,right,bottom]: ".json_encode($selection['regions'],JSON_THROW_ON_ERROR)
            .'. Only the exact UNION of these rectangles is eligible floor; gaps are excluded, never use their outer bounding rectangle. Keep the entire visible contact and tiny contact attenuation region inside the floor after6-pixel inward clearance (existing elliptical erosion), and keep complete furniture inside205..1842.'
            .' Source-planned contact-plus-attenuation envelopes by object: '.json_encode($selection['contactEnvelopes'],JSON_THROW_ON_ERROR)
            .'. Place each object so its contacts and tiny attenuation fit its named envelope. These numeric limits are unchanged for a correction. Do not draw these boxes/labels, replace floor, or fabricate hidden support.';
    }
    public static function request(array $wire,array $contract,string $phase): array
    {
        abort_unless(LabMachineSegmentationPlan::canonicalHash($contract)===LabMachineSegmentationPlan::canonicalHash(self::snapshot()),422);
        if(!in_array($phase,['layout','candidate'],true))return $wire;
        abort_unless(str_contains($wire['instructions'],self::instructions()),422,'The source union compiler is required.');
        $b=json_decode($wire['input'][0]['content'][1]['text'],true,flags:JSON_THROW_ON_ERROR);$b['requestVersion']='interior-room-evidence-floor-union-request/1';$b['floorContract']=$contract;
        $wire['input'][0]['content'][1]['text']=json_encode($b,JSON_THROW_ON_ERROR);return $wire;
    }
    public static function result(array $v,string $phase): array
    {if(in_array($phase,['layout','candidate'],true)&&$v['status']!=='needs_review')self::selection($v['sourceFloor'],$v['profileId'],$phase==='layout'?$v['objects']:null);return $v;}
    public function bind(string $requestId,string $profileId,array $selection,string $sourceSha,string $nativeSha,string $assessmentSha,string $contextSha): array
    {
        $d=self::selection($selection,$profileId);
        $p=['stepKey'=>'source-floor','profileId'=>$profileId,'requestId'=>$requestId,'reservationState'=>'server_reserved','model'=>LabMachineSegmentationTransport::MODEL,
            'inputSha256'=>$sourceSha,'width'=>2048,'height'=>2048,'payload'=>['prompt'=>'floor','box_prompts'=>[],'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,'max_masks'=>1,'include_scores'=>true,'include_boxes'=>true],
            'expectedIdentities'=>['floor'],'selectionOrigin'=>'machine','assessmentProposalSha256'=>$assessmentSha,'contextSha256'=>$contextSha,'maximumPostAttempts'=>1,'automaticRetry'=>false,'semanticClassVerified'=>false,
            'witness'=>['version'=>self::VERSION,'selection'=>$selection,'nativeSourceSha256'=>$nativeSha,'usablePixelsSha256'=>$d['usablePixelsSha256'],'safePixelsSha256'=>$d['safePixelsSha256']]];
        $p['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($p);$this->validate($p,$requestId);return $p;
    }
    public function validate(array $p,string $requestId): void
    {
        $v=LabInteriorDesignProtocol::class;$v::keys($p,['stepKey','profileId','requestId','reservationState','model','inputSha256','width','height','payload','expectedIdentities','selectionOrigin','assessmentProposalSha256','contextSha256','maximumPostAttempts','automaticRetry','semanticClassVerified','witness','inputFingerprint']);
        abort_unless(\Illuminate\Support\Str::isUuid($requestId)&&$p['requestId']===$requestId&&$p['stepKey']==='source-floor'&&$p['reservationState']==='server_reserved'&&$p['model']===LabMachineSegmentationTransport::MODEL&&[$p['width'],$p['height']]===[2048,2048]&&$p['expectedIdentities']===['floor']&&$p['selectionOrigin']==='machine'&&$p['maximumPostAttempts']===1&&$p['automaticRetry']===false&&$p['semanticClassVerified']===false,422);
        foreach(['inputSha256','assessmentProposalSha256','contextSha256','inputFingerprint'] as $k)$v::hash($p[$k]);
        $w=$p['witness'];$v::keys($w,['version','selection','nativeSourceSha256','usablePixelsSha256','safePixelsSha256']);$v::hash($w['nativeSourceSha256']);abort_unless($w['version']===self::VERSION,422);
        $d=self::selection($w['selection'],$p['profileId']);foreach(['usablePixelsSha256','safePixelsSha256'] as $k)abort_unless($w[$k]===$d[$k],422,'Source union pixels changed.');
        $expected=['prompt'=>'floor','box_prompts'=>[],'apply_mask'=>false,'output_format'=>'png','return_multiple_masks'=>true,'max_masks'=>1,'include_scores'=>true,'include_boxes'=>true];
        abort_unless(LabMachineSegmentationPlan::canonicalHash($p['payload'])===LabMachineSegmentationPlan::canonicalHash($expected),422);
        $q=$p;unset($q['inputFingerprint']);abort_unless($p['inputFingerprint']===LabMachineSegmentationPlan::canonicalHash($q),422);
    }
    public function payload(array $plan,string $bytes): array
    {$this->validate($plan,$plan['requestId']);abort_unless(strlen($bytes)<=30*1024*1024&&hash('sha256',$bytes)===$plan['inputSha256'],422);$s=@getimagesizefromstring($bytes);abort_unless($s&&[$s[0],$s[1],$s['mime']]===[2048,2048,'image/png'],422);return ['image_url'=>'data:image/png;base64,'.base64_encode($bytes)]+$plan['payload'];}
    public function associate(array $plan,array $receipt,string $bytes): array
    {
        $this->validate($plan,$plan['requestId']);LabInteriorDesignProtocol::keys($receipt,['requestId','providerRequestId','model','inputFingerprint','inputSha256','rawResponseSha256','status','masks']);
        foreach(['requestId','model','inputFingerprint','inputSha256'] as $k)abort_unless($receipt[$k]===$plan[$k],422);LabInteriorDesignProtocol::hash($receipt['rawResponseSha256']);abort_unless(is_string($receipt['providerRequestId'])&&$receipt['providerRequestId']!=='',422);
        $held=['status'=>'needs_review','reviewReasons'=>['SOURCE_FLOOR_UNION_PIXELS_UNCONFIRMED'],'associations'=>[]];
        if($receipt['status']!=='completed'||!is_array($receipt['masks'])||!array_is_list($receipt['masks'])||count($receipt['masks'])!==1)return $held;
        $desc=app(LabMachineMaskPixels::class)->describe($bytes);abort_unless(LabMachineSegmentationPlan::canonicalHash($desc)===LabMachineSegmentationPlan::canonicalHash($receipt['masks'][0]),422);
        $d=self::selection($plan['witness']['selection'],$plan['profileId']);$im=imagecreatefromstring($bytes);if(!imageistruecolor($im))imagepalettetotruecolor($im);$valid=true;
        try{for($y=0;$y<2048;$y++)for($x=0;$x<2048;$x++){$n=imagecolorat($im,$x,$y)&255;if(($n!==0&&$n!==255)||($d['rows'][$y][$x]==="\xff"&&$n!==255))$valid=false;}}finally{imagedestroy($im);}if(!$valid)return $held;
        $w=$plan['witness']+['sourceSha256'=>$plan['inputSha256'],'rawMaskSha256'=>$desc['sha256'],'planSha256'=>$plan['inputFingerprint'],'assessmentSha256'=>$plan['assessmentProposalSha256'],'contextSha256'=>$plan['contextSha256']];
        return ['status'=>'ready_for_room_support','reviewReasons'=>[],'associations'=>[['targetId'=>'floor','role'=>'source-floor','maskIndex'=>0,'maskSha256'=>$desc['sha256'],'positivePixels'=>$desc['positivePixels'],'witnessPixels'=>$d['usablePositivePixels'],'semanticClassVerified'=>false]],'floorWitness'=>$w,'restrictionUsage'=>'support_eligibility_only','humanReviewed'=>false,'automaticAcceptance'=>false];
    }
}
