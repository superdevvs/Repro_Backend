<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabLifestyleOpticalCutProtocol as P,LabLifestyleOpticalProtocol,LabAnalysisRecipes,LabAnalysisSourceContext};
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabLifestyleOpticalCutProtocolTest extends TestCase
{
    public static function fixture(int $count=2): array
    {
        $test=new IguideLabLifestyleFramingProtocolTest('test_cut_gate_is_independent_and_uncertainty_prevents_authority');
        [$c,$images,$v]=(new \ReflectionMethod($test,'cut'))->invoke($test);
        $b=&$c['lifestyleFraming'];$b['version']=LabLifestyleOpticalProtocol::VERSION;
        $b['opticalDetails']=['version'=>'lifestyle-bound-native-optical-context/1','sourceAuthoritySha256'=>str_repeat('a',64),'responseSha256'=>str_repeat('b',64),
            'parent'=>$count?['id'=>(string)Str::uuid(),'resultSha256'=>str_repeat('c',64),'responseSha256'=>str_repeat('d',64)]:null,
            'manifestSha256'=>$count?str_repeat('e',64):null,'views'=>[]];
        for($i=0;$i<$count;$i++){$image=$images[0];$id='source_detail_'.($i+1);$b['opticalDetails']['views'][]=['id'=>$id,'sha256'=>hash('sha256',base64_decode(explode(',',$image,2)[1])),
            'width'=>2048,'height'=>2048,'nativeSourceSha256'=>$b['nativeSourceSha256'],'pose'=>['yaw'=>$i*90,'pitch'=>0,'hFov'=>40],
            'sourceSampling'=>IguideLabLifestyleOpticalProtocolTest::sampling(11008,5504)];$c['viewIds'][]=$id;$images[]=$image;}
        $v['lifestyleReadiness']['version']=P::GATE_VERSION;$v['lifestyleReadiness']['checks']['optical_dependencies']['evidenceViewIds']=$c['viewIds'];
        return [$c,$images,$v];
    }
    public function test_exact_six_base_inputs_plus_zero_to_two_real_native_pixels(): void
    {
        foreach([0,1,2] as $n){[$c,$images,$v]=self::fixture($n);$recipe=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_OPTICAL_CUT);
            $p=app(LabAnalysisRecipes::class)->request($recipe,$c,$images);$content=$p['input'][0]['content'];$this->assertCount(13+2*$n,$content);
            foreach($images as $i=>$im)$this->assertSame($im,$content[2+2*$i]['image_url']);
            $this->assertSame($c,json_decode($content[0]['text'],true));$parsed=app(LabAnalysisRecipes::class)->parse($recipe,IguideLabLifestyleFramingProtocolTest::response($v),$c);
            $this->assertTrue($parsed['lifestyleReadiness']['allRequiredChecksPass']);$this->assertFalse($parsed['automaticGeneration']);}
    }
    public function test_absent_detail_pixels_wrong_order_or_sampling_do_not_compile(): void
    {
        [$c,$images]=self::fixture();$recipe=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_OPTICAL_CUT)['recipe'];
        $changes=[fn($c)=>array_replace_recursive($c,['lifestyleFraming'=>['opticalDetails'=>['views'=>[0=>['sha256'=>str_repeat('0',64)]]]]]),
            fn($c)=>array_replace_recursive($c,['lifestyleFraming'=>['opticalDetails'=>['views'=>[0=>['sourceSampling'=>['independentOpticalResolutionKnown'=>true]]]]]]),
            fn($c)=>array_replace_recursive($c,['lifestyleFraming'=>['opticalDetails'=>['version'=>'unknown']]]),
            fn($c)=>array_replace($c,['viewIds'=>LabAnalysisSourceContext::IDS])];
        foreach($changes as $change){try{P::compile($recipe,$change($c),$images);$this->fail('Invalid native context accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
        $this->expectException(\InvalidArgumentException::class);P::compile($recipe,$c,array_slice($images,0,6));
    }
    public function test_optics_must_cite_all_supplied_details_and_source_parent_and_holds_stay_held(): void
    {
        [$c,,$v]=self::fixture();$good=$v;
        foreach([['source','parent_source','source_detail_1'],['source_detail_1','source_detail_2','parent_source'],['source','source_detail_1','source_detail_2']] as $ids){
            $v=$good;$v['lifestyleReadiness']['checks']['optical_dependencies']['evidenceViewIds']=$ids;
            try{P::parse(IguideLabLifestyleFramingProtocolTest::response($v),$c);$this->fail('Missing mandatory observation accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
        $v=$good;$v['lifestyleReadiness']['checks']['optical_dependencies']['status']='uncertain';
        $this->assertFalse(P::parse(IguideLabLifestyleFramingProtocolTest::response($v),$c)['lifestyleReadiness']['allRequiredChecksPass']);
    }
    public function test_native_details_do_not_expand_legacy_six_input_recipe(): void
    {
        [$c,$images]=self::fixture();$recipe=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_CUT)['recipe'];
        $this->expectException(\InvalidArgumentException::class);\App\Services\IguideLab\LabLifestyleCutProtocol::compile($recipe,$c,$images);
    }
}
