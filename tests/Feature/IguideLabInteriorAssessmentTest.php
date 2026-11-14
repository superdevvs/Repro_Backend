<?php
namespace Tests\Feature;
use App\Models\{IguideLabProject,IguideLabWorkflowItem,IguideLabWorkflowStep,IguideLabInteriorAssessment,IguideLabAnalysis,IguideLabRun};
use App\Services\IguideLab\{LabService,LabInteriorDesignContext,LabInteriorDesignFramingProtocol,LabInteriorDesignAssessments,LabInteriorDesignPolicy,LabWorkflowSteps,LabWorkflows,LabAnalysisRecipes};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Unit\IguideLabInteriorDesignFramingProtocolTest as Fixtures;

class IguideLabInteriorAssessmentTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary=[];
    private InteriorContextFixture $context;
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();$key=tempnam(sys_get_temp_dir(),'interior-key');file_put_contents($key,'fixture-primary');$this->temporary[]=$key;
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'services.openai.api_key'=>'fixture-fallback','iguide-lab.openai_primary_key_path'=>$key]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context=new InteriorContextFixture;$this->app->instance(LabInteriorDesignContext::class,$this->context);
        $manifest=tempnam(sys_get_temp_dir(),'interior-runtime');$this->temporary[]=$manifest;
        file_put_contents($manifest,json_encode(['version'=>LabInteriorDesignPolicy::VERSION,'validationStatus'=>'passed','implementation'=>app(LabInteriorDesignAssessments::class)->implementation(),
            'pythonPath'=>(string)config('iguide-lab.pipeline_python_path'),'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0']));
        config(['iguide-lab.interior_design_runtime_manifest'=>$manifest]);
    }
    protected function tearDown(): void {foreach($this->temporary as $path)if(is_file($path))unlink($path);parent::tearDown();}
    private function fixture(): array
    {
        $project=IguideLabProject::create(['name'=>'Interior authority','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        [$context,$images]=Fixtures::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]);
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('native.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'interior-design','style'=>'modern']]]);
        $policy=$workflow->policy;$policy['interiorDesign']=LabInteriorDesignPolicy::snapshot();$policy['interiorAssessments']=app(LabInteriorDesignAssessments::class)->snapshot();$workflow->update(['policy'=>$policy]);
        config(['iguide-lab.interior_design_workflow_enabled'=>true]);$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();$context['nativeSourceSha256']=$case->source_sha256;
        $assets=[];foreach($images as $i=>$image){$path='iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/fixture-'.$i.'.png';Storage::disk(LabService::DISK)->put($path,base64_decode(explode(',',$image,2)[1]));$assets[]=['id'=>$context['views'][$i]['id'],'path'=>$path,'sha256'=>$context['views'][$i]['sha256']];}
        $this->context->bundle=['context'=>$context,'assets'=>$assets,'implementation'=>$this->context->snapshot(),'geometryManifestSha256'=>str_repeat('d',64)];
        $service=app(LabInteriorDesignAssessments::class);$step=$service->reserve($item,'framing');$record=$service->start($step);
        return [$project,$workflow,$item,$case,$step,$record];
    }
    private function response(): array {return Fixtures::response(Fixtures::candidate($this->context->bundle['context']));}
    private function finish(IguideLabInteriorAssessment $record): IguideLabInteriorAssessment
    {
        Http::fake(['api.openai.com/v1/responses'=>Http::response($this->response(),200,['x-request-id'=>'req-interior'])]);
        $service=app(LabInteriorDesignAssessments::class);$service->process($record);$service->process($record->fresh());return $record->fresh();
    }
    public function test_typed_framing_receipt_is_exactly_once_and_cannot_be_mislabeled_as_scene_analysis(): void
    {
        [$project,,$item,,$step,$record]=$this->fixture();$record=$this->finish($record);$service=app(LabInteriorDesignAssessments::class);
        $this->assertSame('completed',$record->status);$service->process($record);$this->assertSame($record->id,$service->start($step)->id);
        $this->assertSame($record->id,$service->completed($item,$record->id,'framing')->id);Http::assertSentCount(1);
        $this->assertSame(0,IguideLabAnalysis::count());$this->assertSame(0,IguideLabRun::count());$this->assertSame(0,$project->fresh()->used_runs);
        $this->assertFalse($record->result['imageGenerationAuthorized']);$this->assertFalse($record->result['materialTransferAuthorized']);
    }
    public function test_committed_but_unattached_never_submitted_record_recovers_same_id_and_job(): void
    {
        [,,,,$step,$record]=$this->fixture();$step->update(['child_id'=>null]);Queue::fake();
        $this->assertSame($record->id,app(LabInteriorDesignAssessments::class)->start($step->fresh())->id);
        $this->assertSame($record->id,$step->fresh()->child_id);Queue::assertPushed(\App\Jobs\ProcessIguideLabInteriorAssessment::class,1);$this->finish($record);Http::assertSentCount(1);
    }
    public function test_ambiguous_transport_or_submitting_replay_never_dispatches_again(): void
    {
        [,,,,$step,$record]=$this->fixture();$count=0;Http::fake(function()use(&$count){$count++;throw new \Illuminate\Http\Client\ConnectionException('lost receipt');});
        $service=app(LabInteriorDesignAssessments::class);$service->process($record);Queue::fake();$service->start($step);$service->process($record->fresh());
        $this->assertSame(1,$count);$this->assertSame('unknown',$record->fresh()->provider_attempts[0]['status']);$this->assertSame('needs_attention',$record->fresh()->status);Queue::assertNothingPushed();
    }
    public function test_cancel_between_primary_quota_and_fallback_stops_second_paid_claim(): void
    {
        [,$workflow,,,,$record]=$this->fixture();$count=0;Http::fake(function()use($workflow,&$count){$count++;app(LabWorkflows::class)->cancel($workflow);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabInteriorDesignAssessments::class)->process($record);$this->assertSame(1,$count);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
    }
    public function test_parent_authority_is_revalidated_again_before_quota_fallback(): void
    {
        [,,,,,$record]=$this->fixture();$count=0;Http::fake(function()use(&$count){$count++;$this->context->bundle['context']['instructions']='changed after first attempt';return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabInteriorDesignAssessments::class)->process($record);$this->assertSame(1,$count);$this->assertSame('needs_attention',$record->fresh()->status);
    }
    public function test_confirmed_quota_fallback_preserves_payload_and_received_resume_never_resubmits(): void
    {
        [,,$item,,,$record]=$this->fixture();Http::fakeSequence('api.openai.com/v1/responses')->push(['error'=>['code'=>'insufficient_quota']],429)->push($this->response(),200,['x-request-id'=>'req-interior-fallback']);
        $service=app(LabInteriorDesignAssessments::class);$service->process($record);$this->assertSame('received',$record->fresh()->status);$service->process($record->fresh());
        $this->assertSame('completed',$record->fresh()->status);Http::assertSentCount(2);$requests=Http::recorded();$this->assertSame($requests[0][0]->body(),$requests[1][0]->body());
        $attempts=$record->fresh()->provider_attempts;$attempts[0]['requestedModelId']='different-model';$record->update(['provider_attempts'=>$attempts]);
        $this->expectException(HttpException::class);$service->completed($item,$record->id,'framing');
    }
    public function test_receipt_file_and_result_drift_or_foreign_item_cannot_reuse_authority(): void
    {
        [,, $item,,$step,$record]=$this->fixture();$record=$this->finish($record);$service=app(LabInteriorDesignAssessments::class);$disk=Storage::disk(LabService::DISK);
        $path=$record->data['providerReceipt']['response']['path'];$bytes=$disk->get($path);$disk->put($path,$bytes.' ');
        try{$service->completed($item,$record->id,'framing');$this->fail('Receipt mutation accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}$disk->put($path,$bytes);
        $result=$record->result;$changed=$result;$changed['view']['yaw']=80;$record->update(['result'=>$changed]);
        try{$service->completed($item,$record->id,'framing');$this->fail('Result mutation accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}$record->update(['result'=>$result]);
        $foreign=$item->replicate();$foreign->position=1;$foreign->save();$this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);$service->completed($foreign,$record->id,'framing');
    }
    public function test_original_source_drift_and_disabled_flag_stop_queued_resubmission(): void
    {
        [,,,$case,$step,$record]=$this->fixture();config(['iguide-lab.interior_design_workflow_enabled'=>false]);Queue::fake();
        try{app(LabInteriorDesignAssessments::class)->start($step);$this->fail('Disabled capability dispatched.');}catch(HttpException $e){$this->assertSame(503,$e->getStatusCode());}Queue::assertNothingPushed();
        config(['iguide-lab.interior_design_workflow_enabled'=>true]);Storage::disk(LabService::DISK)->put($case->source_path,'changed');app(LabInteriorDesignAssessments::class)->process($record);
        $this->assertSame('needs_attention',$record->fresh()->status);Http::assertNothingSent();
    }
    public function test_three_phase_budget_is_separate_from_legacy_and_fourth_analysis_is_rejected(): void
    {
        [,, $item]=$this->fixture();$steps=app(LabWorkflowSteps::class);$steps->reserve($item,'interior-cut','analysis',[]);$steps->reserve($item,'interior-candidate','analysis',[]);
        $this->expectException(HttpException::class);$steps->reserve($item,'fourth','analysis',[]);
    }
    public function test_default_off_and_stale_runtime_cannot_claim_ready(): void
    {
        $this->assertFalse(config('iguide-lab.interior_design_workflow_enabled'));$policy=app(\App\Services\IguideLab\LabWorkflowPolicy::class)->snapshot();$this->assertArrayNotHasKey('interiorDesign',$policy);
        $path=config('iguide-lab.interior_design_runtime_manifest');$m=json_decode(file_get_contents($path),true);$m['implementation']['framing']['protocolSha256']=str_repeat('0',64);file_put_contents($path,json_encode($m));
        $this->assertFalse(app(LabInteriorDesignAssessments::class)->ready());$this->expectException(HttpException::class);app(LabInteriorDesignAssessments::class)->snapshot();
    }
}
class InteriorContextFixture extends LabInteriorDesignContext
{
    public array $bundle=[];
    public function capture(IguideLabWorkflowItem $item,string $phase,array $parents): array {return $this->bundle;}
}
