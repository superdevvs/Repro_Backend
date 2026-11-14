<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabInteriorDesignMaskPlan as P,LabMachineSegmentationPlan,LabMachineSegmentationTransport};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
class IguideLabInteriorDesignMaskPlanTest extends TestCase
{
    private function plan(string $role='source-material'): array
    {return app(P::class)->bind((string)Str::uuid(),$role,['id'=>$role==='source-foreground'?'foreground_1':'material_1','maskPlan'=>['prompt'=>'matte upholstery','box'=>[300,400,800,900]]],str_repeat('a',64),str_repeat('b',64),str_repeat('c',64));}
    private function receipt(array $p): array
    {return ['requestId'=>$p['requestId'],'providerRequestId'=>'sam-fixture','model'=>LabMachineSegmentationTransport::MODEL,'inputFingerprint'=>$p['inputFingerprint'],'inputSha256'=>$p['inputSha256'],
        'rawResponseSha256'=>str_repeat('d',64),'status'=>'completed','masks'=>[['sha256'=>str_repeat('e',64),'width'=>2048,'height'=>2048,'bounds'=>[300,400,801,901],'positivePixels'=>200000]]];}
    public function test_each_bound_role_has_one_explicit_identity_and_no_semantic_or_human_claim(): void
    {
        foreach(['source-material','candidate-material','source-foreground'] as $role){$p=$this->plan($role);$result=app(P::class)->associate($p,$this->receipt($p));
            $this->assertSame('ready_for_material_support',$result['status']);$this->assertSame($role,$result['associations'][0]['role']);
            $this->assertSame([$p['targetId']],$p['expectedIdentities']);$this->assertFalse($result['associations'][0]['semanticClassVerified']);$this->assertFalse($result['humanReviewed']);
            $this->assertSame(1,$p['maximumPostAttempts']);$this->assertFalse($p['automaticRetry']);}
    }
    public function test_changed_role_input_uuid_or_plan_cannot_rebind_a_receipt(): void
    {
        $p=$this->plan();$r=$this->receipt($p);
        foreach(['requestId','inputSha256','inputFingerprint'] as $key){$bad=$r;$bad[$key]=$key==='requestId'?(string)Str::uuid():str_repeat('f',64);$this->rejects(fn()=>app(P::class)->associate($p,$bad));}
        $bad=$p;$bad['maskRole']='candidate-material';$this->rejects(fn()=>app(P::class)->validate($bad,$bad['requestId']));
        $bad=$p;$bad['payload']['prompt']='different';$this->rejects(fn()=>app(P::class)->validate($bad,$bad['requestId']));
    }
    public function test_wrong_count_fragment_or_outside_region_has_no_ready_mask_association(): void
    {
        $p=$this->plan();$r=$this->receipt($p);
        $changes=[function($x){$x['masks']=[];return $x;},function($x){$x['masks'][]=$x['masks'][0];return $x;},
            function($x){$x['masks'][0]['bounds']=[0,0,400,400];return $x;},function($x){$x['masks'][0]['bounds']=[400,500,450,550];return $x;},
            function($x){$x['masks'][0]['positivePixels']=700001;return $x;}];
        foreach($changes as $change){$held=app(P::class)->associate($p,$change($r));$this->assertSame('needs_review',$held['status']);$this->assertSame([],$held['associations']);}
    }
    public function test_even_rehashed_plan_cannot_widen_dimensions_or_enable_automatic_retry(): void
    {
        foreach(['width'=>1024,'automaticRetry'=>true,'semanticClassVerified'=>true] as $key=>$value){$p=$this->plan();$p[$key]=$value;unset($p['inputFingerprint']);$p['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($p);$this->rejects(fn()=>app(P::class)->validate($p,$p['requestId']));}
    }
    private function rejects(callable $fn): void
    {try{$fn();$this->fail('Changed material mask authority accepted.');}catch(HttpException|\InvalidArgumentException $e){$this->assertTrue($e instanceof \InvalidArgumentException||$e->getStatusCode()===422);}}
}
