<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabLifestyleFramingProtocol as P,LabAnalysisRecipes,LabLifestyleCutProtocol,LabAnalysisSourceContext,LabScenePlanProtocol};
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabLifestyleFramingProtocolTest extends TestCase
{
    public static function response(array $value): array {return ['id'=>'resp-framing','model'=>'gpt-6-astra','status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($value,JSON_THROW_ON_ERROR)]]]]];}
    public static function candidate(array $context): array
    {return ['version'=>P::VERSION,'sourceSha256'=>$context['sourceSha256'],'intentSha256'=>$context['intentSha256'],'status'=>'framing_candidate','sourcePeople'=>'none_observed','coherentGroup'=>'plausible',
        'view'=>['yaw'=>12.0,'pitch'=>-25.0,'hFov'=>110.0],'constraints'=>array_fill_keys(P::CONSTRAINTS,['status'=>'verify_on_cut','evidenceViewIds'=>['source','front'],'reason'=>'Check actual frame and parent context before generation.']),'summary'=>'One plausible framing for independent cut verification.'];}
    public static function fixture(): array
    {
        $images=[];$views=[];
        foreach(P::IDS as $i=>$id){$w=$i===0?2048:1024;$im=imagecreatetruecolor($w,1024);imagefill($im,0,0,imagecolorallocate($im,30+$i*20,90,100));ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);
            $views[]=['id'=>$id,'sha256'=>hash('sha256',$bytes),'width'=>$w,'height'=>1024];$images[]='data:image/png;base64,'.base64_encode($bytes);}
        return [['version'=>P::VERSION,'sourceSha256'=>str_repeat('a',64),'sourceAuthoritySha256'=>str_repeat('b',64),'intentSha256'=>str_repeat('c',64),'style'=>null,'instructions'=>'','views'=>$views],$images];
    }
    public function test_original_evidence_order_hashes_budget_and_no_generation_authority(): void
    {
        [$context,$images]=self::fixture();$wire=P::request($context,$images);$parsed=P::parse(self::response(self::candidate($context)),$context);
        $this->assertFalse($wire['store']);$this->assertSame($context,json_decode($wire['input'][0]['content'][0]['text'],true));
        $this->assertStringNotContainsString('maxLength',json_encode($wire['text']['format']['schema']));
        $this->assertFalse($parsed['imageGenerationAuthorized']);$this->assertFalse($parsed['manualAcceptance']);$this->assertSame('verify_on_cut',$parsed['constraints']['circulation']['status']);
        [$images[2],$images[3]]=[$images[3],$images[2]];$this->expectException(\InvalidArgumentException::class);P::request($context,$images);
    }
    public function test_framing_rejects_occupied_unknown_blocked_unbounded_or_claimed_authority(): void
    {
        [$c]=self::fixture();$good=self::candidate($c);
        $mutations=[fn($v)=>array_replace($v,['sourcePeople'=>'present']),fn($v)=>array_replace($v,['sourcePeople'=>'uncertain']),fn($v)=>array_replace($v,['coherentGroup'=>'uncertain']),
            fn($v)=>array_replace_recursive($v,['view'=>['hFov'=>121]]),fn($v)=>array_replace_recursive($v,['view'=>['yaw'=>181]]),
            fn($v)=>array_replace_recursive($v,['constraints'=>['circulation'=>['status'=>'blocked']]]),fn($v)=>$v+['imageGenerationAuthorized'=>true],
            fn($v)=>array_replace($v,['sourceSha256'=>str_repeat('f',64)]),fn($v)=>array_replace_recursive($v,['constraints'=>['frame_margin'=>['evidenceViewIds'=>['unknown']]]])];
        foreach($mutations as $mutate){try{P::parse(self::response($mutate($good)),$c);$this->fail('Unsafe framing parsed.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
    }
    public function test_hold_carries_no_pose_and_incomplete_receipt_is_not_a_candidate(): void
    {
        [$c]=self::fixture();$v=self::candidate($c);$v['status']='needs_review';$v['sourcePeople']='uncertain';$v['view']=null;
        $this->assertNull(P::parse(self::response($v),$c)['view']);$response=self::response($v);$response['status']='incomplete';
        $this->expectException(\InvalidArgumentException::class);P::parse($response,$c);
    }
    public function test_oversized_image_is_rejected_before_wire_submission(): void
    {
        [$c,$images]=self::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]).str_repeat('x',24*1024*1024);$images[0]='data:image/png;base64,'.base64_encode($bytes);$c['views'][0]['sha256']=hash('sha256',$bytes);
        $this->expectException(\InvalidArgumentException::class);P::request($c,$images);
    }
    private function cut(): array
    {
        $test=new IguideLabSectionAnalysisProtocolTest('test_wire_keeps_exact_order_bytes_roles_schema_and_twenty_four_mebibyte_bound');
        $method=new \ReflectionMethod($test,'fixture');[$recipe,$c,$images]=$method->invoke($test);
        $c['preset']='lifestyle';$c['style']=null;$c['instructions']='Add exactly two separately standing adults.';
        $c['lifestyleFraming']=['version'=>P::VERSION,'id'=>(string)Str::uuid(),'resultSha256'=>str_repeat('f',64),'nativeSourceSha256'=>$c['sourceContext']['binding']['nativeSourceSha256'],
            'view'=>array_intersect_key($c['sourceContext']['binding']['view'],array_flip(['yaw','pitch','hFov'])),'constraints'=>array_fill_keys(P::CONSTRAINTS,['status'=>'verify_on_cut','evidenceViewIds'=>['source'],'reason'=>'Check actual cut.'])];
        $proposal=['schemaVersion'=>LabScenePlanProtocol::VERSION,'sourceSha256'=>$c['sourceSha256'],'projection'=>'rectilinear','preset'=>'lifestyle','style'=>null,'summary'=>'Two-adult standing pair.',
            'protectedFeatures'=>[['id'=>'window','description'=>'Keep window.','evidenceViewIds'=>['source'],'confidence'=>'high']],
            'targets'=>array_map(fn($id)=>['id'=>$id,'description'=>$id,'action'=>'add','objectCount'=>1,'evidenceViewIds'=>['source']],['adult_1','adult_2']),
            'sourceArtifacts'=>[],'uncertainties'=>[],'workflow'=>['mode'=>'whole_panorama','reason'=>'Complete group fits the current cut.','sections'=>[]],
            'generationPrompt'=>'Add the two adults; preserve all original features.',
            'reviewChecks'=>array_map(fn($check)=>['check'=>$check,'instruction'=>'Inspect unchanged source features.'],['edit','geometry','seam','appearance']),
            'lifestyleReadiness'=>['version'=>LabLifestyleCutProtocol::GATE_VERSION,'sourceSha256'=>$c['sourceSha256'],'nativeSourceSha256'=>$c['lifestyleFraming']['nativeSourceSha256'],'framingResultSha256'=>$c['lifestyleFraming']['resultSha256'],
                'checks'=>array_fill_keys(LabLifestyleCutProtocol::CHECKS,['status'=>'pass','evidenceViewIds'=>['source','parent_source'],'reason'=>'Both source cut and native context support the bounded scope.'])]];
        return [$c,$images,$proposal];
    }
    public function test_cut_gate_is_independent_and_uncertainty_prevents_authority(): void
    {
        [$c,$images,$v]=$this->cut();$snapshot=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_CUT);
        $wire=app(LabAnalysisRecipes::class)->request($snapshot,$c,$images);$this->assertSame($c,json_decode($wire['input'][0]['content'][0]['text'],true));
        $this->assertStringNotContainsString('maxLength',json_encode($wire['text']['format']['schema']));
        $parsed=LabLifestyleCutProtocol::parse(self::response($v),$c);$this->assertTrue($parsed['lifestyleReadiness']['allRequiredChecksPass']);$this->assertFalse($parsed['automaticGeneration']);
        $v['lifestyleReadiness']['checks']['optical_dependencies']['status']='uncertain';$this->assertFalse(LabLifestyleCutProtocol::parse(self::response($v),$c)['lifestyleReadiness']['allRequiredChecksPass']);
        $v['lifestyleReadiness']['checks']['optical_dependencies']['evidenceViewIds']=['source'];$this->expectException(\InvalidArgumentException::class);LabLifestyleCutProtocol::parse(self::response($v),$c);
    }
    public function test_cut_pose_parent_hash_and_receipt_binding_cannot_be_changed(): void
    {
        [$c,$images,$v]=$this->cut();$recipe=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::LIFESTYLE_CUT)['recipe'];
        $mutations=[fn($x)=>array_replace_recursive($x,['lifestyleFraming'=>['view'=>['yaw'=>19]]]),fn($x)=>array_replace_recursive($x,['lifestyleFraming'=>['nativeSourceSha256'=>str_repeat('0',64)]]),
            fn($x)=>array_replace_recursive($x,['sourceContext'=>['views'=>[2=>['role'=>'authoritative_edit_cut']]]])];
        foreach($mutations as $mutate){try{LabLifestyleCutProtocol::compile($recipe,$mutate($c),$images);$this->fail('Changed cut authority accepted.');}catch(\InvalidArgumentException $e){$this->assertNotEmpty($e->getMessage());}}
        $v['lifestyleReadiness']['framingResultSha256']=str_repeat('e',64);$this->expectException(\InvalidArgumentException::class);LabLifestyleCutProtocol::parse(self::response($v),$c);
    }
}
