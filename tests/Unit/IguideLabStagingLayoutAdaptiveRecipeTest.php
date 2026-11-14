<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabAnalysisRecipes,LabStagingLayoutProtocol as P,LabMachineSegmentationPlan as M};
use InvalidArgumentException;
use Tests\TestCase;

class IguideLabStagingLayoutAdaptiveRecipeTest extends TestCase
{
    private function fixture(): array
    {
        $test=new IguideLabStagingLayoutProtocolTest('test_initial_explicit_plan_is_not_actual_placement_or_mask_authority');
        return (new \ReflectionMethod($test,'fixture'))->invoke($test);
    }
    private function response(array $value): array { return IguideLabStagingFramingProtocolTest::response($value); }
    private function questions(array $value): array
    {
        $value['workflow']['mode']='manual_review';
        $value['stagingLayout']['decision']='request_details';
        $value['stagingLayout']['layout']=null;
        $value['stagingLayout']['floorAppearance']='uncertain';
        $value['stagingLayout']['optics']='needs_detail';
        $value['stagingLayout']['checks']['optical_dependencies']['status']='uncertain';
        $value['stagingLayout']['detailRequests']=[['id'=>'source_detail_1','pose'=>['yaw'=>20,'pitch'=>-10,'hFov'=>60],
            'evidenceViewIds'=>['source','parent_right'],'reason'=>'Resolve the source glazing and floor response before any image.']];
        return $value;
    }
    public function test_v3_changes_only_identity_and_instructions_preserving_old_recipes_and_wire(): void
    {
        [, $context, $images]=$this->fixture();$recipes=app(LabAnalysisRecipes::class);
        $v2=$recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM);$v3=$recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_ADAPTIVE);
        $this->assertSame('c3166d4b86c79f1c1b6ebdcd8f0f0dda1b7d160680013cbaa9c8ec0b70a93601',hash_file('sha256',resource_path('iguide-lab/analysis-recipes/staging-layout-1.json')));
        $this->assertSame('d1ece646fa875f43b1b3cba00c57068df319e622a6264f2c637305ce9cfcf466',hash_file('sha256',resource_path('iguide-lab/analysis-recipes/staging-layout-2.json')));
        $restored=$v3['recipe'];$restored['instructionsVersion']=$v2['recipe']['instructionsVersion'];$restored['instructions']=$v2['recipe']['instructions'];
        $this->assertSame($v2['recipe'],$restored);$this->assertSame($v2['schemaSha256'],$v3['schemaSha256']);
        $this->assertSame(['effort'=>'medium'],$v3['recipe']['reasoning']);$this->assertSame(12000,$v3['recipe']['max_output_tokens']);
        $old=$recipes->request($v2,$context,$images);$new=$recipes->request($v3,$context,$images);
        $this->assertNotSame($old['instructions'],$new['instructions']);$new['instructions']=$old['instructions'];$this->assertSame($old,$new);
        $this->assertSame($v2['recipe'],$recipes->validate($v2));$this->assertSame(LabAnalysisRecipes::CANDIDATE,$recipes->active()['recipe']['instructionsVersion']);
    }
    public function test_null_adaptive_layout_supports_one_owned_followup_without_generation_authority(): void
    {
        [, $context, $images, $value]=$this->fixture();$recipes=app(LabAnalysisRecipes::class);$snapshot=$recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_ADAPTIVE);
        $initial=$recipes->parse($snapshot,$this->response($this->questions($value)),$context);$gate=$initial['stagingLayout'];
        $this->assertNull($gate['layout']);$this->assertNull($gate['geometry']);$this->assertFalse($gate['eligibleForDraft']);$this->assertFalse($initial['automaticGeneration']);
        $prior=['proposalSha256'=>LabAnalysisRecipes::hash($initial['proposal']),'layout'=>$gate];
        $context['stagingLayout']['priorResult']=$prior;
        $context['stagingLayout']['followup']=['ordinal'=>1,'operation'=>'native_details','previousAnalysisId'=>'11111111-1111-4111-8111-111111111111','previousResultSha256'=>M::canonicalHash($prior)];
        $bytes=base64_decode(explode(',',$images[2],2)[1]);$context['viewIds'][]='source_detail_1';$images[]=$images[2];
        $context['stagingLayout']['detailViews'][]=['id'=>'source_detail_1','sha256'=>hash('sha256',$bytes),'nativeSourceSha256'=>$gate['nativeSourceSha256'],
            'width'=>1024,'height'=>1024,'projection'=>'rectilinear','role'=>'same_capture_native_detail','pose'=>$gate['detailRequests'][0]['pose']];
        $wire=$recipes->request($snapshot,$context,$images);$this->assertCount(15,$wire['input'][0]['content']);
        $held=$this->questions($value);$held['stagingLayout']['decision']='needs_review';$held['stagingLayout']['detailRequests']=[];
        $parsed=$recipes->parse($snapshot,$this->response($held),$context);
        $this->assertSame(1,$parsed['stagingLayout']['followupOrdinal']);$this->assertFalse($parsed['stagingLayout']['eligibleForDraft']);
        foreach(['prior','pose','second'] as $kind){$bad=$context;$answer=$held;
            if($kind==='prior')$bad['stagingLayout']['priorResult']['layout']['summary'].=' drift';
            elseif($kind==='pose')$bad['stagingLayout']['detailViews'][0]['pose']['yaw']+=1;
            else $answer=$this->questions($value);
            try{$recipes->parse($snapshot,$this->response($answer),$bad);$this->fail('Drift or second source operation accepted.');}catch(InvalidArgumentException){$this->addToAssertionCount(1);}
        }
    }
    public function test_null_or_malformed_draft_geometry_does_not_gain_eligibility(): void
    {
        [, $context, , $value]=$this->fixture();$recipes=app(LabAnalysisRecipes::class);$snapshot=$recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_ADAPTIVE);
        $null=$value;$null['stagingLayout']['layout']=null;
        $parsed=$recipes->parse($snapshot,$this->response($null),$context);$this->assertFalse($parsed['stagingLayout']['eligibleForDraft']);
        $this->assertFalse($parsed['stagingLayout']['supportMaskAuthority']);
        $valid=$recipes->parse($snapshot,$this->response($value),$context);$this->assertTrue($valid['stagingLayout']['eligibleForDraft']);
        $this->assertFalse($valid['automaticGeneration']);$this->assertFalse($valid['stagingLayout']['realizedPlacementVerified']);
        $value['stagingLayout']['layout']['visibleFloor'][0]['polygon']=[[.1,.4],[.9,.4],[.5,.6],[.9,.9],[.1,.9]];
        $this->expectException(InvalidArgumentException::class);$recipes->parse($snapshot,$this->response($value),$context);
    }
    public function test_null_reframe_and_hold_keep_same_source_checks(): void
    {
        [, $context, , $value]=$this->fixture();$recipes=app(LabAnalysisRecipes::class);$snapshot=$recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_ADAPTIVE);
        $value['workflow']['mode']='manual_review';$value['stagingLayout']['layout']=null;$value['stagingLayout']['decision']='request_reframe';
        $view=$context['sourceContext']['binding']['view'];$value['stagingLayout']['reframePose']=['yaw'=>$view['yaw']+5,'pitch'=>$view['pitch'],'hFov'=>$view['hFov']];
        $this->assertFalse($recipes->parse($snapshot,$this->response($value),$context)['stagingLayout']['eligibleForDraft']);
        $value['stagingLayout']['emptyRoom']='occupied';$this->expectException(InvalidArgumentException::class);
        $recipes->parse($snapshot,$this->response($value),$context);
    }
}
