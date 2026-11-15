<?php
namespace Tests\Feature;

use App\Jobs\ProcessIguideLabWorkflow;
use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabProject,IguideLabRun,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Services\IguideLab\{LabService,LabWorkflows,LabWorkflowScheduler,LabWorkflowPolicy,LabWorkflowArtifacts,LabAnalysisViews,LabWorkflowSteps,LabWorkflowInvocation,LabWorkflowCapacity,LabAnalysisRecipes};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IguideLabTourSchedulerTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabCase $case;
    private string $key;
    private string $token;
    protected function setUp():void {
        parent::setUp();Queue::fake();Http::preventStrayRequests();$this->travelTo(now()->startOfSecond());
        $this->key=tempnam(sys_get_temp_dir(),'tour-key-');file_put_contents($this->key,'fixture-only');
        config(['iguide-lab.tour_scheduler_enabled'=>true,'app.key'=>'base64:'.base64_encode(str_repeat('k',32)),
            'services.openai.api_key'=>'fixture-only','iguide-lab.openai_primary_key_path'=>$this->key]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        $disk=Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK));$disk->makeDirectory('');$disk->setVisibility('','private');
        $this->token=str_repeat('a',64);$this->project=IguideLabProject::create(['name'=>'Tour fixture','token_hash'=>hash('sha256',$this->token),'expires_at'=>now()->addDays(10),'max_runs'=>400]);
        $png=(string)\Intervention\Image\ImageManager::gd()->create(2048,1024)->fill('888888')->toPng();
        $this->case=app(LabService::class)->addCase($this->project,UploadedFile::fake()->createWithContent('source.png',$png),['label'=>'Original','projection'=>'equirectangular']);
        $this->mock(LabAnalysisViews::class)->shouldReceive('ready')->andReturn(true);
        $this->mock(LabWorkflowArtifacts::class,function($m){$m->shouldReceive('snapshot')->andReturn(['recipeVersion'=>'fixture/1']);
            $m->shouldReceive('validateSnapshot')->andReturnNull();$m->shouldReceive('ready')->andReturn(true);$m->shouldReceive('preflight')->andReturnNull();});
    }
    protected function tearDown():void {if(is_file($this->key))unlink($this->key);$this->travelBack();parent::tearDown();}
    private function body(int $count=1):array {
        return ['requestId'=>(string)Str::uuid(),'items'=>array_map(fn($i)=>['caseId'=>$this->case->id,'preset'=>'twilight','instructions'=>'Authorized fixture variant '.$i],range(1,$count))];
    }
    private function workflow(int $count=1):IguideLabWorkflow {return app(LabWorkflows::class)->start($this->project,$this->body($count));}
    private function fullDailyWindow():void {
        $rows=[];for($i=0;$i<120;$i++)$rows[]=['id'=>(string)Str::uuid(),'project_id'=>$this->project->id,'case_id'=>$this->case->id,
            'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'model_id'=>'gpt-image-2.6-sunburst','provider'=>'openai',
            'preset'=>'twilight','prompt'=>'Retained fixture','options'=>'{}','status'=>'completed','created_at'=>now(),'updated_at'=>now()];
        IguideLabRun::insert($rows);
    }
    private function analysisHistory(bool $recent):void {
        $rows=[];for($i=0;$i<120;$i++)$rows[]=['id'=>(string)Str::uuid(),'project_id'=>$this->project->id,'case_id'=>$this->case->id,
            'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('b',64),'source_sha256'=>$this->case->source_sha256,
            'settings'=>'{}','status'=>'needs_attention','created_at'=>$recent?now():now()->subDays(2),'updated_at'=>now()];
        IguideLabAnalysis::insert($rows);
    }
    public function test_eighty_items_reserve_once_and_cancel_without_any_provider_or_usage():void {
        $body=$this->body(80);
        // Distinct capture records, with shared synthetic image bytes only for test economy.
        $disk=Storage::disk(LabService::DISK);$bytes=$disk->get($this->case->source_path);
        foreach($body['items'] as $i=>&$entry){$capture=$this->case->replicate();$capture->id=(string)Str::uuid();$capture->label='Capture '.$i;$capture->pano_id=(string)$i;
            $capture->source_path='iguide-lab/'.$this->project->id.'/cases/'.$capture->id.'.png';$disk->put($capture->source_path,$bytes);$capture->save();$entry['caseId']=$capture->id;$entry['instructions']='';}unset($entry);
        $response=$this->withToken($this->token)->postJson('/api/iguide-lab/workflows',$body)->assertAccepted();
        $id=$response->json('data.id');$response->assertJsonPath('data.counts.total',80);
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows',$body)->assertAccepted()->assertJsonPath('data.id',$id);
        config(['iguide-lab.tour_scheduler_enabled'=>false]);
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows',$body)->assertAccepted()->assertJsonPath('data.id',$id);
        $new=$body;$new['requestId']=(string)Str::uuid();$this->withToken($this->token)->postJson('/api/iguide-lab/workflows',$new)->assertUnprocessable();
        $this->assertSame(80,IguideLabWorkflowItem::count());$this->assertSame(1,IguideLabWorkflow::count());
        Queue::assertPushed(ProcessIguideLabWorkflow::class,1);$this->assertSame(0,$this->project->fresh()->used_runs);
        $changed=$body;$changed['items']=array_reverse($changed['items']);$this->withToken($this->token)->postJson('/api/iguide-lab/workflows',$changed)->assertConflict();
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows/'.$id.'/cancel')->assertOk();
        $this->assertSame(80,IguideLabWorkflowItem::where('status','cancelled')->count());
        $this->assertTrue(app(LabWorkflows::class)->process(IguideLabWorkflow::findOrFail($id)));Http::assertNothingSent();
    }
    public function test_eighty_one_is_rejected_by_api_and_service_and_disabled_cap_is_twenty():void {
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows',$this->body(81))->assertUnprocessable();
        try{app(LabWorkflows::class)->start($this->project,$this->body(81));$this->fail();}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        config(['iguide-lab.tour_scheduler_enabled'=>false]);$this->assertSame(20,app(LabWorkflowPolicy::class)->capabilities()['maxItems']);
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows',$this->body(21))->assertUnprocessable();
        $this->assertSame(0,IguideLabWorkflow::count());Queue::assertNothingPushed();
    }
    public function test_only_first_item_starts_and_later_item_deadline_begins_on_its_admission():void {
        $w=$this->workflow(80);config(['iguide-lab.tour_scheduler_enabled'=>false]);
        $service=app(LabWorkflows::class);$service->process($w);$first=IguideLabWorkflowItem::orderBy('position')->first();
        $firstDeadline=$first->scheduler['deadlineAt'];$this->assertSame(1,IguideLabAnalysis::count());
        $this->assertSame(79,IguideLabWorkflowItem::whereNull('scheduler')->count());
        $this->travel(3)->hours();$service->process($w->fresh());$this->assertSame('item_deadline',$first->fresh()->data['reviewReasons'][0]['code']);
        $this->assertSame(1,IguideLabAnalysis::count());$service->process($w->fresh());
        $second=IguideLabWorkflowItem::where('position',1)->sole();$this->assertGreaterThan($firstDeadline,$second->scheduler['admittedAt']);
        $this->assertSame(now()->timestamp+7200,$second->scheduler['deadlineAt']);$this->assertSame(2,IguideLabAnalysis::count());
        $step=IguideLabWorkflowStep::where('item_id',$first->id)->sole();
        $this->expectException(HttpException::class);app(LabWorkflowInvocation::class)->claim(app(LabWorkflowSteps::class)->invocation($step),$this->project->id,$step->request_id,'analysis',fn()=>$this->fail('Expired item submitted.'));
    }
    public function test_daily_capacity_wait_has_no_clock_charge_child_or_usage_then_admits_exact_item():void {
        $this->fullDailyWindow();$w=$this->workflow();$service=app(LabWorkflows::class);$service->process($w);$item=IguideLabWorkflowItem::sole();
        $this->assertSame('queued',$item->status);$this->assertNull($item->scheduler['admittedAt']);$this->assertNull($item->scheduler['deadlineAt']);
        $this->assertSame('daily_image_capacity',$item->scheduler['capacityReason']);$this->assertSame(0,IguideLabWorkflowStep::count());
        $this->assertSame(0,$this->project->fresh()->used_runs);$this->assertSame(120,IguideLabRun::count());
        $this->travel(24)->hours();$this->travel(2)->seconds();$service->process($w->fresh());$item->refresh();
        $this->assertSame(now()->timestamp,$item->scheduler['admittedAt']);$this->assertSame(now()->timestamp+7200,$item->scheduler['deadlineAt']);
        $this->assertSame(1,IguideLabAnalysis::count());$id=IguideLabAnalysis::sole()->id;$service->process($w->fresh());
        $this->assertSame($id,IguideLabAnalysis::sole()->id);$this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_active_capacity_wait_preserves_child_identity_and_pauses_only_item_clock():void {
        $w=$this->workflow();$scheduler=app(LabWorkflowScheduler::class);$item=IguideLabWorkflowItem::sole();$this->assertTrue($scheduler->admit($w,$item,$this->project));
        $step=app(LabWorkflowSteps::class)->reserve($item,'image-1','image',['caseId'=>$this->case->id]);$deadline=$item->scheduler['deadlineAt'];
        $this->travel(1)->hours();$scheduler->hold($w,$item,new LabWorkflowCapacity('daily_image_capacity',now()->timestamp+3600));
        $this->assertFalse($scheduler->admit($w,$item->fresh(),$this->project));$this->travel(1)->hours();
        $this->assertTrue($scheduler->admit($w,$item->fresh(),$this->project));$item->refresh();$this->assertSame($deadline+3600,$item->scheduler['deadlineAt']);
        $this->assertSame($step->request_id,app(LabWorkflowSteps::class)->reserve($item,'image-1','image',['caseId'=>$this->case->id])->request_id);
        $this->assertSame(0,IguideLabRun::count());$this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_locked_image_capacity_race_keeps_reserved_uuid_and_replays_one_charge_after_wait():void {
        $w=$this->workflow();$service=app(LabWorkflows::class);$service->process($w);$analysis=IguideLabAnalysis::sole();
        $proposal=['generationPrompt'=>'Apply visible requested lighting while retaining the original sphere and matching reflected surfaces.','workflow'=>['mode'=>'whole_panorama'],'uncertainties'=>[]];
        $sha=hash('sha256',\App\Services\IguideLab\LabImages::prepared(Storage::disk(LabService::DISK)->get($this->case->source_path)));
        $analysis->update(['status'=>'completed','proposal'=>$proposal,'proposal_sha256'=>LabAnalysisRecipes::hash($proposal),
            'data'=>array_merge($analysis->data,['preparedSourceSha256'=>$sha,'repairContextManifestSha256'=>str_repeat('e',64)])]);
        // Another request consumes the rolling capacity after this item was admitted.
        $this->fullDailyWindow();$service->process($w->fresh());$step=IguideLabWorkflowStep::where('kind','image')->sole();
        $this->assertSame('daily_image_capacity',IguideLabWorkflowItem::sole()->scheduler['capacityReason']);
        $this->assertSame(120,IguideLabRun::count());$this->assertSame(0,$this->project->fresh()->used_runs);
        Queue::assertNotPushed(\App\Jobs\ProcessIguideLabRun::class);
        $this->travel(24)->hours();$this->travel(2)->seconds();$service->process($w->fresh());
        $run=IguideLabRun::where('request_id',$step->request_id)->sole();$service->process($w->fresh());
        $this->assertSame(121,IguideLabRun::count());$this->assertSame($run->id,IguideLabRun::where('request_id',$step->request_id)->sole()->id);
        $this->assertSame($step->request_id,$run->request_id);$this->assertSame(1,$this->project->fresh()->used_runs);
        Queue::assertPushed(\App\Jobs\ProcessIguideLabRun::class,1);Http::assertNothingSent();
    }
    public function test_cancellation_while_capacity_waiting_never_admits_after_the_wait_ends():void {
        $this->fullDailyWindow();$w=$this->workflow(80);$service=app(LabWorkflows::class);$service->process($w);
        $service->cancel($w);$this->travel(24)->hours();$this->travel(2)->seconds();
        $this->assertTrue($service->process($w->fresh()));$this->assertSame(80,IguideLabWorkflowItem::where('status','cancelled')->count());
        $this->assertSame(0,IguideLabWorkflowStep::count());$this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_cancel_and_source_drift_during_wait_do_not_create_a_child():void {
        $this->fullDailyWindow();$w=$this->workflow();$service=app(LabWorkflows::class);$service->process($w);
        Storage::disk(LabService::DISK)->put($this->case->source_path,'changed');$this->travel(24)->hours();$this->travel(2)->seconds();
        $service->process($w->fresh());$this->assertSame('needs_review',IguideLabWorkflowItem::sole()->status);$this->assertSame(0,IguideLabAnalysis::count());
        $service->cancel($w);$this->assertTrue($service->process($w->fresh()));$this->assertSame(0,IguideLabWorkflowStep::count());Http::assertNothingSent();
    }
    public function test_configured_project_capacity_is_explicit_hold_and_session_expiry_is_not_extended():void {
        $this->project->update(['max_runs'=>0,'expires_at'=>now()->addHour()]);$w=$this->workflow(80);
        $this->assertSame($this->project->expires_at->timestamp,$w->policy['scheduler']['expiresAt']);app(LabWorkflows::class)->process($w);
        $this->assertSame('project_image_capacity',IguideLabWorkflowItem::orderBy('position')->first()->data['reviewReasons'][0]['code']);
        $this->assertSame(0,IguideLabWorkflowStep::count());$this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_retry_until_is_fixed_across_serialization_and_past_480_poll_deliveries():void {
        $w=$this->workflow(80);$deadline=$w->policy['scheduler']['expiresAt'];$job=new ProcessIguideLabWorkflow($w->id,$deadline,LabAnalysisRecipes::hash($w->policy));
        $this->travel(3)->hours();$restored=unserialize(serialize($job));$this->assertSame($deadline,$restored->retryUntil());
        $this->assertSame(3,$restored->maxExceptions);app(LabWorkflowScheduler::class)->verifyJob($w,$restored->deadlineAt,$restored->policySha256);
        $queued=\Mockery::mock(\Illuminate\Contracts\Queue\Job::class);$queued->shouldReceive('maxTries')->andReturn(480);
        $queued->shouldReceive('retryUntil')->andReturn($deadline);$queued->shouldReceive('attempts')->andReturn(481);$queued->shouldNotReceive('fail');
        // This is Laravel's actual pre-dispatch guard, not a mirrored implementation.
        $guard=new \ReflectionMethod(\Illuminate\Queue\Worker::class,'markJobAsFailedIfAlreadyExceedsMaxAttempts');
        for($i=481;$i<=485;$i++)$guard->invoke(app('queue.worker'),'studio',$queued,480);
        $this->assertSame($deadline,$restored->retryUntil());$this->assertNull((new ProcessIguideLabWorkflow($w->id))->retryUntil());
        $this->expectException(HttpException::class);app(LabWorkflowScheduler::class)->verifyJob($w,$deadline+1,$restored->policySha256);
    }
    public function test_legacy_null_retry_until_retains_the_actual_laravel_delivery_ceiling():void {
        $job=new ProcessIguideLabWorkflow((string)Str::uuid());$this->assertNull($job->retryUntil());$this->assertNull($job->maxExceptions);
        config(['iguide-lab.tour_scheduler_enabled'=>false]);$legacy=$this->workflow();
        $old=(new \ReflectionClass(ProcessIguideLabWorkflow::class))->newInstanceWithoutConstructor();$old->workflowId=$legacy->id;
        $this->assertFalse((new \ReflectionProperty($old,'deadlineAt'))->isInitialized($old));
        $this->assertFalse((new \ReflectionProperty($old,'policySha256'))->isInitialized($old));
        $old=unserialize(serialize($old));$this->assertNull($old->retryUntil());$old->handle(app(LabWorkflows::class));
        $this->assertSame(1,IguideLabAnalysis::count());Http::assertNothingSent();
        $queued=\Mockery::mock(\Illuminate\Contracts\Queue\Job::class);$queued->shouldReceive('maxTries')->andReturn(480);
        $queued->shouldReceive('retryUntil')->andReturn(null);$queued->shouldReceive('attempts')->andReturn(481);
        $queued->shouldReceive('resolveName')->andReturn(ProcessIguideLabWorkflow::class);$queued->shouldReceive('fail')->once();
        $guard=new \ReflectionMethod(\Illuminate\Queue\Worker::class,'markJobAsFailedIfAlreadyExceedsMaxAttempts');
        $this->expectException(\Illuminate\Queue\MaxAttemptsExceededException::class);$guard->invoke(app('queue.worker'),'studio',$queued,480);
    }
    public function test_historical_analysis_ceiling_does_not_consume_owned_tour_allowance_but_manual_limit_remains():void {
        // An explicit object mirrors imported-source metadata. Empty PHP arrays are a
        // separate legacy DTO shape and must not obscure this count/capability proof.
        $this->case->update(['metadata'=>['fixtureOrigin'=>'synthetic scheduler read-contract source']]);
        $this->analysisHistory(false);$w=$this->workflow(80);app(LabWorkflows::class)->process($w);
        $this->assertSame(121,IguideLabAnalysis::count());$this->assertSame(160,$w->policy['scheduler']['recordAllowances']['analysis']);
        $this->assertSame(1,IguideLabWorkflowStep::count());$this->assertSame(0,$this->project->fresh()->used_runs);
        // Export genuine controller DTOs for the future strict-reader change.
        // Historical records here are synthetic needs-attention fixtures, not paid results.
        $current=IguideLabAnalysis::where('status','queued')->sole();
        IguideLabAnalysis::whereKeyNot($current->id)->update(['settings'=>json_encode($current->settings,JSON_THROW_ON_ERROR),'data'=>json_encode($current->data,JSON_THROW_ON_ERROR)]);
        $projectDto=$this->withToken($this->token)->getJson('/api/iguide-lab/')->assertOk()->assertJsonPath('data.capabilities.sceneAnalysis.usedAnalyses',121)->assertJsonPath('data.capabilities.sceneAnalysis.maxAnalyses',120);
        $workflowDto=$this->withToken($this->token)->getJson('/api/iguide-lab/workflows')->assertOk()->assertJsonPath('data.capabilities.maxItems',80);
        if($proof=getenv('IGUIDE_TEST_SCHEDULER_DTO_DIR')){
            file_put_contents($proof.'/project-over-120.json',$projectDto->getContent());
            file_put_contents($proof.'/workflow-80-items.json',$workflowDto->getContent());
        }
        $this->expectException(HttpException::class);
        app(\App\Services\IguideLab\LabAnalyses::class)->start($this->project,['requestId'=>(string)Str::uuid(),'caseId'=>$this->case->id,'preset'=>'twilight','instructions'=>'','options'=>\App\Services\IguideLab\LabAnalyses::EDIT_DEFAULTS]);
    }
    public function test_analysis_daily_wait_resumes_same_owned_uuid_without_duplicate_record_or_charge():void {
        $this->analysisHistory(true);$w=$this->workflow();$service=app(LabWorkflows::class);$service->process($w);$step=IguideLabWorkflowStep::sole();
        $this->assertSame('daily_analysis_capacity',IguideLabWorkflowItem::sole()->scheduler['capacityReason']);
        $this->assertSame(120,IguideLabAnalysis::count());$this->assertSame(0,$this->project->fresh()->used_runs);
        $this->travel(24)->hours();$this->travel(2)->seconds();$service->process($w->fresh());
        $record=IguideLabAnalysis::where('request_id',$step->request_id)->sole();$service->process($w->fresh());
        $this->assertSame(121,IguideLabAnalysis::count());$this->assertSame($record->id,IguideLabAnalysis::where('request_id',$step->request_id)->sole()->id);
        $this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_changed_record_allowance_is_not_a_client_or_database_override():void {
        $w=$this->workflow(80);$p=$w->policy;$p['scheduler']['recordAllowances']['analysis']++;$w->update(['policy'=>$p]);
        $this->expectException(HttpException::class);app(LabWorkflowScheduler::class)->policy($w->fresh());
    }
    public function test_evaluation_capacity_wait_resumes_even_when_its_image_used_the_last_project_allowance():void {
        $this->project->update(['max_runs'=>1]);$w=$this->workflow();$item=IguideLabWorkflowItem::sole();$scheduler=app(LabWorkflowScheduler::class);
        $this->assertTrue($scheduler->admit($w,$item,$this->project));
        $step=app(LabWorkflowSteps::class)->reserve($item,'evaluation-1','evaluation',[]);
        $this->project->update(['used_runs'=>1]);
        $scheduler->hold($w,$item,new LabWorkflowCapacity('daily_evaluation_capacity',now()->timestamp+60));
        $this->travel(61)->seconds();$this->assertTrue($scheduler->admit($w,$item->fresh(),$this->project));
        $this->assertSame('existing-image-evaluation',app(LabWorkflowInvocation::class)->claim(app(LabWorkflowSteps::class)->invocation($step),$this->project->id,$step->request_id,'evaluation',fn()=>'existing-image-evaluation'));
        $this->assertSame(1,$this->project->fresh()->used_runs);$this->assertSame(0,IguideLabRun::count());
        $this->assertNull($item->fresh()->scheduler['waitingSince']);Http::assertNothingSent();
    }
    public function test_only_owned_section_helper_uses_derived_allowance_beyond_source_upload_capacity():void {
        $w=$this->workflow();$item=IguideLabWorkflowItem::sole();app(LabWorkflowScheduler::class)->admit($w,$item,$this->project);
        for($i=0;$i<79;$i++){$case=$this->case->replicate();$case->id=(string)Str::uuid();$case->label='Historical source '.$i;$case->save();}
        $input=['caseId'=>$this->case->id,'view'=>['yaw'=>0,'pitch'=>0,'hFov'=>90]];
        $step=app(LabWorkflowSteps::class)->reserve($item,'section-1','section',$input);$inv=app(LabWorkflowSteps::class)->invocation($step);
        $this->mock(\App\Services\IguideLab\LabSectionGeometry::class)->shouldReceive('ready')->andReturn(true);
        $section=app(\App\Services\IguideLab\LabSections::class)->start($this->project,$input+['requestId'=>$step->request_id],$inv);
        $png=(string)\Intervention\Image\ImageManager::gd()->create(2048,2048)->fill('999999')->toPng();
        $helper=['label'=>'Owned extracted cut','projection'=>'rectilinear','metadata'=>['role'=>'section-source','sectionId'=>$section->id,'parentCaseId'=>$this->case->id,'nativeSourceSha256'=>$this->case->source_sha256]];
        $cut=app(LabService::class)->addCase($this->project,UploadedFile::fake()->createWithContent('section.png',$png),$helper,$inv);
        $this->assertSame(81,IguideLabCase::count());$this->assertSame($cut->id,app(LabService::class)->addCase($this->project,UploadedFile::fake()->createWithContent('section.png',$png),$helper,$inv)->id);
        $helper['label']='Public lineage cannot grant capacity';$this->expectException(HttpException::class);
        app(LabService::class)->addCase($this->project,UploadedFile::fake()->createWithContent('section.png',$png),$helper);
    }

    public function test_actual_process_job_advances_all_eighty_captures_across_every_recipe_sequentially_and_replays_terminal():void
    {
        $policy=app(LabWorkflowPolicy::class)->snapshot();
        $policy['stagingAssessment']=\App\Services\IguideLab\LabStagingAssessmentPolicy::snapshot();
        $policy['stagingLayout']=['version'=>\App\Services\IguideLab\LabStagingLayouts::VERSION];
        $policy['lifestyleAssessment']=\App\Services\IguideLab\LabLifestyleAssessmentPolicy::snapshot();
        $policy['grassAssessment']=\App\Services\IguideLab\LabGrassAssessmentPolicy::snapshot();
        $policy['grassSourceAdmission']=\App\Services\IguideLab\LabGrassSourceAdmissionPolicy::snapshot();
        $policy['declutterRemoval']=\App\Services\IguideLab\LabDeclutterRemovalPolicy::snapshot(true);
        $policy['interiorDesign']=\App\Services\IguideLab\LabInteriorDesignPolicy::snapshot();
        $policy['interiorRoomRouting']=\App\Services\IguideLab\LabInteriorRoomPolicy::snapshot();
        $this->partialMock(LabWorkflowPolicy::class)->shouldReceive('snapshot')->andReturn($policy);
        $visited=[];
        $drivers=[
            \App\Services\IguideLab\LabStagingWorkflow::class=>'staging-framing',
            \App\Services\IguideLab\LabLifestyleWorkflow::class=>'lifestyle-framing',
            \App\Services\IguideLab\LabGrassWorkflow::class=>'grass-source-check',
            \App\Services\IguideLab\LabDeclutterWorkflow::class=>'declutter-source-check',
            \App\Services\IguideLab\LabInteriorRoomWorkflow::class=>'room-route',
        ];
        // Only individual recipe work is a terminal stub. The queued job, workflow,
        // scheduler, exact source verification, ordered admission and reservations are real.
        foreach($drivers as $class=>$key)$this->mock($class)->shouldReceive('advance')->andReturnUsing(function($workflow,$item,$project)use($key,&$visited){
            app(\App\Services\IguideLab\LabWorkflowSources::class)->verify($project->id,$item->source);
            app(LabWorkflowScheduler::class)->assertWindow($workflow,$item);
            app(LabWorkflowSteps::class)->reserve($item,$key,'analysis',['caseId'=>$item->case_id]);
            $visited[]=$item->position;
            app(LabWorkflows::class)->finish($item,'needs_review','synthetic_source_hold','Scheduler fixture; no provider or quality claim.');
        });
        $body=$this->body(80);$presets=['staging','interior-design','lifestyle','grass','declutter','golden-hour','twilight'];
        $disk=Storage::disk(LabService::DISK);$bytes=$disk->get($this->case->source_path);
        foreach($body['items'] as $i=>&$entry){
            $source=$this->case->replicate();$source->id=(string)Str::uuid();$source->pano_id=(string)($i+1);$source->label='Capture '.($i+1);
            $source->source_path='iguide-lab/'.$this->project->id.'/cases/'.$source->id.'.png';$disk->put($source->source_path,$bytes);$source->save();
            $entry=['caseId'=>$source->id,'preset'=>$presets[$i%count($presets)],'instructions'=>''];
        }unset($entry);
        $service=app(LabWorkflows::class);$workflow=$service->start($this->project,$body);
        $job=unserialize(serialize(new ProcessIguideLabWorkflow($workflow->id,$workflow->policy['scheduler']['expiresAt'],LabAnalysisRecipes::hash($workflow->policy))));
        $positions=[];$ticks=0;
        while(!in_array($workflow->fresh()->status,LabWorkflows::TERMINAL,true)){
            $this->assertLessThan(161,++$ticks);
            $first=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->whereNotIn('status',LabWorkflows::TERMINAL)->orderBy('position')->firstOrFail();
            $job->handle($service);
            $this->assertSame(0,IguideLabWorkflowItem::where('workflow_id',$workflow->id)->where('position','>',$first->position)->whereNotNull('scheduler')->count());
            // Lighting traverses its real source-analysis reservation; its synthetic child
            // then holds on the following actual workflow tick, without an image.
            IguideLabAnalysis::where('status','queued')->update(['status'=>'needs_attention','error'=>'Synthetic retained source hold.']);
            if(in_array($first->fresh()->status,LabWorkflows::TERMINAL,true))$positions[]=$first->position;
        }
        $this->assertSame(range(0,79),$positions);$this->assertCount(58,$visited);
        $this->assertSame(80,IguideLabWorkflowItem::where('status','needs_review')->count());
        $this->assertSame(80,IguideLabWorkflowStep::count());$this->assertSame(22,IguideLabAnalysis::count());
        $this->assertSame(0,IguideLabRun::count());$this->assertSame(0,$this->project->fresh()->used_runs);
        $ids=IguideLabWorkflowStep::orderBy('id')->pluck('request_id')->all();
        config(['iguide-lab.tour_scheduler_enabled'=>false]);$this->assertSame($workflow->id,$service->start($this->project,$body)->id);
        $job->handle($service);$this->assertSame($ids,IguideLabWorkflowStep::orderBy('id')->pluck('request_id')->all());
        Http::assertNothingSent();
    }
}
