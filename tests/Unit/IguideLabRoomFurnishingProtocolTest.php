<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabInteriorDesignProtocol as V,LabInteriorRoomProfiles as R,LabInteriorRoomRouteProtocol as Route,LabMachineSegmentationPlan as H,
    LabRoomFurnishingProtocol as Base,LabRoomFurnishingFramingProtocol as F,LabRoomFurnishingLayoutProtocol as L,LabRoomFurnishingCandidateProtocol as C};
use Tests\TestCase;

class IguideLabRoomFurnishingProtocolTest extends TestCase
{
    public static function context(string $phase='framing',string $profile=R::BEDROOM): array
    {
        [$source]=IguideLabInteriorRoomRouteProtocolTest::fixture();$value=IguideLabInteriorRoomRouteProtocolTest::candidate($source);
        if($profile===R::LIVING)$value['room']['function']='living';$route=Route::parse(V::response($value),$source);
        $binding=['profileId'=>$profile,'publicPreset'=>'interior-design','style'=>$source['style'],'sourceSha256'=>$source['nativeSourceSha256'],
            'intentSha256'=>$source['intentSha256'],'profileSha256'=>H::canonicalHash(R::get($profile))];
        $c=['version'=>F::VERSION,'phase'=>'framing','nativeSourceSha256'=>$source['nativeSourceSha256'],'sourceSha256'=>$source['nativeSourceSha256'],
            'preparedSourceSha256'=>$source['preparedSourceSha256'],'sourceAuthoritySha256'=>$source['sourceAuthoritySha256'],'intentSha256'=>$source['intentSha256'],
            'style'=>$source['style'],'instructions'=>$source['instructions'],'route'=>['binding'=>$binding,'result'=>$route],
            'framing'=>null,'layout'=>null,'candidateRunId'=>null,'candidateSha256'=>null,'views'=>$source['views']];
        if($phase==='framing')return $c;
        $framing=F::parse(V::response(self::value(F::class,$c)),$c);$c['version']=L::VERSION;$c['phase']='layout';
        $c['sourceSha256']=str_repeat('c',64);$c['preparedSourceSha256']=str_repeat('d',64);
        $c['framing']=['id'=>'8f6be514-8642-4be8-b8b0-129ab786cfe4','resultSha256'=>H::canonicalHash($framing),'responseSha256'=>str_repeat('f',64),'result'=>$framing];
        $c['views']=[['id'=>'source','sha256'=>$c['preparedSourceSha256'],'width'=>2048,'height'=>2048]];
        foreach(array_slice($source['views'],0,5) as $i=>$v){$v['id']=L::CUT_IDS[$i+1];$c['views'][]=$v;}
        if($phase==='layout')return $c;
        $layout=L::parse(V::response(self::value(L::class,$c)),$c);$c['version']=C::VERSION;$c['phase']='candidate';
        $c['layout']=['id'=>'25735e25-bcc6-4ff4-9764-d2718fd12aeb','resultSha256'=>H::canonicalHash($layout),'responseSha256'=>str_repeat('e',64),'result'=>$layout];
        $c['candidateRunId']='90997bff-8268-4646-b876-b83d141f35b7';$c['candidateSha256']=str_repeat('7',64);
        array_splice($c['views'],1,0,[['id'=>'candidate','sha256'=>$c['candidateSha256'],'width'=>2048,'height'=>2048]]);return $c;
    }
    public static function value(string $p,array $c): array
    {
        $phase=$p::PHASE;$profile=R::get($c['route']['binding']['profileId']);
        $v=['version'=>$p::VERSION,'contextSha256'=>H::canonicalHash($c),'profileId'=>$profile['id'],
            'status'=>match($phase){'framing'=>'framing_candidate','layout'=>'ready_for_image','candidate'=>'ready_for_masks'},
            'view'=>$phase==='framing'?['yaw'=>0.0,'pitch'=>-25.0,'hFov'=>100.0]:null,'objects'=>[],'sourceFloor'=>null,'styleFeatures'=>[],
            'foregroundStatus'=>$phase==='framing'?'not_assessed':'none_observed','opticalStatus'=>$phase==='framing'?'not_assessed':'no_required_changes',
            'contactShadowStatus'=>$phase==='framing'?'not_assessed':'tiny_local_only',
            'checks'=>array_fill_keys($phase==='candidate'?Base::CANDIDATE_CHECKS:Base::CHECKS,['status'=>$phase==='framing'?'verify_on_cut':'pass','evidenceViewIds'=>$p::ids(),'reason'=>'Inspect exact source, actual group and all original optical context.']),
            'summary'=>'Bounded synthetic protocol fixture, not real room or quality evidence.'];
        if($phase!=='framing'){
            $boxes=[[650,550,1400,1500],[300,600,600,1000],[1450,600,1740,1000]];
            foreach($profile['roles'] as $i=>$id){$b=$boxes[$i];$v['objects'][]=['id'=>$id,'description'=>'A complete opaque matte '.$id.' in the requested style.','box'=>$b,'maskPrompt'=>$id,
                'visibleBaseContacts'=>$phase==='candidate'&&$profile['id']===R::BEDROOM?[[[$b[0]+20,$b[3]-1],[min($b[2]-20,$b[0]+500),$b[3]-1]]]:[]];}
            $v['sourceFloor']=['status'=>'opaque_matte','prompt'=>'original floor','box'=>[205,450,1842,1842]];
            $v['styleFeatures']=[['kind'=>'form','description'=>'Simple coherent profile-specific forms.','objectIds'=>$profile['roles']],['kind'=>'material','description'=>'Visible matte wood and woven cloth appropriate to the selected style.','objectIds'=>$profile['roles']]];
        }return $v;
    }
    public function test_source_framing_actual_layout_and_realized_bed_roles_are_distinct(): void
    {
        foreach([F::class,L::class,C::class] as $p){$c=self::context($p::PHASE);$v=$p::parse(V::response(self::value($p,$c)),$c);
            $this->assertFalse($v['humanReviewedSupport']);$this->assertFalse($v['automaticAcceptance']);$this->assertFalse($v['crossViewVerified']);
            $this->assertSame($p::VERSION,$v['version']);if($p!==F::class)$this->assertSame(R::get(R::BEDROOM)['roles'],array_column($v['objects'],'id'));}
    }
    public function test_bedroom_cannot_borrow_living_identity_or_heuristic_contacts(): void
    {
        $c=self::context('candidate');$v=self::value(C::class,$c);
        $bad=$v;$bad['objects'][0]['id']='sofa';$this->rejects(fn()=>C::parse(V::response($bad),$c));
        $bad=$v;$bad['objects'][0]['visibleBaseContacts']=[];$this->rejects(fn()=>C::parse(V::response($bad),$c));
        $living=self::context('candidate',R::LIVING);$out=C::parse(V::response(self::value(C::class,$living)),$living);$this->assertSame(['sofa','coffee_table','chair'],array_column($out['objects'],'id'));
        $bad=self::value(C::class,$living);$bad['objects'][0]['visibleBaseContacts']=[[[700,1499],[900,1499]]];$this->rejects(fn()=>C::parse(V::response($bad),$living));
    }
    public function test_source_layout_cannot_claim_realized_contacts_and_optical_uncertainty_holds(): void
    {
        $c=self::context('layout');$v=self::value(L::class,$c);$bad=$v;$bad['objects'][0]['visibleBaseContacts']=[[[700,1499],[900,1499]]];$this->rejects(fn()=>L::parse(V::response($bad),$c));
        $bad=$v;$bad['checks']['optical_dependencies']['status']='uncertain';$this->rejects(fn()=>L::parse(V::response($bad),$c));
        $bad['status']='needs_review';$bad['objects']=[];$bad['sourceFloor']=null;$bad['styleFeatures']=[];$this->assertSame('needs_review',L::parse(V::response($bad),$c)['status']);
    }
    public function test_actual_candidate_cannot_move_original_floor_or_hide_parent_context(): void
    {
        $c=self::context('candidate');$v=self::value(C::class,$c);$bad=$v;$bad['sourceFloor']['box'][1]=500;$this->rejects(fn()=>C::parse(V::response($bad),$c));
        $bad=$v;$bad['checks']['optical_dependencies']['evidenceViewIds']=['source','candidate'];$this->rejects(fn()=>C::parse(V::response($bad),$c));
        $bad=$v;$bad['checks']['geometry']['evidenceViewIds']=['source'];$this->rejects(fn()=>C::parse(V::response($bad),$c));
        $bad=$c;$bad['layout']['result']['sourceFloor']['box'][1]=500;$this->rejects(fn()=>C::context($bad));
    }
    public function test_exact_context_digest_frame_guard_contact_bounds_and_full_style_cannot_be_relaxed(): void
    {
        $c=self::context('candidate');$v=self::value(C::class,$c);
        foreach([
            fn($v)=>array_replace($v,['contextSha256'=>str_repeat('0',64)]),
            fn($v)=>array_replace_recursive($v,['objects'=>[0=>['box'=>[204,550,1400,1500]]]]),
            fn($v)=>array_replace_recursive($v,['objects'=>[0=>['visibleBaseContacts'=>[[[20,1499],[900,1499]]]]]]),
            fn($v)=>array_replace_recursive($v,['styleFeatures'=>[0=>['kind'=>'colour'],1=>['kind'=>'colour']]])
        ] as $change)$this->rejects(fn()=>C::parse(V::response($change($v)),$c));
    }
    private function rejects(callable $f): void {try{$f();$this->fail('Unsafe profile authority accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
}
