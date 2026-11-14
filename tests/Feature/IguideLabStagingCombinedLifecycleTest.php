<?php
namespace Tests\Feature;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabWorkflowItem};
use App\Services\IguideLab\{LabAnalyses,LabAnalysisRecipes,LabStagingCombinedLayoutProtocol,LabStagingLayoutReadiness,LabStagingLayouts,LabStagingLayoutViews,LabService};
use Illuminate\Support\Facades\{Http,Storage};
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Persisted combined follow-up; only synthetic projection pixels replace local numerical workers. */
class IguideLabStagingCombinedLifecycleTest extends IguideLabStagingLayoutLifecycleTest
{
    private function value(string $name):mixed{return (new \ReflectionProperty(IguideLabStagingLayoutLifecycleTest::class,$name))->getValue($this);}
    private function callFixture(string $name,mixed ...$args):mixed{return (new \ReflectionMethod(IguideLabStagingLayoutLifecycleTest::class,$name))->invoke($this,...$args);}
    protected function combinedLayoutFixture():bool{return true;}
    protected function setUp():void
    {
        parent::setUp();
        $this->app->instance(LabStagingLayoutViews::class,new class extends LabStagingLayoutViews {
            protected function execute(string $request,string $root):array {
                $r=json_decode(file_get_contents($request),true);$base=$r['outputDirectory'];@mkdir($base.'/views',02770,true);$views=[];
                foreach($r['requests'] as $v){$path=$base.'/views/'.$v['id'].'.png';$bytes=(string)ImageManager::gd()->create(1024,1024)->fill('778899')->toPng();
                    file_put_contents($path,$bytes);$views[]=['id'=>$v['id'],'path'=>$path,'sha256'=>hash('sha256',$bytes),'pose'=>$v['pose']+['width'=>1024,'height'=>1024]];}
                $path=$base.'/manifest.json';file_put_contents($path,json_encode(['source'=>$r['source'],'views'=>$views]));
                return ['status'=>'completed','sameCaptureOnly'=>true,'editScopeExpanded'=>false,'providerCalls'=>0,'independentCameraEvidence'=>false,
                    'source'=>$r['source'],'views'=>$views,'manifest'=>['path'=>$path,'sha256'=>hash_file('sha256',$path)],
                    'runtime'=>['version'=>'staging-original-native-details/1','python'=>'3.12.3','numpy'=>'2.5.4','pillow'=>'13.0.0',
                        'workerSha256'=>hash_file('sha256',resource_path('iguide-lab/staging_layout_details.py')),'geometrySha256'=>hash_file('sha256',resource_path('iguide-lab/pano_geometry.py'))]];
            }
        });
    }
    private function responseFor(array $context,bool $combined):array
    {
        $fixture=new \Tests\Unit\IguideLabStagingLayoutProtocolTest('test_initial_explicit_plan_is_not_actual_placement_or_mask_authority');
        [,,,$v]=(new \ReflectionMethod($fixture,'fixture'))->invoke($fixture);
        $v['sourceSha256']=$v['stagingLayout']['sourceSha256']=$context['sourceSha256'];
        $v['stagingLayout']['version']=LabStagingCombinedLayoutProtocol::GATE;
        foreach(['nativeSourceSha256','framingResultSha256'] as $k)$v['stagingLayout'][$k]=$context['stagingLayout'][$k];
        if($combined){$v['workflow']['mode']='manual_review';$v['stagingLayout']['decision']='request_reframe_details';$v['stagingLayout']['layout']=null;
            $v['stagingLayout']['reframePose']=['yaw'=>10,'pitch'=>-15,'hFov'=>90];$v['stagingLayout']['detailRequests']=[['id'=>'source_detail_1',
                'pose'=>['yaw'=>60,'pitch'=>-5,'hFov'=>60],'evidenceViewIds'=>['source','parent_right'],'reason'=>'Inspect original glazing while changing the group framing.']];}
        return \Tests\Unit\IguideLabStagingFramingProtocolTest::response($v);
    }
    private function finishCombined(IguideLabAnalysis $record,bool $combined):IguideLabAnalysis
    {
        $this->callFixture('fake',function($request)use($combined){$c=json_decode($request['input'][0]['content'][0]['text'],true);
            return Http::response($this->responseFor($c,$combined),200,['x-request-id'=>'req-combined-'.substr(hash('sha256',json_encode($c)),0,12)]);});
        app(LabAnalyses::class)->process($record);$record=$record->fresh();$this->assertSame('completed',$record->status,$record->error??'');return $record;
    }
    private function combinedFollowup():array
    {
        $initial=$this->finishCombined($this->callFixture('reserve'),true);$gate=app(LabStagingLayoutReadiness::class)->verified($initial,false);
        $cut=$this->callFixture('makeSection','staging-reframed-section',$gate['reframePose']);$followup=$this->callFixture('reserve',$initial->id,$cut);
        $this->assertNotSame($initial->case_id,$followup->case_id);$this->assertSame('reframe_native_details',$followup->data['stagingLayout']['followup']['operation']);
        $this->assertCount(1,$followup->data['stagingLayout']['detailViews']);return [$initial,$followup,$cut];
    }
    public function test_combined_completed_followup_is_exactly_replayable_and_no_extra_analysis_can_be_reserved():void
    {
        [$initial,$followup,$cut]=$this->combinedFollowup();$followup=$this->finishCombined($followup,false);
        $this->assertTrue(app(LabStagingLayoutReadiness::class)->verified($followup)['eligibleForDraft']);
        $this->assertSame($followup->id,$this->callFixture('reserve',$initial->id,$cut)->id);Http::assertSentCount(1);
        $this->assertSame(LabAnalysisRecipes::STAGING_LAYOUT_COMBINED,$followup->data['executionRecipe']['recipe']['instructionsVersion']);
        $this->assertCount(7,$followup->data['views']);$this->assertSame(0,$this->value('project')->fresh()->used_runs);
        $this->expectException(HttpException::class);app(LabStagingLayoutReadiness::class)->reference($this->value('item'),$this->value('framing')->id,$cut,$followup->id);
    }
    public function test_combined_prior_receipt_drift_after_quota_prevents_fallback():void
    {
        [$initial,$followup]=$this->combinedFollowup();$this->callFixture('fake',function()use($initial){Storage::disk(LabService::DISK)->delete($initial->data['stagingLayoutReceipt']['path']);
            return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabAnalyses::class)->process($followup);Http::assertSentCount(1);
        $this->assertSame('needs_attention',$followup->fresh()->status);$this->assertSame(['quota_rejected'],array_column($followup->fresh()->provider_attempts,'status'));
    }
    public function test_combined_policy_downgrade_after_quota_prevents_fallback():void
    {
        [, $followup]=$this->combinedFollowup();$this->callFixture('fake',function(){config(['iguide-lab.staging_layout_combined_enabled'=>false]);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabAnalyses::class)->process($followup);Http::assertSentCount(1);$this->assertSame('needs_attention',$followup->fresh()->status);
        $this->assertSame(['quota_rejected'],array_column($followup->fresh()->provider_attempts,'status'));
    }
}
