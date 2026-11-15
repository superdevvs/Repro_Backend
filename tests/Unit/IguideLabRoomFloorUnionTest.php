<?php
namespace Tests\Unit;
use App\Models\IguideLabRoomAssessment;
use App\Services\IguideLab\{LabRoomFloorWitnessUnion as U,LabRoomFloorWitness as W,LabRoomFloorUnionLayoutProtocol as L,LabRoomFloorUnionCandidateProtocol as C,
    LabRoomFloorUnionImageDraft as Image,LabRoomEvidenceLayoutProtocol as OldL,LabRoomEvidenceCandidateProtocol as OldC,LabRoomEvidenceImageDraft as OldImage,
    LabInteriorRoomProfiles as R,LabMachineSegmentationPlan as H,LabInteriorDesignProtocol as V,LabMachineMaskPixels};
use Tests\TestCase;
class IguideLabRoomFloorUnionTest extends TestCase
{
    public static function selection(): array
    {return ['status'=>'opaque_matte','prompt'=>U::LABEL,'regions'=>[[200,1200,1200,1800],[1100,1100,1800,1800]],
        'planeEvidence'=>['status'=>'same_visible_target_floor','evidenceViewIds'=>U::IDS,'reason'=>'Synthetic same visible original target floor, excluding all steps and doorways.'],
        'contactEnvelopes'=>[['objectId'=>'sofa','box'=>[330,1500,570,1580]],['objectId'=>'coffee_table','box'=>[1420,1500,1680,1580]],['objectId'=>'chair','box'=>[875,1500,1120,1580]]]];}
    public static function layout(): array
    {[$c,$v]=IguideLabRoomEvidenceProtocolTest::layout();$c['version']=L::VERSION;$v['version']=L::VERSION;$v['contextSha256']=H::canonicalHash($c);$v['draftEvidence']['sourceBindingSha256']=H::canonicalHash($c);
        $v['sourceFloor']=self::selection();$boxes=[[300,650,599,1549],[1400,650,1699,1549],[850,650,1149,1549]];foreach($v['objects'] as $i=>&$o)$o['box']=$boxes[$i];unset($o);return [$c,$v];}
    public static function candidate(): array
    {[$lc,$lv]=self::layout();$layout=L::parse(V::response($lv),$lc);[$c,$v]=IguideLabRoomEvidenceProtocolTest::candidate();$c['version']=C::VERSION;$c['layout']['result']=$layout;$c['layout']['resultSha256']=H::canonicalHash($layout);
        $v['version']=C::VERSION;$v['contextSha256']=H::canonicalHash($c);$v['sourceFloor']=$layout['sourceFloor'];return [$c,$v];}
    private function rejects(callable $f): void
    {try{$f();$this->fail('Invalid floor authority accepted.');}catch(\InvalidArgumentException|\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertTrue(true);}}
    public function test_union_preserves_concavity_and_overlap_is_counted_once(): void
    {$d=U::selection(self::selection(),R::LIVING);$this->assertSame("\0",$d['rows'][1150][500]);$this->assertSame("\xff",$d['rows'][1250][1150]);
        $this->assertSame(1001*601+701*701-101*601,$d['usablePositivePixels']);$this->assertSame("\xff",$d['safeRows'][1300][1100]);
        $this->assertSame("\0",$d['safeRows'][1200][500]);$this->assertSame("\xff",$d['safeRows'][1206][500]);
        $s=self::selection();$s['regions']=array_reverse($s['regions']);$r=U::selection($s);$this->assertSame($d['usablePixelsSha256'],$r['usablePixelsSha256']);$this->assertSame($d['safePixelsSha256'],$r['safePixelsSha256']);}
    public function test_envelope_checks_every_pixel_not_corners_or_outer_bbox(): void
    {$s=self::selection();$s['regions']=[[200,1200,900,1800],[1000,1200,1800,1800]];$s['contactEnvelopes'][2]['box']=[850,1500,1120,1580];$this->rejects(fn()=>U::selection($s));
        $s=self::selection();$s['contactEnvelopes'][0]['box']=[330,1200,570,1280];$this->rejects(fn()=>U::selection($s));
        $s=self::selection();$s['contactEnvelopes'][0]['box']=[330,1110,570,1180];$this->rejects(fn()=>U::selection($s));}
    public function test_bounded_regions_roles_and_source_plane_cannot_be_widened(): void
    {foreach(['duplicate','tiny','fifth','plane','missing-parent','role'] as $kind){$s=self::selection();switch($kind){case 'duplicate':$s['regions'][]=$s['regions'][0];break;case 'tiny':$s['regions'][0]=[200,1200,210,1800];break;case 'fifth':$s['regions']=array_fill(0,5,[200,1200,1200,1800]);break;case 'plane':$s['planeEvidence']['status']='different_level';break;case 'missing-parent':array_pop($s['planeEvidence']['evidenceViewIds']);break;case 'role':$s['contactEnvelopes'][2]['objectId']='sofa';break;}$this->rejects(fn()=>U::selection($s,R::LIVING));}}
    public function test_new_protocol_keeps_source_hard_holds_and_candidate_actual_gates(): void
    {[$c,$v]=self::layout();$this->assertSame('ready_for_image',L::parse(V::response($v),$c)['status']);foreach(['known_obstruction','receiver_observability'] as $k){$b=$v;$b['draftEvidence']['sourceChecks'][$k]['status']='uncertain';$this->rejects(fn()=>L::parse(V::response($b),$c));}
        [$c,$v]=self::candidate();$this->assertSame('ready_for_masks',C::parse(V::response($v),$c)['status']);foreach(['visible_contacts','optical_dependencies','circulation','geometry'] as $k){$b=$v;$b['checks'][$k]['status']='uncertain';$this->rejects(fn()=>C::parse(V::response($b),$c));}
        $v['sourceFloor']['regions'][0][0]=199;$this->rejects(fn()=>C::parse(V::response($v),$c));}
    public function test_source_plan_lower_region_and_digest_are_required_before_image(): void
    {[$c,$v]=self::layout();$v['objects'][0]['box']=[900,300,1100,600];$this->rejects(fn()=>L::parse(V::response($v),$c));
        $p=$this->plan();$p['witness']['safePixelsSha256']=str_repeat('f',64);$q=$p;unset($q['inputFingerprint']);$p['inputFingerprint']=H::canonicalHash($q);$this->rejects(fn()=>app(U::class)->validate($p,$p['requestId']));}
    public function test_both_ordinals_have_identical_plain_numeric_source_geometry(): void
    {[$c,$v]=self::layout();$layout=new IguideLabRoomAssessment(['phase'=>'layout','result'=>L::parse(V::response($v),$c),'data'=>['contextSnapshot'=>['context'=>$c]]]);
        $prior=new IguideLabRoomAssessment(['result'=>['repairDecision'=>['eligible'=>true],'correction'=>['defects'=>[['checkId'=>'geometry','objectIds'=>['sofa'],'sourceAlternative'=>'Move inward using the same original source region.']]],'checks'=>['geometry'=>['reason'=>'Synthetic extent failed the unchanged frame guard.']]]]);
        $a=app(Image::class)->prompt($layout);$b=app(Image::class)->prompt($layout,$prior);$tail=U::prompt($v['sourceFloor']);$this->assertStringEndsWith($tail,$a);$this->assertStringEndsWith($tail,$b);
        $this->assertStringContainsString('[[200,1200,1200,1800],[1100,1100,1800,1800]]',$a);$this->assertStringContainsString('6-pixel inward clearance',$a);$this->assertStringContainsString('contact-plus-attenuation',$a);
        $this->assertStringNotContainsString('safePixelsSha256',$a);$this->assertStringNotContainsString(U::geometryBinding($v['sourceFloor'])['selectionSha256'],$a);}
    public function test_raw_mask_hole_or_gray_pixel_holds_without_repair_and_receipt_drift_rejects(): void
    {$p=$this->plan();foreach([null,[500,1400,0],[500,1400,0x808080]] as $missing){$im=imagecreatetruecolor(2048,2048);imagefill($im,0,0,0xffffff);if($missing)imagesetpixel($im,...$missing);ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);
        $r=['requestId'=>$p['requestId'],'providerRequestId'=>'synthetic','model'=>$p['model'],'inputFingerprint'=>$p['inputFingerprint'],'inputSha256'=>$p['inputSha256'],'rawResponseSha256'=>str_repeat('e',64),'status'=>'completed','masks'=>[app(LabMachineMaskPixels::class)->describe($bytes)]];
        $out=app(U::class)->associate($p,$r,$bytes);$this->assertSame($missing?'needs_review':'ready_for_room_support',$out['status']);if(!$missing){$this->assertSame($p['witness']['safePixelsSha256'],$out['floorWitness']['safePixelsSha256']);$r['inputSha256']=str_repeat('f',64);$this->rejects(fn()=>app(U::class)->associate($p,$r,$bytes));}}
    }
    public function test_default_off_and_v2_are_not_reinterpreted(): void
    {config(['iguide-lab.interior_floor_witness_enabled'=>true,'iguide-lab.interior_room_evidence_drafts_enabled'=>true,'iguide-lab.interior_floor_witness_union_enabled'=>false]);$r=['implementation'=>['workflow'=>['floorContract'=>U::snapshot()]]];$this->rejects(fn()=>W::selected($r));
        $old=['implementation'=>['workflow'=>['floorContract'=>W::snapshot()]]];$this->assertSame(W::VERSION,W::selected($old)['version']);config(['iguide-lab.interior_floor_witness_union_enabled'=>true]);$this->assertSame(U::VERSION,W::selected($r)['version']);$this->assertSame(W::VERSION,W::selected($old)['version']);
        [$c,$v]=IguideLabRoomEvidenceProtocolTest::layout();$this->assertSame(OldL::VERSION,OldL::parse(V::response($v),$c)['version']);$v['sourceFloor']=self::selection();$this->rejects(fn()=>OldL::parse(V::response($v),$c));
        [$c,$v]=self::candidate();$c['layout']['result']['version']=OldL::VERSION;$c['layout']['resultSha256']=H::canonicalHash($c['layout']['result']);$this->rejects(fn()=>C::context($c));}
    private function plan(): array
    {return app(U::class)->bind('296011dc-e305-4e25-ad8b-3045f049d0df',R::LIVING,self::selection(),str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),str_repeat('d',64));}
}
