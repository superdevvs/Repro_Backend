<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabAnalysisRecipes,LabLifestyleOpticalProtocol,LabLifestyleOriginalDetailTransport as T,LabLifestyleOriginalOpticalCutProtocol};
use Tests\TestCase;

class IguideLabLifestyleOriginalDetailTransportTest extends TestCase
{
    public function test_framing_only_native_details_change_and_legacy_wire_remains_exact(): void
    {
        [$c,$images]=IguideLabLifestyleOpticalProtocolTest::fixture();
        $this->assertSame(LabLifestyleOpticalProtocol::request($c,$images),T::framing($c,$images,T::snapshot()));
        [$c,$images]=IguideLabLifestyleOpticalProtocolTest::followup($c,$images);
        $old=LabLifestyleOpticalProtocol::request($c,$images);
        $this->assertSame($old,T::framing($c,$images,null));
        $new=T::framing($c,$images,T::snapshot());$levels=[];
        foreach($new['input'][0]['content'] as &$part)if(($part['type']??null)==='input_image'){$levels[]=$part['detail'];$part['detail']='high';}
        unset($part);
        $this->assertSame([...array_fill(0,7,'high'),'original'],$levels);$this->assertSame($old,$new);
    }

    public function test_cut_recipe_has_new_closed_identity_with_identical_semantics_and_only_native_wire_change(): void
    {
        [$c,$images,$answer]=IguideLabLifestyleOpticalCutProtocolTest::fixture(2);$recipes=app(LabAnalysisRecipes::class);
        $old=$recipes->snapshot(LabAnalysisRecipes::LIFESTYLE_OPTICAL_CUT);$new=$recipes->snapshot(LabAnalysisRecipes::LIFESTYLE_ORIGINAL_OPTICAL_CUT);
        $restored=$new['recipe'];$restored['instructionsVersion']=$old['recipe']['instructionsVersion'];$restored['compilerVersion']=$old['recipe']['compilerVersion'];
        $this->assertSame($old['recipe'],$restored);$this->assertSame($old['schemaSha256'],$new['schemaSha256']);
        $this->assertSame(LabLifestyleOriginalOpticalCutProtocol::VERSION,$new['recipe']['compilerVersion']);
        $wire=$recipes->request($new,$c,$images);$levels=[];
        foreach($wire['input'][0]['content'] as &$part)if(($part['type']??null)==='input_image'){$levels[]=$part['detail'];$part['detail']='high';}unset($part);
        $this->assertSame([...array_fill(0,6,'high'),'original','original'],$levels);$this->assertSame($recipes->request($old,$c,$images),$wire);
        $response=IguideLabLifestyleFramingProtocolTest::response($answer);
        $this->assertSame($recipes->parse($old,$response,$c),$recipes->parse($new,$response,$c));
    }

    public function test_original_patch_and_dimension_limits_are_checked_without_silent_resize(): void
    {
        $this->assertSame(4096,T::patches(2048,2048));$this->assertSame(30000,T::patches(6400,4800));$this->assertSame(2048,T::patches(65535,1));
        foreach([[6401,4800],[65536,1],[0,2048],[2048,2048.0]] as [$w,$h]){
            try{T::patches($w,$h);$this->fail('Invalid original dimensions accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}
        }
    }

    public function test_policy_and_actual_detail_hash_are_not_replaceable(): void
    {
        [$c,$images]=IguideLabLifestyleOpticalProtocolTest::fixture();[$c,$images]=IguideLabLifestyleOpticalProtocolTest::followup($c,$images);
        $policy=T::snapshot();$policy['nativeDetail']='high';
        try{T::framing($c,$images,$policy);$this->fail('Transport policy changed.');}catch(\InvalidArgumentException $e){$this->assertStringContainsString('transport changed',$e->getMessage());}
        $wire=LabLifestyleOpticalProtocol::request($c,$images);$descriptors=$c['views'];$descriptors[7]['sha256']=str_repeat('0',64);
        $this->expectException(\InvalidArgumentException::class);T::apply($wire,array_column($c['views'],'id'),$descriptors);
    }

    public function test_original_transport_retains_existing_total_raw_byte_limit(): void
    {
        [$c,$images]=IguideLabLifestyleOpticalProtocolTest::fixture();[$c,$images]=IguideLabLifestyleOpticalProtocolTest::followup($c,$images);
        $wire=LabLifestyleOpticalProtocol::request($c,$images);
        $wire['input'][0]['content'][2]['image_url']='data:image/png;base64,'.base64_encode(str_repeat('x',24*1024*1024+1));
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('byte limit');
        T::apply($wire,array_column($c['views'],'id'),$c['views']);
    }
}
