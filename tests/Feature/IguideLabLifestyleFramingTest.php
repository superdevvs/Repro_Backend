<?php
namespace Tests\Feature;
use App\Models\{IguideLabProject,IguideLabWorkflowItem,IguideLabWorkflowStep,IguideLabLifestyleFraming,IguideLabAnalysis};
use App\Services\IguideLab\{LabService,LabLifestyleFramingContext,LabLifestyleFramingProtocol,LabLifestyleFramings,LabLifestyleFramingProvider,LabLifestyleAssessmentPolicy,LabWorkflowSteps,LabWorkflows,LabWorkflowInvocation,LabLifestyleCutReadiness,LabAnalysisRecipes};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Unit\IguideLabLifestyleFramingProtocolTest as Fixtures;

class IguideLabLifestyleFramingTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary=[];
    private LifestyleFramingContextFixture $context;
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();$key=tempnam(sys_get_temp_dir(),'framing-key');file_put_contents($key,'fixture-primary');$this->temporary[]=$key;
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'services.openai.api_key'=>'fixture-fallback','iguide-lab.openai_primary_key_path'=>$key]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context=new LifestyleFramingContextFixture;$this->app->instance(LabLifestyleFramingContext::class,$this->context);
        $manifest=tempnam(sys_get_temp_dir(),'framing-runtime');$this->temporary[]=$manifest;
        file_put_contents($manifest,json_encode(['version'=>LabLifestyleFramingProtocol::VERSION,'validationStatus'=>'passed','implementation'=>app(LabLifestyleFramings::class)->implementation(),
            'pythonPath'=>(string)config('iguide-lab.pipeline_python_path'),'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0']));
        config(['iguide-lab.lifestyle_framing_runtime_manifest'=>$manifest]);
    }
    protected function tearDown(): void {foreach($this->temporary as $path)if(is_file($path))unlink($path);parent::tearDown();}
    private function fixture(): array
    {
        $project=IguideLabProject::create(['name'=>'Source framing','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        [$context,$images]=Fixtures::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]);
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('native.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'lifestyle']]]);
        $policy=$workflow->policy;$policy['lifestyleAssessment']=LabLifestyleAssessmentPolicy::snapshot();$policy['lifestyleFraming']=app(LabLifestyleFramings::class)->snapshot();$workflow->update(['policy'=>$policy]);
        config(['iguide-lab.lifestyle_framing_enabled'=>true]);
        $item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();$context['sourceSha256']=$case->source_sha256;
        $assets=[];foreach($images as $i=>$image){$path='iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/fixture-'.$i.'.png';Storage::disk(LabService::DISK)->put($path,base64_decode(explode(',',$image,2)[1]));$assets[]=['id'=>$context['views'][$i]['id'],'path'=>$path,'sha256'=>$context['views'][$i]['sha256']];}
        $this->context->bundle=['context'=>$context,'assets'=>$assets,'preparedSourceSha256'=>$context['views'][0]['sha256'],'implementation'=>$this->context->snapshot(),'geometryManifestSha256'=>str_repeat('d',64)];
        $service=app(LabLifestyleFramings::class);$step=$service->reserve($item);$record=$service->start($step);
        return [$project,$workflow,$item,$case,$step,$record];
    }
    private function response(): array {return Fixtures::response(Fixtures::candidate($this->context->bundle['context']));}
    private function finish(IguideLabLifestyleFraming $record): IguideLabLifestyleFraming
    {
        Http::fake(['api.openai.com/v1/responses'=>Http::response($this->response(),200,['x-request-id'=>'req-framing'])]);
        $service=app(LabLifestyleFramings::class);$service->process($record);$service->process($record->fresh());return $record->fresh();
    }
    public function test_typed_receipt_resume_is_exactly_once_and_never_a_scene_plan_or_image(): void
    {
        [$project,,$item,,$step,$record]=$this->fixture();$record=$this->finish($record);$service=app(LabLifestyleFramings::class);
        $this->assertSame('completed',$record->status);$service->process($record);$this->assertSame($record->id,$service->start($step)->id);
        $this->assertSame($record->id,$service->completed($item,$record->id)->id);Http::assertSentCount(1);
        $this->assertSame(0,IguideLabAnalysis::count());$this->assertSame(0,$project->fresh()->used_runs);$this->assertFalse($record->result['imageGenerationAuthorized']);
        $this->assertSame(1,IguideLabWorkflowStep::where('kind','analysis')->count());
    }
    public function test_never_submitted_record_recovers_missing_child_attachment_and_queue_dispatch(): void
    {
        [,,,,$step,$record]=$this->fixture();$step->update(['child_id'=>null]);Queue::fake();
        $recovered=app(LabLifestyleFramings::class)->start($step->fresh());$this->assertSame($record->id,$recovered->id);$this->assertSame($record->id,$step->fresh()->child_id);
        Queue::assertPushed(\App\Jobs\ProcessIguideLabLifestyleFraming::class,1);$this->finish($record);Http::assertSentCount(1);
    }
    public function test_unknown_submission_and_submitting_replay_never_redispatch(): void
    {
        [,,,,$step,$record]=$this->fixture();$record->update(['status'=>'submitting','started_at'=>now()]);Queue::fake();
        app(LabLifestyleFramings::class)->start($step);Queue::assertNothingPushed();app(LabLifestyleFramings::class)->process($record->fresh());
        $this->assertSame('needs_attention',$record->fresh()->status);Http::assertNothingSent();
    }
    public function test_transport_ambiguity_never_repeats_the_paid_request(): void
    {
        [,,,,,$record]=$this->fixture();$count=0;Http::fake(function()use(&$count){$count++;throw new \Illuminate\Http\Client\ConnectionException('lost receipt');});
        $service=app(LabLifestyleFramings::class);$service->process($record);$service->process($record->fresh());
        $this->assertSame(1,$count);$this->assertSame('unknown',$record->fresh()->provider_attempts[0]['status']);$this->assertSame('needs_attention',$record->fresh()->status);
    }
    public function test_cancel_after_quota_prevents_fallback_claim_and_cancelled_queue_recovery(): void
    {
        [,$workflow,,,,$record]=$this->fixture();$count=0;
        Http::fake(function()use($workflow,&$count){$count++;app(LabWorkflows::class)->cancel($workflow);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabLifestyleFramings::class)->process($record);$this->assertSame(1,$count);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
    }
    public function test_quota_fallback_uses_identical_body_and_received_receipt_is_not_resubmitted(): void
    {
        [,,,,,$record]=$this->fixture();Http::fakeSequence('api.openai.com/v1/responses')->push(['error'=>['code'=>'insufficient_quota']],429)->push($this->response(),200,['x-request-id'=>'req-fallback']);
        $service=app(LabLifestyleFramings::class);$service->process($record);$this->assertSame('received',$record->fresh()->status);$service->process($record->fresh());
        $this->assertSame('completed',$record->fresh()->status);Http::assertSentCount(2);$requests=Http::recorded();$this->assertSame($requests[0][0]->body(),$requests[1][0]->body());
    }
    public function test_raw_receipt_and_result_tampering_or_context_drift_cannot_authorize_a_cut(): void
    {
        [,, $item,,$step,$record]=$this->fixture();$record=$this->finish($record);$service=app(LabLifestyleFramings::class);
        $path=$record->data['providerReceipt']['response']['path'];$disk=Storage::disk(LabService::DISK);$raw=$disk->get($path);$disk->put($path,$raw.' ');
        try{$service->completed($item,$record->id);$this->fail('Changed raw receipt accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}$disk->put($path,$raw);
        $result=$record->result;$changed=$result;$changed['view']['yaw']=11;$record->update(['result'=>$changed]);
        try{$service->completed($item,$record->id);$this->fail('Changed result accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}$record->update(['result'=>$result]);
        $this->context->bundle['context']['instructions']='changed';$this->expectException(HttpException::class);$service->completed($item,$record->id);
    }
    public function test_changed_source_file_blocks_submission(): void
    {
        [,, $item,$case,,$record]=$this->fixture();Storage::disk(LabService::DISK)->put($case->source_path,'changed');app(LabLifestyleFramings::class)->process($record);
        $this->assertSame('needs_attention',$record->fresh()->status);Http::assertNothingSent();
    }
    public function test_foreign_item_cannot_reuse_a_completed_framing_record(): void
    {
        [,, $item,,,$record]=$this->fixture();$record=$this->finish($record);$foreign=$item->replicate();$foreign->position=1;$foreign->save();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);app(LabLifestyleFramings::class)->completed($foreign,$record->id);
    }
    public function test_disabling_framing_blocks_queued_dispatch_recovery_before_any_submission(): void
    {
        [,,,,$step]=$this->fixture();config(['iguide-lab.lifestyle_framing_enabled'=>false]);Queue::fake();
        try{app(LabLifestyleFramings::class)->start($step);$this->fail('Disabled framing dispatched.');}catch(HttpException $e){$this->assertSame(503,$e->getStatusCode());}
        Queue::assertNothingPushed();Http::assertNothingSent();
    }
    public function test_image_claim_requires_actual_cut_authority_and_cannot_use_framing_alone(): void
    {
        [$project,,$item]=$this->fixture();$steps=app(LabWorkflowSteps::class);$step=$steps->reserve($item,'lifestyle-image','image',['caseId'=>$item->case_id,'options'=>[]]);
        $called=false;$this->mock(LabLifestyleCutReadiness::class)->shouldReceive('verifyImageStep')->once()->withArgs(fn($s)=>$s->id===$step->id)->andThrow(new HttpException(422,'Actual cut uncertain.'));
        try{app(LabWorkflowInvocation::class)->claim($steps->invocation($step),$project->id,$step->request_id,'image',function()use(&$called){$called=true;});$this->fail('Missing cut check accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->assertFalse($called);Http::assertNothingSent();
    }
    public function test_three_analysis_budget_counts_typed_framing_without_raising_lighting_budget(): void
    {
        [,, $item]=$this->fixture();$steps=app(LabWorkflowSteps::class);$steps->reserve($item,'cut-plan','analysis',[]);$steps->reserve($item,'placement','analysis',[]);
        $this->expectException(HttpException::class);$steps->reserve($item,'fourth','analysis',[]);
    }
    public function test_stale_validated_code_manifest_is_not_ready_and_no_flag_means_legacy_policy(): void
    {
        $this->assertFalse(config('iguide-lab.lifestyle_framing_enabled'));$policy=app(\App\Services\IguideLab\LabWorkflowPolicy::class)->snapshot();$this->assertArrayNotHasKey('lifestyleFraming',$policy);
        $path=config('iguide-lab.lifestyle_framing_runtime_manifest');$m=json_decode(file_get_contents($path),true);$m['implementation']['protocolSha256']=str_repeat('0',64);file_put_contents($path,json_encode($m));
        $this->assertFalse(app(LabLifestyleFramings::class)->ready());$this->expectException(HttpException::class);app(LabLifestyleFramings::class)->snapshot();
    }
}
class LifestyleFramingContextFixture extends LabLifestyleFramingContext
{
    public array $bundle=[];
    public function capture(IguideLabWorkflowItem $item): array {return $this->bundle;}
}
