<?php
namespace Tests\Unit;

use App\Services\IguideLab\{LabAnalysisRecipes,LabMachineSegmentationPlan,LabStagingCombinedLayoutProtocol as Combined,LabStagingLayoutProtocol as Legacy,LabStagingLayouts};
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class IguideLabStagingCombinedLayoutTest extends TestCase
{
    private function fixture():array
    {
        $fixture=new IguideLabStagingLayoutProtocolTest('test_initial_explicit_plan_is_not_actual_placement_or_mask_authority');
        [, $c,$images,$v]=(new \ReflectionMethod($fixture,'fixture'))->invoke($fixture);
        $c['stagingLayout']['version']=$v['stagingLayout']['version']=Combined::GATE;
        $snapshot=app(LabAnalysisRecipes::class)->snapshot(LabAnalysisRecipes::STAGING_LAYOUT_COMBINED);
        return [$snapshot,$c,$images,$v];
    }
    private function request(array $v):array
    {
        $v['workflow']['mode']='manual_review';$g=&$v['stagingLayout'];$g['decision']='request_reframe_details';$g['layout']=null;
        $g['reframePose']=['yaw'=>25,'pitch'=>-20,'hFov'=>95];
        $g['detailRequests']=[['id'=>'source_detail_1','pose'=>['yaw'=>65,'pitch'=>-5,'hFov'=>55],'evidenceViewIds'=>['source','parent_right'],'reason'=>'Resolve the source glazing while the revised cut establishes group fit.']];
        return $v;
    }
    private function response(array $v):array{return IguideLabStagingFramingProtocolTest::response($v);}
    private function followup(bool $differentCut=true):array
    {
        [$s,$c,$images,$v]=$this->fixture();$request=$this->request($v);
        $prior=Combined::parse($this->response($request),$c)['stagingLayout'];
        $a=&$c['stagingLayout'];$a['priorResult']=['proposalSha256'=>str_repeat('8',64),'layout'=>$prior];
        $a['followup']=['ordinal'=>1,'operation'=>'reframe_native_details','previousAnalysisId'=>(string)Str::uuid(),'previousResultSha256'=>LabMachineSegmentationPlan::canonicalHash($a['priorResult'])];
        $b=&$c['sourceContext']['binding'];$b['sectionId']=(string)Str::uuid();$b['view']=array_replace($b['view'],$request['stagingLayout']['reframePose']);
        if($differentCut){$b['sourceSha256']=$c['sourceSha256']=$a['sourceCutSha256']=str_repeat('9',64);}
        $c['sourceContext']['bindingSha256']=LabAnalysisRecipes::hash($b);
        $c['sourceContext']['views'][0]=array_replace($c['sourceContext']['views'][0],$request['stagingLayout']['reframePose']);
        $bytes=base64_decode(explode(',',$images[2],2)[1]);$images[]='data:image/png;base64,'.base64_encode($bytes);$c['viewIds'][]='source_detail_1';
        $a['detailViews']=[['id'=>'source_detail_1','sha256'=>hash('sha256',$bytes),'nativeSourceSha256'=>$a['nativeSourceSha256'],'width'=>1024,'height'=>1024,
            'projection'=>'rectilinear','role'=>'same_capture_native_detail','pose'=>$request['stagingLayout']['detailRequests'][0]['pose']]];
        $v['sourceSha256']=$v['stagingLayout']['sourceSha256']=$c['sourceSha256'];
        return [$s,$c,$images,$v];
    }
    public function test_closed_v4_recipe_preserves_old_files_and_selects_only_when_enabled():void
    {
        [$s,$c,$images,$v]=$this->fixture();$r=app(LabAnalysisRecipes::class);$this->assertFalse(config('iguide-lab.staging_layout_combined_enabled'));
        $this->assertSame(Combined::VERSION,$s['recipe']['compilerVersion']);$this->assertSame(Combined::gateSchema(),$s['recipe']['schema']['properties']['stagingLayout']);
        $this->assertSame(['effort'=>'medium'],$s['recipe']['reasoning']);$this->assertSame(12000,$s['recipe']['max_output_tokens']);
        foreach([LabAnalysisRecipes::STAGING_LAYOUT,LabAnalysisRecipes::STAGING_LAYOUT_MEDIUM,LabAnalysisRecipes::STAGING_LAYOUT_ADAPTIVE] as $version){
            $legacy=$r->snapshot($version);$this->assertSame(Legacy::VERSION,$legacy['recipe']['compilerVersion']);
            $this->assertNotContains('request_reframe_details',$legacy['recipe']['schema']['properties']['stagingLayout']['properties']['decision']['enum']);}
        $wire=$r->request($s,$c,$images);$this->assertSame($c,json_decode($wire['input'][0]['content'][0]['text'],true));
    }
    public function test_combined_source_request_has_no_image_or_geometry_authority():void
    {
        [$s,$c,$images,$v]=$this->fixture();$gate=Combined::parse($this->response($this->request($v)),$c)['stagingLayout'];
        $this->assertSame('request_reframe_details',$gate['decision']);$this->assertNull($gate['layout']);
        $this->assertFalse($gate['eligibleForDraft']);$this->assertFalse($gate['supportMaskAuthority']);$this->assertFalse($gate['realizedPlacementVerified']);
    }
    public function test_combined_followup_binds_new_cut_and_old_native_details_without_requiring_pixel_difference():void
    {
        foreach([true,false] as $different){[$s,$c,$images,$v]=$this->followup($different);
            $wire=Combined::compile($s['recipe'],$c,$images);$gate=Combined::parse($this->response($v),$c)['stagingLayout'];
            $this->assertCount(15,$wire['input'][0]['content']);$this->assertSame('high',$wire['input'][0]['content'][14]['detail']);
            $this->assertTrue($gate['eligibleForDraft']);$this->assertSame(1,$gate['followupOrdinal']);}
    }
    public function test_reframe_pose_detail_pose_prior_native_or_ordinal_drift_rejected():void
    {
        [$s,$c,$images,$v]=$this->followup();
        foreach(['reframe','detail','native','ordinal','priorhash','missingdetail'] as $kind){$bad=$c;
            if($kind==='reframe'){$bad['sourceContext']['binding']['view']['yaw']=26;$bad['sourceContext']['views'][0]['yaw']=26;$bad['sourceContext']['bindingSha256']=LabAnalysisRecipes::hash($bad['sourceContext']['binding']);}
            if($kind==='detail')$bad['stagingLayout']['detailViews'][0]['pose']['yaw']=66;
            if($kind==='native'){$bad['stagingLayout']['priorResult']['layout']['nativeSourceSha256']=str_repeat('0',64);$bad['stagingLayout']['followup']['previousResultSha256']=LabMachineSegmentationPlan::canonicalHash($bad['stagingLayout']['priorResult']);}
            if($kind==='ordinal')$bad['stagingLayout']['followup']['ordinal']=2;
            if($kind==='priorhash')$bad['stagingLayout']['priorResult']['layout']['summary']='Rewritten prior';
            if($kind==='missingdetail')$bad['stagingLayout']['detailViews']=[];
            try{Combined::compile($s['recipe'],$bad,$images);$this->fail('Drift accepted: '.$kind);}catch(InvalidArgumentException){$this->addToAssertionCount(1);}}
    }
    public function test_second_operation_and_legacy_compiler_cannot_authorize_combined_path():void
    {
        [$s,$c,$images,$v]=$this->followup();
        try{Combined::parse($this->response($this->request($v)),$c);$this->fail('Second operation accepted');}catch(InvalidArgumentException){$this->addToAssertionCount(1);}
        $this->expectException(InvalidArgumentException::class);Legacy::compile($s['recipe'],$c,$images);
    }
    public function test_unsupported_sources_incomplete_combination_and_geometry_still_hold():void
    {
        [$s,$c,$images,$v]=$this->fixture();$request=$this->request($v);
        foreach(['occupied','optics','missingpose','missingdetails','samepose','partial'] as $kind){$bad=$request;
            if($kind==='occupied')$bad['stagingLayout']['emptyRoom']='occupied';
            if($kind==='optics')$bad['stagingLayout']['optics']='required_unsupported_change';
            if($kind==='missingpose')$bad['stagingLayout']['reframePose']=null;
            if($kind==='missingdetails')$bad['stagingLayout']['detailRequests']=[];
            if($kind==='samepose')$bad['stagingLayout']['reframePose']=array_intersect_key($c['sourceContext']['binding']['view'],array_flip(['yaw','pitch','hFov']));
            if($kind==='partial')$bad['stagingLayout']['layout']=$v['stagingLayout']['layout'];
            try{Combined::parse($this->response($bad),$c);$this->fail('Unsupported request accepted: '.$kind);}catch(InvalidArgumentException){$this->addToAssertionCount(1);}}
        $v['stagingLayout']['layout']['targets'][0]['editEnvelope'][0]=.01;
        $this->assertFalse(Combined::parse($this->response($v),$c)['stagingLayout']['eligibleForDraft']);
        $v['stagingLayout']['layout']=null;$this->assertFalse(Combined::parse($this->response($v),$c)['stagingLayout']['eligibleForDraft']);
    }
}
