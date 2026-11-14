<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabAnalysisRecipes,LabAnalysisSourceContext,LabInteriorDesignFramingProtocol as F,LabInteriorDesignCutProtocol as P};
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabInteriorDesignCutProtocolTest extends TestCase
{
    public static function fixture(): array
    {
        $test=new IguideLabSectionAnalysisProtocolTest('test_wire_keeps_exact_order_bytes_roles_schema_and_twenty_four_mebibyte_bound');
        $method=new \ReflectionMethod($test,'fixture');[, $c,$images]=$method->invoke($test);
        $c['preset']='interior-design';$c['style']='bohemian';$c['instructions']='';
        [$fc]=IguideLabInteriorDesignFramingProtocolTest::fixture();$fc['style']=$c['style'];$fc['nativeSourceSha256']=$c['sourceContext']['binding']['nativeSourceSha256'];
        $raw=IguideLabInteriorDesignFramingProtocolTest::candidate($fc);
        $raw['view']=array_intersect_key($c['sourceContext']['binding']['view'],array_flip(['yaw','pitch','hFov']));
        $result=F::parse(IguideLabInteriorDesignFramingProtocolTest::response($raw),$fc);
        $c['interiorFraming']=['id'=>(string)Str::uuid(),'context'=>$fc,'result'=>$result,'resultSha256'=>LabAnalysisRecipes::hash($result)];
        return [$c,$images];
    }
    public static function candidate(array $c): array
    {
        $targets=[];foreach($c['interiorFraming']['result']['targets'] as $i=>$t){unset($t['sourceOptics'],$t['evidenceViewIds']);
            $targets[]=$t+['registrationSurface'=>$i===0?'locally_rigid_drape':'planar','maskPlan'=>['prompt'=>$i===0?'sofa upholstery':'rug','box'=>$i===0?[300,350,1050,900]:[400,950,1500,1650]]];}
        return ['version'=>P::VERSION,'sourceCutSha256'=>$c['sourceSha256'],'preparedSourceSha256'=>$c['sourceContext']['views'][0]['sha256'],
            'nativeSourceSha256'=>$c['sourceContext']['binding']['nativeSourceSha256'],'sourceContextBindingSha256'=>$c['sourceContext']['bindingSha256'],
            'framingResultSha256'=>$c['interiorFraming']['resultSha256'],'style'=>$c['style'],'status'=>'ready_for_source_masks','targets'=>$targets,'foreground'=>[],
            'checks'=>array_fill_keys(P::CHECKS,['status'=>'pass','evidenceViewIds'=>LabAnalysisSourceContext::IDS,'reason'=>'The actual cut and original panorama justify these unchanged matte surfaces.']),
            'generationPrompt'=>'Restyle the original upholstery with visible woven detail and the rug with a Bohemian pattern; preserve original forms, folds, lighting, shadows and all other pixels.',
            'summary'=>'Two unchanged-form materials are suitable for machine source support verification.'];
    }
    public function test_actual_cut_parent_and_source_candidate_are_bound_without_image_authority(): void
    {
        [$c,$images]=self::fixture();$wire=P::request($c,$images);$value=P::parse(IguideLabInteriorDesignFramingProtocolTest::response(self::candidate($c)),$c);
        $this->assertSame($c,json_decode($wire['input'][0]['content'][0]['text'],true));
        $this->assertTrue($value['sourceMaskSelectionAuthorized']);$this->assertFalse($value['imageGenerationAuthorized']);$this->assertFalse($value['materialTransferAuthorized']);
        $this->assertArrayNotHasKey('sourceAnalysis',$value);$this->assertFalse($value['manualAcceptance']);
        [$images[0],$images[1]]=[$images[1],$images[0]];$this->rejects(fn()=>P::request($c,$images));
    }
    public function test_uncertain_cut_or_optics_is_held_even_when_source_framing_was_plausible(): void
    {
        [$c]=self::fixture();$good=self::candidate($c);
        foreach(P::CHECKS as $check){$bad=$good;$bad['checks'][$check]['status']='uncertain';$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($bad),$c));}
        $held=$good;$held['status']='needs_review';$held['targets']=[];$held['foreground']=[];$held['generationPrompt']=null;$held['checks']['optical_dependencies']['status']='uncertain';
        $value=P::parse(IguideLabInteriorDesignFramingProtocolTest::response($held),$c);$this->assertFalse($value['sourceMaskSelectionAuthorized']);$this->assertNull($value['generationPrompt']);
    }
    public function test_source_identity_scope_pose_and_original_context_cannot_be_changed(): void
    {
        [$c,$images]=self::fixture();$good=self::candidate($c);
        $changes=[fn($v)=>array_replace_recursive($v,['targets'=>[0=>['sourceDescription'=>'A different object']]]),
            fn($v)=>array_replace_recursive($v,['targets'=>[0=>['maskPlan'=>['box'=>[204,300,800,900]]]]]),
            fn($v)=>array_replace_recursive($v,['targets'=>[0=>['registrationSurface'=>'nonrigid']]]),
            fn($v)=>$v+['humanReviewed'=>true],
            function($v){$v['checks']['optical_dependencies']['evidenceViewIds']=['source','parent_source'];return $v;}];
        foreach($changes as $change)$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($change($good)),$c));
        $bad=$c;$bad['sourceContext']['binding']['view']['yaw']+=1;$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($good),$bad));
        $bad=$c;$bad['sourceContext']['views'][2]['role']='authoritative_edit_cut';$this->rejects(fn()=>P::request($bad,$images));
        $bad=$c;$bad['interiorFraming']['result']['style']='modern';$this->rejects(fn()=>P::request($bad,$images));
    }
    public function test_boxes_need_exact_pixel_bounds_and_foreground_has_independent_semantic_identity(): void
    {
        [$c]=self::fixture();$good=self::candidate($c);
        $good['foreground']=[['id'=>'foreground_1','description'=>'Original throw pillow in front of sofa upholstery.','maskPlan'=>['prompt'=>'throw pillow','box'=>[350,400,600,650]]]];
        $this->assertSame('foreground_1',P::parse(IguideLabInteriorDesignFramingProtocolTest::response($good),$c)['foreground'][0]['id']);
        $good['foreground'][0]['id']='material_1';$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($good),$c));
        $good=self::candidate($c);$good['targets'][0]['maskPlan']['box'][0]=300.5;$this->rejects(fn()=>P::parse(IguideLabInteriorDesignFramingProtocolTest::response($good),$c));
    }
    private function rejects(callable $fn): void
    {try{$fn();$this->fail('Unsupported Interior cut authority accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
}
