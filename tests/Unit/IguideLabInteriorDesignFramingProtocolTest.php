<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabCatalog,LabInteriorDesignFramingProtocol as P};
use Tests\TestCase;

class IguideLabInteriorDesignFramingProtocolTest extends TestCase
{
    public static function fixture(): array
    {
        [$old,$images]=IguideLabStagingFramingProtocolTest::fixture();
        unset($old['sourceSha256']);
        return [array_replace($old,['version'=>P::VERSION,'nativeSourceSha256'=>str_repeat('a',64),
            'preparedSourceSha256'=>$old['views'][0]['sha256']]),$images];
    }
    public static function candidate(array $context): array
    {
        $value=array_intersect_key($context,array_flip(['version','nativeSourceSha256','preparedSourceSha256','sourceAuthoritySha256','intentSha256','style']));
        return $value+['status'=>'framing_candidate','view'=>['yaw'=>20.0,'pitch'=>-25.0,'hFov'=>100.0],
            'targets'=>[
                ['id'=>'material_1','sourceDescription'=>'Complete matte sofa upholstery, excluding cushions and wooden frame.','surface'=>'upholstery','sourceOptics'=>'opaque_matte',
                    'plannedChange'=>'Use a recognizably woven material while retaining the exact sofa shape and source folds.','changeKinds'=>['material','texture'],'evidenceViewIds'=>['source','front']],
                ['id'=>'material_2','sourceDescription'=>'The existing complete rug, preserving its contact shadows.','surface'=>'rug','sourceOptics'=>'opaque_matte',
                    'plannedChange'=>'Apply a style-appropriate visible textile pattern at the original rug perspective.','changeKinds'=>['pattern'],'evidenceViewIds'=>['source','front']],
            ],'styleFeatures'=>[
                ['kind'=>'texture','description'=>'Visible woven upholstery consistent with the requested style.','targetIds'=>['material_1']],
                ['kind'=>'pattern','description'=>'A recognizable style-specific textile pattern on the original rug.','targetIds'=>['material_2']],
            ],'checks'=>array_fill_keys(P::CHECKS,['status'=>'verify_on_cut','evidenceViewIds'=>P::IDS,'reason'=>'Confirm the actual surfaces, margins and parent context on the extracted cut.']),
            'summary'=>'Existing furnishing forms can be inspected for a bounded material restyle.'];
    }
    public static function response(array $value): array {return IguideLabStagingFramingProtocolTest::response($value);}
    public function test_all_five_styles_only_authorize_framing_candidacy_and_preserve_exact_original_evidence(): void
    {
        [$c,$images]=self::fixture();
        foreach(array_keys(LabCatalog::STYLES) as $style){$c['style']=$style;$wire=P::request($c,$images);$parsed=P::parse(self::response(self::candidate($c)),$c);
            $this->assertSame($c,json_decode($wire['input'][0]['content'][0]['text'],true));
            $this->assertFalse($wire['store']);$this->assertSame('gpt-6-astra',$wire['model']);
            foreach(['imageGenerationAuthorized','segmentationAuthorized','materialTransferAuthorized','manualAcceptance','crossViewVerified'] as $key)$this->assertFalse($parsed[$key]);
            $this->assertArrayNotHasKey('generationPrompt',$parsed);
        }
        $this->assertNotSame($c['nativeSourceSha256'],$c['preparedSourceSha256']);
        $this->assertSame(hash_file('sha256',app_path('Services/IguideLab/LabAnalysisRequestV1.php')),P::snapshot()['wireCompilerSha256']);
    }
    public function test_palette_only_or_unsupported_source_constraints_cannot_become_framing_candidates(): void
    {
        [$c]=self::fixture();$good=self::candidate($c);
        $mutations=[
            function($v){foreach($v['targets'] as &$t)$t['changeKinds']=['colour'];return $v;},
            function($v){foreach($v['styleFeatures'] as &$f)$f['kind']='colour';return $v;},
            fn($v)=>array_replace_recursive($v,['targets'=>[0=>['sourceOptics'=>'transparent']]]),
            fn($v)=>array_replace_recursive($v,['targets'=>[0=>['sourceOptics'=>'reflective']]]),
            fn($v)=>array_replace_recursive($v,['checks'=>['optical_dependencies'=>['status'=>'blocked']]]),
            function($v){$v['checks']['optical_dependencies']['evidenceViewIds']=['source'];return $v;},
            fn($v)=>array_replace_recursive($v,['view'=>['hFov'=>121]]),
            fn($v)=>array_replace_recursive($v,['view'=>['pitch'=>31]]),
            fn($v)=>array_replace($v,['view'=>null]),
            fn($v)=>$v+['imageGenerationAuthorized'=>true],
            fn($v)=>$v+['generationPrompt'=>'Change all furniture.'],
        ];
        foreach($mutations as $change)$this->rejects(fn()=>P::parse(self::response($change($good)),$c));
    }
    public function test_style_and_native_preparation_intent_and_evidence_are_all_bound(): void
    {
        [$c,$images]=self::fixture();$good=self::candidate($c);
        foreach(['nativeSourceSha256','preparedSourceSha256','sourceAuthoritySha256','intentSha256'] as $key){$bad=$good;$bad[$key]=str_repeat('0',64);$this->rejects(fn()=>P::parse(self::response($bad),$c));}
        $bad=$good;$bad['style']='bohemian';$this->rejects(fn()=>P::parse(self::response($bad),$c));
        [$images[2],$images[3]]=[$images[3],$images[2]];$this->rejects(fn()=>P::request($c,$images));
        $c['style']=[];$this->rejects(fn()=>P::request($c,$images));
    }
    public function test_semantic_ids_are_sequential_unique_and_not_provider_array_position_authority(): void
    {
        [$c]=self::fixture();$good=self::candidate($c);
        $changes=[fn($v)=>array_replace_recursive($v,['targets'=>[1=>['id'=>'material_1']]]),
            fn($v)=>array_replace_recursive($v,['styleFeatures'=>[0=>['targetIds'=>['unknown']]]]),
            fn($v)=>array_replace_recursive($v,['styleFeatures'=>[0=>['targetIds'=>[['nested']]]]]),
            fn($v)=>array_replace_recursive($v,['targets'=>[0=>['changeKinds'=>[['nested']]]]]),
            fn($v)=>array_replace_recursive($v,['checks'=>['existing_forms'=>['evidenceViewIds'=>[['nested']]]]])];
        foreach($changes as $change)$this->rejects(fn()=>P::parse(self::response($change($good)),$c));
    }
    public function test_hold_and_incomplete_model_receipt_never_authorize_free_extraction(): void
    {
        [$c]=self::fixture();$v=self::candidate($c);$v['status']='needs_review';$v['view']=null;$v['checks']['optical_dependencies']['status']='blocked';
        $this->assertNull(P::parse(self::response($v),$c)['view']);
        $v['view']=['yaw'=>0,'pitch'=>0,'hFov'=>90];$this->rejects(fn()=>P::parse(self::response($v),$c));
        $response=self::response(self::candidate($c));$response['status']='incomplete';$this->rejects(fn()=>P::parse($response,$c));
        $response['status']='completed';$response['model']='different';$this->rejects(fn()=>P::parse($response,$c));
    }
    public function test_input_budget_is_checked_before_any_provider_request(): void
    {
        [$c,$images]=self::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]).str_repeat('x',24*1024*1024);
        $images[0]='data:image/png;base64,'.base64_encode($bytes);$c['views'][0]['sha256']=$c['preparedSourceSha256']=hash('sha256',$bytes);
        $this->rejects(fn()=>P::request($c,$images));
    }
    private function rejects(callable $fn): void
    {
        try{$fn();$this->fail('Unsupported material authority accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}
    }
}
