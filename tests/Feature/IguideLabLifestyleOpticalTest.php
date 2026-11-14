<?php
namespace Tests\Feature;
use App\Models\{IguideLabProject,IguideLabWorkflowItem,IguideLabLifestyleFraming,IguideLabWorkflowStep};
use App\Services\IguideLab\{LabLifestyleOpticalDetails,LabLifestyleOpticalProtocol,LabLifestyleFramingProtocol,LabLifestyleFramingContext,LabLifestyleFramings,LabLifestyleAssessmentPolicy,LabWorkflowSteps,LabWorkflows,LabService,LabAnalysisRecipes};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Unit\IguideLabLifestyleOpticalProtocolTest as F;
use Tests\Unit\IguideLabLifestyleFramingProtocolTest as Wire;

class IguideLabLifestyleOpticalTest extends TestCase
{
    use RefreshDatabase;
    private array $temporary=[];
    private OpticalOriginalContextFixture $context;
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();$key=tempnam(sys_get_temp_dir(),'optical-key');file_put_contents($key,'fixture-primary');$this->temporary[]=$key;
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'services.openai.api_key'=>'fixture-fallback','iguide-lab.openai_primary_key_path'=>$key,'iguide-lab.pipeline_python_path'=>PHP_BINARY]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context=new OpticalOriginalContextFixture;$this->app->instance(LabLifestyleFramingContext::class,$this->context);
        $this->app->instance(LabLifestyleOpticalDetails::class,new OpticalDetailFixture);
        foreach(['lifestyle_framing_runtime_manifest'=>[LabLifestyleFramingProtocol::VERSION,app(LabLifestyleFramings::class)->implementation()],
            'lifestyle_optical_runtime_manifest'=>[LabLifestyleOpticalProtocol::VERSION,app(LabLifestyleOpticalDetails::class)->implementation()]] as $key=>[$version,$code]){
            $path=tempnam(sys_get_temp_dir(),'optical-runtime');$this->temporary[]=$path;
            file_put_contents($path,json_encode(['version'=>$version,'validationStatus'=>'passed','implementation'=>$code,'pythonPath'=>PHP_BINARY,'pythonSha256'=>hash_file('sha256',PHP_BINARY),
                'pythonVersion'=>'3.12.3','numpyVersion'=>'2.5.4','pillowVersion'=>'13.0.0']));config(['iguide-lab.'.$key=>$path]);}
    }
    protected function tearDown(): void {foreach($this->temporary as $p)if(is_file($p))unlink($p);parent::tearDown();}
    private function fixture(): array
    {
        $project=IguideLabProject::create(['name'=>'Synthetic optical follow-up','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $im=imagecreatetruecolor(4096,2048);imagefill($im,0,0,imagecolorallocate($im,70,90,100));ob_start();imagepng($im);$native=ob_get_clean();imagedestroy($im);
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('native.png',$native),['label'=>'Synthetic original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'lifestyle']]]);
        $policy=$workflow->policy;$policy['lifestyleAssessment']=LabLifestyleAssessmentPolicy::snapshot();$policy['lifestyleFraming']=app(LabLifestyleFramings::class)->snapshot();$policy['lifestyleOpticalDetails']=app(LabLifestyleOpticalDetails::class)->snapshot();$workflow->update(['policy'=>$policy]);
        config(['iguide-lab.lifestyle_framing_enabled'=>true,'iguide-lab.lifestyle_optical_details_enabled'=>true]);
        $item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();[$c,$images]=Wire::fixture();$c['sourceSha256']=$case->source_sha256;$assets=[];
        foreach($images as $i=>$image){$path='iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/fixture-'.$i.'.png';Storage::disk(LabService::DISK)->put($path,base64_decode(explode(',',$image,2)[1]));$assets[]=['id'=>$c['views'][$i]['id'],'path'=>$path,'sha256'=>$c['views'][$i]['sha256']];}
        $this->context->bundle=['context'=>$c,'assets'=>$assets,'preparedSourceSha256'=>$c['views'][0]['sha256'],'implementation'=>$this->context->snapshot(),'geometryManifestSha256'=>str_repeat('d',64)];
        $service=app(LabLifestyleFramings::class);$step=$service->reserve($item);$record=$service->start($step);return [$project,$workflow,$item,$case,$step,$record];
    }
    private function finish(IguideLabLifestyleFraming $record,string $kind='enquiry'): IguideLabLifestyleFraming
    {
        $c=$record->data['contextSnapshot']['context'];$v=$kind==='enquiry'?F::enquiry($c):F::candidate($c);
        if($kind==='hold'){$v['status']='needs_review';$v['view']=null;$v['constraints']['optical_dependencies']['status']='unresolved';}
        $this->resetHttp();Http::fake(['api.openai.com/v1/responses'=>Http::response(Wire::response($v),200,['x-request-id'=>'req-'.$record->id])]);
        $service=app(LabLifestyleFramings::class);$service->process($record);$service->process($record->fresh());$this->assertSame('completed',$record->fresh()->status);return $record->fresh();
    }
    private function followup(): array
    {
        [$project,$workflow,$item,$case,$step,$parent]=$this->fixture();$parent=$this->finish($parent);$service=app(LabLifestyleFramings::class);
        $step=$service->reserveFollowup($item,$parent->id);$record=$service->start($step);$this->resetHttp();return [$project,$workflow,$item,$case,$step,$parent,$record];
    }
    private function resetHttp(): void {Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();}
    public function test_one_followup_has_stable_identity_original_receipt_and_no_image_spend(): void
    {
        [$project,,$item,,$step,$parent,$record]=$this->followup();$record=$this->finish($record,'candidate');$service=app(LabLifestyleFramings::class);
        $this->assertSame($step->id,$service->reserveFollowup($item,$parent->id)->id);$this->assertSame($record->id,$service->start($step)->id);
        $service->process($record);$this->assertSame($record->id,$service->completed($item,$record->id)->id);Http::assertSentCount(1);
        $this->assertCount(8,$record->data['contextSnapshot']['context']['views']);$this->assertSame(0,$project->fresh()->used_runs);
        $this->assertSame(2,IguideLabWorkflowStep::where('kind','analysis')->count());$this->assertSame(0,IguideLabWorkflowStep::where('kind','image')->count());
        $this->assertSame(LabLifestyleOpticalProtocol::VERSION,$service->dto($record)['version']);$this->assertFalse($record->result['imageGenerationAuthorized']);
        if($dir=getenv('IGUIDE_TEST_OPTICAL_DTO_DIR')){
            file_put_contents($dir.'/initial-framing.dto.json',json_encode($service->dto($parent),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            file_put_contents($dir.'/followup-framing.dto.json',json_encode($service->dto($record),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            file_put_contents($dir.'/followup-context.json',json_encode($record->data['contextSnapshot']['context'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        }
        $this->expectException(HttpException::class);$service->reserveFollowup($item,$record->id);
    }
    public function test_remaining_optics_holds_without_third_analysis_or_any_image(): void
    {
        [,, $item,,$step,$parent,$record]=$this->followup();$held=$this->finish($record,'hold');
        $this->assertSame('needs_review',$held->result['status']);$this->assertNull($held->result['view']);
        $this->expectException(HttpException::class);app(LabLifestyleFramings::class)->reserveFollowup($item,$held->id);
    }
    public function test_parent_raw_receipt_drift_after_primary_quota_blocks_fallback(): void
    {
        [,,,,,$parent,$record]=$this->followup();$path=$parent->data['providerReceipt']['response']['path'];
        Http::fake(function()use($path){Storage::disk(LabService::DISK)->put($path,'tampered receipt');return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        $service=app(LabLifestyleFramings::class);$service->process($record);$service->process($record->fresh());Http::assertSentCount(1);
        $this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));$this->assertSame('needs_attention',$record->fresh()->status);
    }
    public function test_native_detail_byte_drift_after_quota_blocks_fallback(): void
    {
        [,,,,,,$record]=$this->followup();$path=$record->data['contextSnapshot']['assets'][7]['path'];
        Http::fake(function()use($path){Storage::disk(LabService::DISK)->put($path,'tampered native detail');return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabLifestyleFramings::class)->process($record);Http::assertSentCount(1);$this->assertSame('needs_attention',$record->fresh()->status);
    }
    public function test_cancel_after_quota_blocks_followup_and_queued_recovery(): void
    {
        [,$workflow,,,$step,,$record]=$this->followup();Http::fake(function()use($workflow){app(LabWorkflows::class)->cancel($workflow);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabLifestyleFramings::class)->process($record);Http::assertSentCount(1);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
    }
    public function test_unknown_submission_does_not_repeat_and_queue_gap_reuses_child(): void
    {
        [,,,,$step,,$record]=$this->followup();$step->update(['child_id'=>null]);Queue::fake();$service=app(LabLifestyleFramings::class);
        $this->assertSame($record->id,$service->start($step->fresh())->id);$this->assertSame($record->id,$step->fresh()->child_id);Queue::assertPushed(\App\Jobs\ProcessIguideLabLifestyleFraming::class,1);
        $record->update(['status'=>'submitting','started_at'=>now()]);Queue::fake();$service->start($step->fresh());Queue::assertNothingPushed();$service->process($record->fresh());$this->assertSame('needs_attention',$record->fresh()->status);
    }
    public function test_quota_fallback_same_payload_and_primary_model_tamper_held(): void
    {
        [,, $item,,,,$record]=$this->followup();$c=$record->data['contextSnapshot']['context'];
        Http::fakeSequence('api.openai.com/v1/responses')->push(['error'=>['code'=>'insufficient_quota']],429)->push(Wire::response(F::candidate($c)),200,['x-request-id'=>'req-fallback']);
        $service=app(LabLifestyleFramings::class);$service->process($record);$service->process($record->fresh());$this->assertSame('completed',$record->fresh()->status);Http::assertSentCount(2);
        $sent=Http::recorded();$this->assertSame($sent[0][0]->body(),$sent[1][0]->body());$record->refresh();$attempts=$record->provider_attempts;$attempts[0]['requestedModelId']='another-model';$record->update(['provider_attempts'=>$attempts]);
        $this->expectException(HttpException::class);$service->completed($item,$record->id);
    }
    public function test_four_analyses_are_closed_to_v2_and_second_followup_key_rejected(): void
    {
        [,, $item,,,$parent]=$this->fixture();$parent=$this->finish($parent);$service=app(LabLifestyleFramings::class);$service->reserveFollowup($item,$parent->id);$steps=app(LabWorkflowSteps::class);
        $steps->reserve($item,'lifestyle-section-plan','analysis',[]);$run=(string)Str::uuid();$steps->reserve($item,'placement-'.$run,'analysis',['protocolVersion'=>LabLifestyleAssessmentPolicy::VERSION,'candidateRunId'=>$run]);
        $this->assertSame(4,IguideLabWorkflowStep::where('kind','analysis')->count());$this->expectException(HttpException::class);$steps->reserve($item,'lifestyle-optical-followup-2','analysis',[]);
    }
    public function test_disabled_flag_or_changed_snapshot_rejects_queued_authority(): void
    {
        [,,,,$step,,$record]=$this->followup();config(['iguide-lab.lifestyle_optical_details_enabled'=>false]);Queue::fake();
        $this->expectException(HttpException::class);app(LabLifestyleFramings::class)->start($step);
    }
    public function test_foreign_parent_and_native_drift_are_rejected_before_followup_submission(): void
    {
        [,, $item,$case,,$parent,$record]=$this->followup();$foreign=$item->replicate();$foreign->position=1;$foreign->save();
        try{app(LabLifestyleFramings::class)->reserveFollowup($foreign,$parent->id);$this->fail('Foreign item reused a parent.');}
        catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){$this->assertNotEmpty($e->getMessage());}
        Http::fake();Storage::disk(LabService::DISK)->put($case->source_path,'source drift');app(LabLifestyleFramings::class)->process($record);
        Http::assertNothingSent();$this->assertSame('needs_attention',$record->fresh()->status);
    }
    public function test_reserved_policy_drift_is_not_adopted_on_resume(): void
    {
        [,$workflow,,,,$parent,$record]=$this->followup();$policy=$workflow->policy;$policy['lifestyleOpticalDetails']['maxFollowups']=2;$workflow->update(['policy'=>$policy]);Http::fake();
        app(LabLifestyleFramings::class)->process($record);Http::assertNothingSent();$this->assertSame('needs_attention',$record->fresh()->status);
    }
}
class OpticalOriginalContextFixture extends LabLifestyleFramingContext
{public array $bundle=[];public function capture(IguideLabWorkflowItem $item): array{return $this->bundle;}}
class OpticalDetailFixture extends LabLifestyleOpticalDetails
{
    protected function extract(IguideLabWorkflowItem $item,IguideLabLifestyleFraming $parent,array $result,$native,array $policy): array
    {
        $im=imagecreatetruecolor(2048,2048);imagefill($im,0,0,imagecolorallocate($im,100,120,140));ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);
        $sha=hash('sha256',$bytes);$path='iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/fixture-detail-'.$parent->id.'.png';app(LabLifestyleFramingContext::class)->put($path,$bytes);
        return ['views'=>[['id'=>'source_detail_1','sha256'=>$sha,'width'=>2048,'height'=>2048,'nativeSourceSha256'=>$native->source_sha256,'pose'=>$result['detailRequests'][0]['pose']]],
            'assets'=>[['id'=>'source_detail_1','path'=>$path,'sha256'=>$sha]],'manifestSha256'=>str_repeat('e',64)];
    }
}
