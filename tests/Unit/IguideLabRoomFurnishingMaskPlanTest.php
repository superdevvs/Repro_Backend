<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabRoomFurnishingMaskPlan as P,LabInteriorRoomProfiles as R,LabMachineSegmentationPlan as H};
use Tests\TestCase;

class IguideLabRoomFurnishingMaskPlanTest extends TestCase
{
    private function fixture(string $role='candidate-furniture',string $profile=R::BEDROOM): array
    {
        $boxes=$role==='source-floor'?[[0,800,2047,2047]]:[[650,550,1400,1500],[300,600,600,1000],[1450,600,1740,1000]];
        $plan=app(P::class)->bind('296011dc-e305-4e25-ad8b-3045f049d0df',$role,$profile,array_map(fn($b)=>['box'=>$b],$boxes),str_repeat('a',64),str_repeat('b',64),str_repeat('c',64));
        $masks=[];foreach($boxes as $i=>$b)$masks[]=['sha256'=>hash('sha256','mask'.$i),'width'=>2048,'height'=>2048,
            'bounds'=>[$b[0],$b[1],$b[2]+1,$b[3]+1],'positivePixels'=>($b[2]+1-$b[0])*($b[3]+1-$b[1])];
        return [$plan,['requestId'=>$plan['requestId'],'providerRequestId'=>'retained-fake-request','model'=>$plan['model'],
            'inputFingerprint'=>$plan['inputFingerprint'],'inputSha256'=>$plan['inputSha256'],'rawResponseSha256'=>str_repeat('d',64),'status'=>'completed','masks'=>$masks]];
    }
    public function test_reordered_masks_associate_by_unique_geometry_without_claiming_semantic_identity(): void
    {
        [$p,$r]=$this->fixture();$r['masks']=array_reverse($r['masks']);$out=app(P::class)->associate($p,$r);
        $this->assertSame('ready_for_room_support',$out['status']);$this->assertSame([2,1,0],array_column($out['associations'],'maskIndex'));
        $this->assertSame(R::get(R::BEDROOM)['roles'],array_column($out['associations'],'targetId'));
        $this->assertFalse($out['automaticAcceptance']);$this->assertFalse($out['humanReviewed']);
        foreach($out['associations'] as $a)$this->assertFalse($a['semanticClassVerified']);
    }
    public function test_ambiguous_duplicate_missing_and_guard_crossing_masks_hold(): void
    {
        [$p,$r]=$this->fixture();$bad=$r;$bad['masks'][1]=$bad['masks'][0];
        $this->assertSame('needs_review',app(P::class)->associate($p,$bad)['status']);
        $bad=$r;$bad['masks']=[];$this->assertSame('needs_review',app(P::class)->associate($p,$bad)['status']);
        $bad=$r;$bad['masks'][0]['bounds'][0]=204;$this->assertSame('needs_review',app(P::class)->associate($p,$bad)['status']);
        $bad=$r;$bad['masks'][0]['positivePixels']=0;$this->assertSame('needs_review',app(P::class)->associate($p,$bad)['status']);
    }
    public function test_original_floor_and_living_roles_are_explicit_separate_plans(): void
    {
        [$p,$r]=$this->fixture('source-floor');$this->assertSame(['floor'],$p['expectedIdentities']);$this->assertSame(1,$p['payload']['max_masks']);
        $this->assertSame('ready_for_room_support',app(P::class)->associate($p,$r)['status']);
        [$p,$r]=$this->fixture('candidate-furniture',R::LIVING);$this->assertSame(['sofa','coffee_table','chair'],$p['expectedIdentities']);
        $this->assertSame(3,$p['payload']['max_masks']);$this->assertFalse($p['automaticRetry']);
    }
    public function test_receipt_and_plan_tampering_never_become_new_authority(): void
    {
        [$p,$r]=$this->fixture();$r['inputSha256']=str_repeat('0',64);
        $this->rejects(fn()=>app(P::class)->associate($p,$r));
        [$p]=$this->fixture();$p['expectedIdentities'][0]='sofa';$x=$p;unset($x['inputFingerprint']);$p['inputFingerprint']=H::canonicalHash($x);
        $this->rejects(fn()=>app(P::class)->validate($p,$p['requestId']));
    }
    private function rejects(callable $f): void
    {try{$f();$this->fail('Invalid mask authority accepted.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException|\InvalidArgumentException $e){$this->assertTrue($e instanceof \InvalidArgumentException||$e->getStatusCode()===422);}}
}
