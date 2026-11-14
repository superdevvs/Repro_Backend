<?php
namespace Tests\Unit;
use App\Services\IguideLab\{LabStagingLayoutProtocol as P,LabStagingLayoutGeometry,LabAnalysisRecipes,LabAnalysisSourceContext,LabScenePlanProtocol};
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class IguideLabStagingLayoutProtocolTest extends TestCase
{
    private function fixture(): array
    {
        $test=new IguideLabSectionAnalysisProtocolTest('test_wire_keeps_exact_order_bytes_roles_schema_and_twenty_four_mebibyte_bound');
        [$recipe,$c,$images]=(new \ReflectionMethod($test,'fixture'))->invoke($test);
        $c['preset']='staging';$c['style']='modern';$c['instructions']='Plan exactly three complete pieces.';
        $b=$c['sourceContext']['binding'];$c['stagingLayout']=['version'=>P::GATE,'framingId'=>(string)Str::uuid(),'framingResultSha256'=>str_repeat('f',64),
            'nativeSourceSha256'=>$b['nativeSourceSha256'],'sourceCutSha256'=>$c['sourceSha256'],'preparedSourceSha256'=>$c['sourceContext']['views'][0]['sha256'],'sourceGeometrySha256'=>$b['geometrySha256'],
            'followup'=>['ordinal'=>0,'operation'=>'initial','previousAnalysisId'=>null,'previousResultSha256'=>null],'detailViews'=>[],'priorResult'=>null];
        $q=fn($x,$y,$w,$h)=>[[$x,$y],[$x+$w,$y],[$x+$w,$y+$h],[$x,$y+$h]];
        $layout=['visibleFloor'=>[['id'=>'floor','polygon'=>$q(.1,.4,.8,.5)]],'forbiddenFloor'=>[],'protectedFeatures'=>[],
            'routes'=>[['id'=>'route','polygon'=>$q(.82,.42,.06,.45)]],'targets'=>[
                ['id'=>'sofa','footprint'=>$q(.15,.52,.15,.2),'editEnvelope'=>[.12,.3,.32,.75]],
                ['id'=>'coffee_table','footprint'=>$q(.4,.6,.13,.12),'editEnvelope'=>[.37,.48,.55,.76]],
                ['id'=>'chair','footprint'=>$q(.65,.56,.12,.16),'editEnvelope'=>[.61,.36,.8,.76]]]];
        $gate=['version'=>P::GATE,'sourceSha256'=>$c['sourceSha256'],'nativeSourceSha256'=>$b['nativeSourceSha256'],'framingResultSha256'=>str_repeat('f',64),
            'decision'=>'draft_candidate','emptyRoom'=>'empty','scale'=>['status'=>'plausible','evidenceViewIds'=>['source'],'reason'=>'Visible door and source perspective support prospective proportions.'],
            'floorAppearance'=>'diffuse','optics'=>'no_required_unsupported_change','checks'=>array_fill_keys(P::CHECKS,['status'=>'supported','evidenceViewIds'=>['source','parent_source'],'reason'=>'Source observations support this explicit prospective proposal.']),
            'layout'=>$layout,'detailRequests'=>[],'reframePose'=>null,'summary'=>'Planning hypothesis only, not realized feet or measured clearance.'];
        $proposal=['schemaVersion'=>LabScenePlanProtocol::VERSION,'sourceSha256'=>$c['sourceSha256'],'projection'=>'rectilinear','preset'=>'staging','style'=>'modern','summary'=>'Prospective three-piece staging.',
            'protectedFeatures'=>[['id'=>'window','description'=>'Preserve source window.','evidenceViewIds'=>['source'],'confidence'=>'high']],
            'targets'=>array_map(fn($id)=>['id'=>$id,'description'=>$id,'action'=>'add','objectCount'=>1,'evidenceViewIds'=>['source']],['sofa','chair','coffee_table']),
            'sourceArtifacts'=>[],'uncertainties'=>[],'workflow'=>['mode'=>'whole_panorama','reason'=>'The explicit prospective group fits.','sections'=>[]],
            'generationPrompt'=>'Add exactly the three pieces while preserving the complete original shell and source details.',
            'reviewChecks'=>array_map(fn($check)=>['check'=>$check,'instruction'=>'Independently inspect actual source and candidate.'],['edit','geometry','seam','appearance']),
            'stagingLayout'=>$gate];
        $recipe['instructions']=P::INSTRUCTIONS;$recipe['schema']['properties']['stagingLayout']=P::gateSchema();$recipe['schema']['required'][]='stagingLayout';
        return [$recipe,$c,$images,$proposal];
    }
    private function response(array $v):array{return IguideLabStagingFramingProtocolTest::response($v);}
    private function details(array &$c,array &$images):void
    {
        [,,,$prior]=$this->fixture();$prior['stagingLayout']['decision']='request_details';
        $prior['stagingLayout']['detailRequests']=array_map(fn($i)=>['id'=>P::DETAILS[$i],'pose'=>['yaw'=>20+$i,'pitch'=>0,'hFov'=>60],'evidenceViewIds'=>['parent_right'],'reason'=>'Inspect the actual source aperture.'],[0,1]);
        $c['stagingLayout']['priorResult']=['proposalSha256'=>str_repeat('8',64),'layout'=>P::validateLayout($prior['stagingLayout'],$c)];
        $c['stagingLayout']['followup']=['ordinal'=>1,'operation'=>'native_details','previousAnalysisId'=>(string)Str::uuid(),'previousResultSha256'=>\App\Services\IguideLab\LabMachineSegmentationPlan::canonicalHash($c['stagingLayout']['priorResult'])];
        for($i=0;$i<2;$i++){
            $bytes=base64_decode(explode(',',$images[2+$i],2)[1]);$id=P::DETAILS[$i];$c['viewIds'][]=$id;$images[]='data:image/png;base64,'.base64_encode($bytes);
            $c['stagingLayout']['detailViews'][]=['id'=>$id,'sha256'=>hash('sha256',$bytes),'nativeSourceSha256'=>$c['stagingLayout']['nativeSourceSha256'],'width'=>1024,'height'=>1024,'projection'=>'rectilinear','role'=>'same_capture_native_detail','pose'=>['yaw'=>20+$i,'pitch'=>0,'hFov'=>60]];
        }
    }
    public function test_initial_explicit_plan_is_not_actual_placement_or_mask_authority():void
    {
        [$r,$c,$images,$v]=$this->fixture();$wire=P::compile($r,$c,$images);$p=P::parse($this->response($v),$c);
        $this->assertSame($c,json_decode($wire['input'][0]['content'][0]['text'],true));$this->assertTrue($p['stagingLayout']['eligibleForDraft']);
        $this->assertFalse($p['stagingLayout']['realizedPlacementVerified']);$this->assertFalse($p['stagingLayout']['supportMaskAuthority']);$this->assertFalse($p['automaticGeneration']);
    }
    public function test_registered_recipe_is_separate_and_default_off():void
    {
        [$r,$c,$images,$v]=$this->fixture();$recipes=app(LabAnalysisRecipes::class);
        $snapshot=$recipes->snapshot(LabAnalysisRecipes::STAGING_LAYOUT);
        $this->assertSame(P::VERSION,$snapshot['recipe']['compilerVersion']);
        $this->assertSame(P::VIEW_RECIPE,$snapshot['recipe']['viewRecipe']);
        $this->assertFalse(config('iguide-lab.staging_layout_enabled'));
        $this->assertArrayHasKey('stagingLayout',$recipes->request($snapshot,$c,$images)['text']['format']['schema']['properties']);
        $legacy=$recipes->snapshot(LabAnalysisRecipes::STAGING_CUT);
        $this->assertArrayNotHasKey('stagingLayout',$legacy['recipe']['schema']['properties']);
    }
    public function test_source_or_geometry_or_preparation_mixups_rejected():void
    {
        [$r,$c,$images,$v]=$this->fixture();foreach(['sourceCutSha256','nativeSourceSha256','preparedSourceSha256','sourceGeometrySha256'] as $key){$bad=$c;$bad['stagingLayout'][$key]=str_repeat('0',64);
            try{P::compile($r,$bad,$images);$this->fail('Mixed source accepted');}catch(InvalidArgumentException){$this->addToAssertionCount(1);}}
    }
    public function test_unsupported_optics_scale_and_numerical_conflict_hold_even_if_model_claims_candidate():void
    {
        [$r,$c,$images,$v]=$this->fixture();foreach(['floorAppearance'=>'reflective','optics'=>'uncertain','emptyRoom'=>'occupied'] as $k=>$value){$bad=$v;$bad['stagingLayout'][$k]=$value;$this->assertFalse(P::parse($this->response($bad),$c)['stagingLayout']['eligibleForDraft']);}
        $v['stagingLayout']['layout']['targets'][0]['editEnvelope'][0]=.01;$this->assertFalse(P::parse($this->response($v),$c)['stagingLayout']['eligibleForDraft']);
    }
    public function test_one_detail_followup_is_bound_and_does_not_expand_generation_scope():void
    {
        [$r,$c,$images,$v]=$this->fixture();$this->details($c,$images);$v['stagingLayout']['checks']['optical_dependencies']['evidenceViewIds']=['source','source_detail_1','source_detail_2'];
        $v['protectedFeatures'][0]['evidenceViewIds']=['source_detail_2'];$wire=P::compile($r,$c,$images);$p=P::parse($this->response($v),$c);
        $this->assertCount(17,$wire['input'][0]['content']);$this->assertTrue($p['stagingLayout']['eligibleForDraft']);
        $this->assertSame(['source_detail_2'],$p['proposal']['protectedFeatures'][0]['evidenceViewIds']);
        [$images[6],$images[7]]=[$images[7],$images[6]];$this->expectException(InvalidArgumentException::class);P::compile($r,$c,$images);
    }
    public function test_second_adaptive_request_and_detail_reframe_combination_rejected():void
    {
        [$r,$c,$images,$v]=$this->fixture();$g=&$v['stagingLayout'];$g['decision']='request_details';$g['detailRequests']=[['id'=>'source_detail_1','pose'=>['yaw'=>60,'pitch'=>0,'hFov'=>60],'evidenceViewIds'=>['parent_right'],'reason'=>'Resolve original glazing detail.']];$v['workflow']['mode']='manual_review';
        $this->assertFalse(P::parse($this->response($v),$c)['stagingLayout']['eligibleForDraft']);
        $bad=$v;$bad['stagingLayout']['reframePose']=['yaw'=>10,'pitch'=>-25,'hFov'=>110];try{P::parse($this->response($bad),$c);$this->fail();}catch(InvalidArgumentException){$this->addToAssertionCount(1);}
        $this->details($c,$images);$this->expectException(InvalidArgumentException::class);P::parse($this->response($v),$c);
    }
    public function test_missing_parent_citation_and_unknown_detail_citation_rejected():void
    {
        [$r,$c,$images,$v]=$this->fixture();foreach([['source'],['source','source_detail_1']] as $ids){$bad=$v;$bad['stagingLayout']['checks']['optical_dependencies']['evidenceViewIds']=$ids;
            try{P::parse($this->response($bad),$c);$this->fail();}catch(InvalidArgumentException){$this->addToAssertionCount(1);}}
    }
    public function test_known_occupied_or_confirmed_unsupported_optics_cannot_request_adaptive_work():void
    {
        [$r,$c,$images,$v]=$this->fixture();
        $v['workflow']['mode']='manual_review';$v['stagingLayout']['decision']='request_details';
        $v['stagingLayout']['detailRequests']=[['id'=>'source_detail_1','pose'=>['yaw'=>60,'pitch'=>0,'hFov'=>60],'evidenceViewIds'=>['parent_right'],'reason'=>'Resolve the visible glass aperture.']];
        foreach(['emptyRoom'=>'occupied','optics'=>'required_unsupported_change'] as $key=>$value){
            $bad=$v;$bad['stagingLayout'][$key]=$value;
            try{P::parse($this->response($bad),$c);$this->fail('Known unsupported source requested additional work.');}
            catch(InvalidArgumentException){$this->addToAssertionCount(1);}
        }
    }
    public function test_legacy_parser_does_not_gain_eight_view_authority():void
    {
        [$r,$c,$images,$v]=$this->fixture();$this->details($c,$images);unset($v['stagingLayout'],$c['sourceContext'],$c['stagingLayout']);
        $this->expectException(InvalidArgumentException::class);LabScenePlanProtocol::parse($this->response($v),$c);
    }
}
