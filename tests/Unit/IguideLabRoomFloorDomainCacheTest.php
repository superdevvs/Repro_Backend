<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabRoomFloorWitnessUnion as U,LabInteriorRoomProfiles as R,LabMachineSegmentationPlan as H};
use Tests\TestCase;

class IguideLabRoomFloorDomainCacheTest extends TestCase
{
    private function rejects(callable $f):void
    {try{$f();$this->fail('Changed geometry or authority was accepted.');}catch(\InvalidArgumentException|\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertTrue(true);}}

    public function test_repeated_domain_is_exact_and_returned_values_cannot_poison_cache():void
    {
        $s=IguideLabRoomFloorUnionTest::selection();$a=U::domain($s['regions']);
        $this->assertSame('484850731de7fd1c4a79f590ca918fbff6c14b5cf7ffa0e740b8ada30a9ff13e',$a['usablePixelsSha256']);
        $this->assertSame('36a55fbe11e4ec5b2f421004d7b75052dd9a432098a1ee8f77fb54ebf13294d6',$a['safePixelsSha256']);
        $copy=U::domain($s['regions']);$this->assertSame($a,$copy);
        $row=&$copy['rows'][1250];$row[1150]="\0";$copy['safeRows'][1300][1100]="\0";$copy['safePixelsSha256']=str_repeat('f',64);unset($copy['rows'][0],$row);
        $this->assertSame($a,U::domain($s['regions']));
    }

    public function test_other_integer_geometry_replaces_cached_value_without_aliasing():void
    {
        $s=IguideLabRoomFloorUnionTest::selection();$a=U::domain($s['regions']);
        $other=$s['regions'];$other[0][0]++;$b=U::domain($other);
        $this->assertNotSame($a['usablePixelsSha256'],$b['usablePixelsSha256']);
        $this->assertSame("\xff",$a['rows'][1300][200]);$this->assertSame("\0",$b['rows'][1300][200]);
        $this->assertSame($a,U::domain($s['regions']));
        $reverse=U::domain(array_reverse($s['regions']));$this->assertSame($a,$reverse);
    }

    public function test_cache_hit_never_coerces_or_bypasses_region_validation():void
    {
        $r=IguideLabRoomFloorUnionTest::selection()['regions'];U::domain($r);
        $float=$r;$float[0][0]=200.0;$string=$r;$string[0][0]='200';$outside=$r;$outside[0][0]=-1;
        $tiny=$r;$tiny[0][2]=$tiny[0][0]+14;
        foreach([$float,$string,$outside,$tiny,[],[$r[0],$r[0]],array_fill(0,5,$r[0]),['first'=>$r[0]]] as $bad)$this->rejects(fn()=>U::domain($bad));
        $this->assertSame('484850731de7fd1c4a79f590ca918fbff6c14b5cf7ffa0e740b8ada30a9ff13e',U::domain($r)['usablePixelsSha256']);
    }

    public function test_warm_raster_does_not_cache_plane_profile_envelope_or_planned_object_checks():void
    {
        $s=IguideLabRoomFloorUnionTest::selection();U::selection($s,R::LIVING);
        $bad=$s;$bad['planeEvidence']['status']='different_level';$this->rejects(fn()=>U::selection($bad,R::LIVING));
        $bad=$s;array_pop($bad['planeEvidence']['evidenceViewIds']);$this->rejects(fn()=>U::selection($bad,R::LIVING));
        $this->rejects(fn()=>U::selection($s,R::BEDROOM));
        $bad=$s;$bad['contactEnvelopes'][0]['box']=[330,1200,570,1280];$this->rejects(fn()=>U::selection($bad,R::LIVING));
        $objects=[['id'=>'sofa','box'=>[900,300,1100,600]],['id'=>'coffee_table','box'=>[1400,650,1699,1549]],['id'=>'chair','box'=>[850,650,1149,1549]]];
        $this->rejects(fn()=>U::selection($s,R::LIVING,$objects));
    }

    public function test_warm_raster_does_not_bypass_pixel_digest_source_receipt_or_plan_binding():void
    {
        $service=app(U::class);$id='296011dc-e305-4e25-ad8b-3045f049d0df';
        $plan=$service->bind($id,R::LIVING,IguideLabRoomFloorUnionTest::selection(),str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),str_repeat('d',64));
        foreach(['usablePixelsSha256','safePixelsSha256'] as $key){$bad=$plan;$bad['witness'][$key]=str_repeat('f',64);$q=$bad;unset($q['inputFingerprint']);$bad['inputFingerprint']=H::canonicalHash($q);$this->rejects(fn()=>$service->validate($bad,$id));}
        foreach(['inputSha256','assessmentProposalSha256','contextSha256'] as $key){$bad=$plan;$bad[$key]=str_repeat('f',64);$this->rejects(fn()=>$service->validate($bad,$id));}
        $bad=$plan;$bad['witness']['nativeSourceSha256']=str_repeat('f',64);$this->rejects(fn()=>$service->validate($bad,$id));
        $receipt=['requestId'=>$id,'providerRequestId'=>'synthetic','model'=>$plan['model'],'inputFingerprint'=>$plan['inputFingerprint'],'inputSha256'=>str_repeat('f',64),'rawResponseSha256'=>str_repeat('e',64),'status'=>'completed','masks'=>[]];
        $this->rejects(fn()=>$service->associate($plan,$receipt,''));
        $this->rejects(fn()=>$service->payload($plan,'changed original bytes'));
    }
}
