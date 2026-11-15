<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabRoomFloorWitness as W,LabRoomFurnishingMaskPlan as Old,LabMachineMaskPixels,LabMachineSegmentationPlan as H,LabInteriorRoomProfiles as R,
    LabRoomFurnishingLayoutProtocol as L,LabRoomFurnishingCandidateProtocol as C,LabInteriorDesignProtocol as V};
use Tests\TestCase;

class IguideLabRoomFloorWitnessTest extends TestCase
{
    private function plan(array $box=[64,64,95,95]): array
    {return app(W::class)->bind('296011dc-e305-4e25-ad8b-3045f049d0df',R::BEDROOM,['status'=>'opaque_matte','prompt'=>W::LABEL,'box'=>$box],str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),str_repeat('d',64));}
    private function png(array $box,?array $missing=null): string
    {
        $im=imagecreatetruecolor(2048,2048);imagefilledrectangle($im,...[...$box,0xffffff]);
        if($missing!==null)imagesetpixel($im,$missing[0],$missing[1],0);ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;
    }
    private function receipt(array $p,string $bytes): array
    {return ['requestId'=>$p['requestId'],'providerRequestId'=>'synthetic-floor','model'=>$p['model'],'inputFingerprint'=>$p['inputFingerprint'],
        'inputSha256'=>$p['inputSha256'],'rawResponseSha256'=>str_repeat('e',64),'status'=>'completed','masks'=>[app(LabMachineMaskPixels::class)->describe($bytes)]];}
    public function test_larger_mask_uses_real_pixels_and_only_the_interior_witness_is_eligible(): void
    {
        $p=$this->plan();$bytes=$this->png([0,0,190,190]);$r=$this->receipt($p,$bytes);$out=app(W::class)->associate($p,$r,$bytes);
        $this->assertSame('ready_for_room_support',$out['status']);$this->assertSame([], $p['payload']['box_prompts']);$this->assertSame(1,$p['payload']['max_masks']);
        $this->assertSame(1024,$out['associations'][0]['witnessPixels']);$this->assertFalse($out['associations'][0]['semanticClassVerified']);
        $this->assertSame('support_eligibility_only',$out['restrictionUsage']);$this->assertFalse($out['automaticAcceptance']);
        $this->assertSame(hash('sha256',$bytes),$out['floorWitness']['rawMaskSha256']);
        $raw=str_repeat("\0",2048*2048);for($y=64;$y<=95;$y++)$raw=substr_replace($raw,str_repeat("\xff",32),$y*2048+64,32);
        $this->assertSame(hash('sha256',$raw),$out['floorWitness']['usablePixelsSha256']);
    }
    public function test_empty_sparse_partial_and_wrong_plane_missing_witness_hold_without_box_iou(): void
    {
        $p=$this->plan();foreach([[[0,0,0,0],null],[[64,64,64,64],null],[[0,0,190,190],[80,80]],[[120,120,190,190],null]] as [$box,$missing]){
            $bytes=$this->png($box,$missing);$out=app(W::class)->associate($p,$this->receipt($p,$bytes),$bytes);$this->assertSame('needs_review',$out['status']);}
    }
    public function test_old_selection_or_receipt_cannot_be_reinterpreted_as_v2(): void
    {
        $this->rejects(fn()=>app(W::class)->bind('296011dc-e305-4e25-ad8b-3045f049d0df',R::BEDROOM,['status'=>'opaque_matte','prompt'=>'floor','box'=>[64,64,95,95]],str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),str_repeat('d',64)));
        $p=$this->plan();$bytes=$this->png([0,0,190,190]);$r=$this->receipt($p,$bytes);
        foreach(['inputSha256','inputFingerprint','requestId','model'] as $key){$bad=$r;$bad[$key]='changed';$this->rejects(fn()=>app(W::class)->associate($p,$bad,$bytes));}
        $bad=$p;$bad['witness']['nativeSourceSha256']=str_repeat('f',64);$this->rejects(fn()=>app(W::class)->validate($bad,$bad['requestId']));
        $this->rejects(fn()=>app(W::class)->associate($p,$r,$this->png([0,0,190,190],[80,80])));
        $old=app(Old::class)->bind($p['requestId'],'source-floor',R::BEDROOM,[['box'=>[64,64,95,95]]],str_repeat('a',64),str_repeat('c',64),str_repeat('d',64));
        $this->rejects(fn()=>app(W::class)->validate($old,$old['requestId']));$this->assertCount(1,$old['payload']['box_prompts']);
    }
    public function test_default_off_and_reserved_recipe_selection_are_closed(): void
    {
        config(['iguide-lab.interior_floor_witness_enabled'=>false]);$recipe=['implementation'=>['workflow'=>['floorContract'=>W::snapshot()]]];
        $this->assertNull(W::selected([]));$this->rejects(fn()=>W::selected($recipe));config(['iguide-lab.interior_floor_witness_enabled'=>true]);
        $this->assertSame(W::snapshot(),W::selected($recipe));$this->assertNull(W::selected([]));
        $recipe['implementation']['workflow']['floorContract']['version']='unknown';$this->rejects(fn()=>W::selected($recipe));
    }
    public function test_explicit_witness_wire_and_parser_keep_public_shape_and_old_request_unchanged(): void
    {
        foreach([L::class,C::class] as $protocol){
            [$c,$images,$wire]=IguideLabRoomFurnishingProtocolTest::wireFixture($protocol,R::BEDROOM);$old=H::canonicalHash($wire);
            $new=W::request($wire,W::snapshot(),$protocol::PHASE);
            $this->assertSame([W::LABEL],$new['text']['format']['schema']['properties']['sourceFloor']['anyOf'][0]['properties']['prompt']['enum']);
            $this->assertStringContainsString('INTERIOR SUPPORT WITNESS',$new['instructions']);$this->assertStringContainsString('at least 16 pixels',$new['instructions']);
            $this->assertSame($old,H::canonicalHash($protocol::request($c,$images)));
            $value=IguideLabRoomFurnishingProtocolTest::value($protocol,$c);$this->rejects(fn()=>W::result($value,$protocol::PHASE));
            $value['sourceFloor']['prompt']=W::LABEL;
            if($protocol===C::class){$c['layout']['result']['sourceFloor']['prompt']=W::LABEL;$c['layout']['resultSha256']=H::canonicalHash($c['layout']['result']);$value['contextSha256']=H::canonicalHash($c);}
            $parsed=$protocol::parse(V::response($value),$c);$this->assertSame($parsed,W::result($parsed,$protocol::PHASE));
            $value['status']='needs_review';$value['objects']=[];$value['sourceFloor']=null;$value['styleFeatures']=[];
            $this->assertSame($value,W::result($value,$protocol::PHASE));
        }
    }
    // Private retained-mask replay belongs to the external room-floor-witness proof.
    // Portable CI exercises the same missing/interior/wrong-plane coverage cases above.
    private function rejects(callable $fn): void
    {try{$fn();$this->fail('Invalid authority accepted.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException|\InvalidArgumentException $e){$this->assertTrue(true);}}
}
