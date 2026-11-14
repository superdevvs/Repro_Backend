<?php
namespace Tests\Feature;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabProject,IguideLabSection,IguideLabWorkflowItem};
use App\Services\IguideLab\{LabAnalyses,LabAnalysisRecipes,LabAnalysisSourceContext,LabImages,LabSectionAnalysisViews,LabService,LabStagingAssessmentPolicy,LabStagingFramingContext,LabStagingFramingProtocol,LabStagingFramings,LabStagingLayoutProtocol,LabStagingLayoutReadiness,LabStagingLayouts,LabWorkflowSteps,LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Unit\{IguideLabStagingFramingProtocolTest as FramingFixture,IguideLabStagingLayoutProtocolTest as LayoutFixture};

/** Real persisted records, parser, preparation and receipts; only diagnostic pixels are synthetic. */
class IguideLabStagingLayoutLifecycleTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary=[];
    private IguideLabProject $project;
    private IguideLabWorkflowItem $item;
    private IguideLabCase $native;
    private IguideLabCase $cut;
    private IguideLabSection $section;
    private $framing;
    private $workflow;

    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();
        $key=tempnam(sys_get_temp_dir(),'layout-key');file_put_contents($key,'fixture-primary');$this->temporary[]=$key;
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'iguide-lab.openai_primary_key_path'=>$key,
            'services.openai.api_key'=>'fixture-fallback','iguide-lab.pipeline_python_path'=>PHP_BINARY]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->project=IguideLabProject::create(['name'=>'Prospective source layout','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        app(\App\Services\IguideLab\LabStorage::class)->prepareProject($this->project->id);
        [$context,$images]=FramingFixture::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]);
        $visible=$this->image($bytes,2048,1024);$this->native=$this->image($bytes,2048,1024);
        $this->native->update(['metadata'=>['role'=>'native-source','parentCaseId'=>$visible->id]]);
        $visible->update(['native_source'=>['caseId'=>$this->native->id,'requestId'=>(string)Str::uuid(),'sha256'=>$this->native->source_sha256,'width'=>2048,'height'=>1024]]);
        $this->workflow=app(LabWorkflows::class)->start($this->project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$this->native->id,'preset'=>'staging','style'=>'modern']]]);
        $this->item=IguideLabWorkflowItem::where('workflow_id',$this->workflow->id)->sole();
        $context['sourceSha256']=$this->native->source_sha256;$assets=[];
        foreach($images as $i=>$image){$path='iguide-lab/'.$this->project->id.'/workflow-items/'.$this->item->id.'/source-'.$i.'.png';
            Storage::disk(LabService::DISK)->put($path,base64_decode(explode(',',$image,2)[1]));$assets[]=['id'=>$context['views'][$i]['id'],'path'=>$path,'sha256'=>$context['views'][$i]['sha256']];}
        $capture=new class extends LabStagingFramingContext {public array $bundle=[];public function capture(IguideLabWorkflowItem $item):array{return $this->bundle;}};
        $capture->bundle=['context'=>$context,'assets'=>$assets,'preparedSourceSha256'=>$context['views'][0]['sha256'],'implementation'=>$capture->snapshot(),'geometryManifestSha256'=>str_repeat('d',64)];
        $this->app->instance(LabStagingFramingContext::class,$capture);
        $framing=app(LabStagingFramings::class);
        $this->manifest('staging_framing_runtime_manifest',['version'=>LabStagingFramingProtocol::VERSION,'validationStatus'=>'passed','implementation'=>$framing->implementation(),
            'pythonPath'=>PHP_BINARY,'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0']);
        $this->manifest('staging_layout_runtime_manifest',['version'=>LabStagingLayouts::VERSION,'validationStatus'=>'passed','files'=>app(LabStagingLayouts::class)->implementation(),
            'pythonPath'=>PHP_BINARY,'pythonSha256'=>hash_file('sha256',PHP_BINARY),'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0']);
        config(['iguide-lab.staging_framing_enabled'=>true,'iguide-lab.staging_layout_enabled'=>true]);
        $policy=$this->workflow->policy;$policy['stagingAssessment']=LabStagingAssessmentPolicy::snapshot();$policy['stagingFraming']=$framing->snapshot();$policy['stagingLayout']=app(LabStagingLayouts::class)->snapshot();$this->workflow->update(['policy'=>$policy]);
        $this->framing=$framing->start($framing->reserve($this->item));$candidate=FramingFixture::candidate($context);$candidate['view']=['yaw'=>0,'pitch'=>-10,'hFov'=>80];
        Http::fake(['api.openai.com/v1/responses'=>Http::response(FramingFixture::response($candidate),200,['x-request-id'=>'req-framing'])]);
        $framing->process($this->framing);$framing->process($this->framing->fresh());$this->framing=$this->framing->fresh();
        $this->assertSame('completed',$this->framing->status);
        $this->cut=$this->makeSection('staging-section',$candidate['view']);
        $this->app->instance(LabSectionAnalysisViews::class,new class extends LabSectionAnalysisViews {
            protected function diagnostics(string $requestPath):array {
                $request=json_decode(file_get_contents($requestPath),true);$out=$request['outputDirectory'];@mkdir($out.'/views',02770,true);$views=[];
                foreach(['front'=>[0,0],'right'=>[90,0],'back'=>[180,0],'left'=>[270,0],'zenith'=>[0,90],'nadir'=>[0,-90]] as $id=>[$yaw,$pitch]){
                    $bytes=(string)ImageManager::gd()->create(1024,1024)->fill('888888')->toPng();$path=$out.'/views/'.$id.'.png';file_put_contents($path,$bytes);
                    $views[]=['id'=>$id,'path'=>$path,'sha256'=>hash('sha256',$bytes),'width'=>1024,'height'=>1024,'yaw_degrees'=>$yaw,'pitch_degrees'=>$pitch,'hfov_degrees'=>100];}
                return ['source'=>$request['source'],'views'=>$views,'adapterSha256'=>hash_file('sha256',resource_path('iguide-lab/analysis_views.py')),'geometrySha256'=>hash_file('sha256',resource_path('iguide-lab/pano_geometry.py'))];
            }
        });
    }
    protected function tearDown():void {foreach($this->temporary as $file)if(is_file($file))unlink($file);parent::tearDown();}
    private function fake($callback=null):void {Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();Http::fake($callback);}
    private function manifest(string $key,array $value):void {$path=tempnam(sys_get_temp_dir(),'layout-runtime');$this->temporary[]=$path;file_put_contents($path,json_encode($value));config(['iguide-lab.'.$key=>$path]);}
    private function image(string $bytes,int $w,int $h):IguideLabCase {
        $id=(string)Str::uuid();$path='iguide-lab/'.$this->project->id.'/cases/'.$id.'.png';Storage::disk(LabService::DISK)->put($path,$bytes);
        return IguideLabCase::create(['id'=>$id,'project_id'=>$this->project->id,'label'=>'Synthetic source','projection'=>$w===$h?'rectilinear':'equirectangular','width'=>$w,'height'=>$h,'source_path'=>$path,'source_sha256'=>hash('sha256',$bytes),'source_mime'=>'image/png','metadata'=>[]]);
    }
    private function makeSection(string $key,array $view):IguideLabCase {
        $cut=$this->image((string)ImageManager::gd()->create(2048,2048)->fill('999999')->toPng(),2048,2048);$steps=app(LabWorkflowSteps::class);
        $step=$steps->reserve($this->item,$key,'section',['caseId'=>$this->native->id,'view'=>$view]);
        $section=IguideLabSection::create(['project_id'=>$this->project->id,'case_id'=>$this->native->id,'derived_case_id'=>$cut->id,'request_id'=>$step->request_id,'request_hash'=>str_repeat('a',64),'source_sha256'=>$this->native->source_sha256,'status'=>'completed','view'=>$view]);$steps->attach($step,$section->id);
        $cut->update(['metadata'=>['role'=>'section-source','parentCaseId'=>$this->native->id,'sectionId'=>$section->id]]);$disk=Storage::disk(LabService::DISK);$base='iguide-lab/'.$this->project->id.'/sections/'.$section->id.'/geometry/cut/';
        $disk->put($base.'views/section.png',$disk->get($cut->source_path));$geometry=['source'=>['path'=>$disk->path($this->native->source_path),'sha256'=>$this->native->source_sha256,'width'=>2048,'height'=>1024],
            'views'=>[['id'=>'section','path'=>$disk->path($base.'views/section.png'),'sha256'=>$cut->source_sha256,'width'=>2048,'height'=>2048,'yaw_degrees'=>$view['yaw'],'pitch_degrees'=>$view['pitch'],'hfov_degrees'=>$view['hFov']]]];
        $bytes=json_encode($geometry);$disk->put($base.'manifest.json',$bytes);$section->update(['data'=>['cutSha256'=>$cut->source_sha256,'geometryPath'=>$base.'manifest.json','geometrySha256'=>hash('sha256',$bytes)]]);$this->section=$section;return $cut;
    }
    private function reserve(?string $prior=null,?IguideLabCase $cut=null):IguideLabAnalysis {
        $cut??=$this->cut;$reference=app(LabStagingLayoutReadiness::class)->reference($this->item,$this->framing->id,$cut,$prior);
        $input=['caseId'=>$cut->id,'preset'=>'staging','style'=>'modern','instructions'=>'Propose exactly three complete objects.','options'=>LabAnalyses::EDIT_DEFAULTS,'stagingLayout'=>$reference];
        $steps=app(LabWorkflowSteps::class);$step=$steps->reserve($this->item,$prior?'staging-layout-followup':'staging-layout-initial','analysis',$input);
        $record=app(LabAnalyses::class)->start($this->project,$input+['requestId'=>$step->request_id],$steps->invocation($step));$steps->attach($step,$record->id);return $record;
    }
    private function response(array $context,string $decision='draft_candidate'):array {
        $fixture=new LayoutFixture('test_initial_explicit_plan_is_not_actual_placement_or_mask_authority');[,,,$value]=(new \ReflectionMethod($fixture,'fixture'))->invoke($fixture);
        $value['sourceSha256']=$context['sourceSha256'];$value['stagingLayout']['sourceSha256']=$context['sourceSha256'];
        $value['stagingLayout']['nativeSourceSha256']=$context['stagingLayout']['nativeSourceSha256'];$value['stagingLayout']['framingResultSha256']=$context['stagingLayout']['framingResultSha256'];
        $value['stagingLayout']['decision']=$decision;
        if($decision==='request_reframe'){$value['stagingLayout']['reframePose']=['yaw'=>10,'pitch'=>-15,'hFov'=>90];$value['workflow']['mode']='manual_review';}
        return FramingFixture::response($value);
    }
    private function finish(IguideLabAnalysis $record,string $decision='draft_candidate'):IguideLabAnalysis {
        $this->fake(function($request)use($decision){$context=json_decode($request['input'][0]['content'][0]['text'],true);return Http::response($this->response($context,$decision),200,['x-request-id'=>'req-layout']);});
        app(LabAnalyses::class)->process($record);$record=$record->fresh();$this->assertSame('completed',$record->status,$record->error??'');return $record;
    }
    public function test_completed_record_replays_exactly_and_missing_receipt_cannot_authorize_image():void {
        $record=$this->finish($this->reserve());$gate=app(LabStagingLayoutReadiness::class)->verified($record);$this->assertTrue($gate['eligibleForDraft']);
        $this->assertSame($record->id,$this->reserve()->id);Http::assertSentCount(1);$this->assertSame(0,$this->project->fresh()->used_runs);
        Storage::disk(LabService::DISK)->delete($record->data['stagingLayoutReceipt']['path']);$this->expectException(HttpException::class);app(LabStagingLayoutReadiness::class)->verified($record);
    }
    public function test_source_cut_and_geometry_drift_block_before_first_attempt():void {
        $record=$this->reserve();$disk=Storage::disk(LabService::DISK);$path=$this->section->data['geometryPath'];$disk->put($path,$disk->get($path).' ');
        $this->fake();app(LabAnalyses::class)->process($record);$this->assertSame('needs_attention',$record->fresh()->status);$this->assertEmpty($record->fresh()->provider_attempts);Http::assertNothingSent();
    }
    public function test_parent_framing_receipt_deleted_after_quota_blocks_fallback():void {
        $record=$this->reserve();$this->fake(function(){Storage::disk(LabService::DISK)->delete($this->framing->data['providerReceipt']['response']['path']);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabAnalyses::class)->process($record);$this->assertSame('needs_attention',$record->fresh()->status);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));Http::assertSentCount(1);
    }
    public function test_exact_reframe_and_full_prior_plan_are_bound_then_prior_receipt_drift_holds():void {
        $initial=$this->finish($this->reserve(),'request_reframe');$gate=app(LabStagingLayoutReadiness::class)->verified($initial,false);
        $cut=$this->makeSection('staging-reframed-section',$gate['reframePose']);$followup=$this->reserve($initial->id,$cut);
        $this->assertSame(1,$followup->data['stagingLayout']['followup']['ordinal']);$this->assertSame($gate,$followup->data['stagingLayout']['priorResult']['layout']);
        $disk=Storage::disk(LabService::DISK);$path=$initial->data['stagingLayoutReceipt']['path'];$disk->put($path,$disk->get($path).' ');
        $this->fake();app(LabAnalyses::class)->process($followup);$this->assertSame('needs_attention',$followup->fresh()->status);$this->assertEmpty($followup->fresh()->provider_attempts);Http::assertNothingSent();
    }
    public function test_cancel_after_quota_stops_fallback_without_spend_or_refund():void {
        $record=$this->reserve();$this->fake(function(){app(LabWorkflows::class)->cancel($this->workflow);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabAnalyses::class)->process($record);Http::assertSentCount(1);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));$this->assertSame(0,$this->project->fresh()->used_runs);
    }
    public function test_disabling_only_layout_after_quota_stops_its_fallback():void {
        $record=$this->reserve();$this->fake(function(){config(['iguide-lab.staging_layout_enabled'=>false]);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabAnalyses::class)->process($record);Http::assertSentCount(1);$this->assertTrue(config('iguide-lab.staging_framing_enabled'));
        $this->assertSame('needs_attention',$record->fresh()->status);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
    }
    public function test_completed_quota_path_rechecks_primary_model_identity():void {
        $record=$this->reserve();$count=0;$this->fake(function($request)use(&$count){
            if(++$count===1)return Http::response(['error'=>['code'=>'insufficient_quota']],429);
            $context=json_decode($request['input'][0]['content'][0]['text'],true);return Http::response($this->response($context),200,['x-request-id'=>'req-layout-fallback']);});
        app(LabAnalyses::class)->process($record);$record=$record->fresh();$this->assertSame('completed',$record->status,$record->error??'');
        $this->assertTrue(app(LabStagingLayoutReadiness::class)->verified($record)['eligibleForDraft']);Http::assertSentCount(2);
        $attempts=$record->provider_attempts;$attempts[0]['requestedModelId']='different-model';$record->update(['provider_attempts'=>$attempts]);
        $this->expectException(HttpException::class);app(LabStagingLayoutReadiness::class)->verified($record);
    }
    public function test_child_association_exists_before_queue_dispatch():void {
        $observed=false;$dispatcher=\Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function($job)use(&$observed){
            $this->assertInstanceOf(\App\Jobs\ProcessIguideLabAnalysis::class,$job);
            $record=IguideLabAnalysis::where('status','queued')->sole();
            $step=\App\Models\IguideLabWorkflowStep::findOrFail($record->data['workflowInvocation']['stepId']);
            $this->assertSame($record->id,$step->child_id);$observed=true;return null;
        });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class,$dispatcher);
        $record=$this->reserve();$this->assertTrue($observed);
        $this->assertSame('queued',$record->status);
    }
}
