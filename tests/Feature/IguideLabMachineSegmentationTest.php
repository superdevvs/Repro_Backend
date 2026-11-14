<?php

namespace Tests\Feature;

use App\Contracts\LabMachineSegmentationAuthority;
use App\Models\{IguideLabCase,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep,IguideLabMachineSegmentation};
use App\Services\IguideLab\{LabService,LabStorage,LabImages,LabMasks,LabWorkflowSources,LabWorkflowSteps,LabMachineSegmentations,LabMachineSegmentationPlan,LabMachineSegmentationTransport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic persisted assessment adapter only; no real assessment or quality success. */
class IguideLabMachineSegmentationTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabWorkflow $workflow;
    private IguideLabWorkflowItem $item;
    private IguideLabCase $cut;
    private IguideLabRun $run;
    private IguideLabSection $section;
    private array $plans=[];

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests(); config(['services.fal.key'=>'test-only-placeholder']);
        $disk=Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK)); $disk->makeDirectory(''); $disk->setVisibility('','private');
        $this->project=IguideLabProject::create(['name'=>'Machine segmentation tests','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        app(LabStorage::class)->prepareProject($this->project->id);
        $native=$this->case($this->png(1024)); $this->cut=$this->case($this->png(2048));
        $this->section=IguideLabSection::create(['project_id'=>$this->project->id,'case_id'=>$native->id,'derived_case_id'=>$this->cut->id,
            'request_id'=>(string)Str::uuid(),'request_hash'=>hash('sha256','section'),'source_sha256'=>$native->source_sha256,'status'=>'completed','view'=>['yaw'=>0.0,'pitch'=>-25.0,'hFov'=>110.0]]);
        app(LabStorage::class)->prepareGeometry($this->project->id,'sections',$this->section->id);
        $path='iguide-lab/'.$this->project->id.'/sections/'.$this->section->id.'/geometry.json'; $disk->put($path,'synthetic geometry',['visibility'=>'private']);
        $this->section->update(['data'=>['cutSha256'=>$this->cut->source_sha256,'geometryPath'=>$path,'geometrySha256'=>hash('sha256','synthetic geometry'),
            'adapterSha256'=>hash_file('sha256',resource_path('iguide-lab/section_geometry.py')),'geometryCodeSha256'=>hash_file('sha256',resource_path('iguide-lab/pano_geometry.py'))]]);
        $snapshot=app(LabMasks::class)->sectionSnapshot($this->project->id,$this->section->id,$this->cut,null);
        $this->workflow=IguideLabWorkflow::create(['project_id'=>$this->project->id,'request_id'=>(string)Str::uuid(),'request_hash'=>hash('sha256','workflow'),'status'=>'editing','policy'=>['version'=>'synthetic-test']]);
        $this->item=IguideLabWorkflowItem::create(['project_id'=>$this->project->id,'workflow_id'=>$this->workflow->id,'case_id'=>$native->id,'position'=>0,'preset'=>'staging','instructions'=>'Synthetic','source'=>app(LabWorkflowSources::class)->capture($native),'status'=>'editing','data'=>[]]);
        $output=$this->png(2048); $id=(string)Str::uuid(); $outputPath='iguide-lab/'.$this->project->id.'/runs/'.$id.'.png'; $disk->put($outputPath,$output,['visibility'=>'private']);
        $source=$disk->get($this->cut->source_path);
        $this->run=IguideLabRun::create(['id'=>$id,'project_id'=>$this->project->id,'case_id'=>$this->cut->id,'request_id'=>(string)Str::uuid(),'request_hash'=>hash('sha256','run'),
            'model_id'=>'gpt-image-2.6-sunburst','provider'=>'openai','preset'=>'staging','prompt'=>'Synthetic','status'=>'completed','output_path'=>$outputPath,
            'output_sha256'=>hash('sha256',$output),'output_width'=>2048,'output_height'=>2048,
            'options'=>['sourceSection'=>$snapshot,'preparedInputSha256'=>[hash('sha256',LabImages::prepared($source))]]]);
        $lookup=fn($step)=>$this->plans[$step->id];
        app()->instance(LabMachineSegmentationAuthority::class,new class($lookup) implements LabMachineSegmentationAuthority {
            public function __construct(private \Closure $lookup) {}
            public function plan(IguideLabWorkflowStep $step): array { return ($this->lookup)($step); }
            public function associate(IguideLabWorkflowStep $step,array $receipt): array { return ['status'=>'ready_for_support_validation','associations'=>[],'synthetic'=>true]; }
        });
        app()->instance(LabMachineSegmentationTransport::class,new class extends LabMachineSegmentationTransport { protected function addresses(string $host): array { return ['8.8.8.8']; } });
    }

    public function test_durable_receipt_replay_and_get_only_completion_submit_once(): void
    {
        $step=$this->step('source-floor'); $service=app(LabMachineSegmentations::class); $record=$service->start($step);
        Http::fake(['https://queue.fal.run/fal-ai/sam-3-1/image'=>Http::response(['request_id'=>'saved-receipt'],200),
            'https://queue.fal.run/fal-ai/sam-3-1/requests/saved-receipt/status'=>Http::sequence()->push(['status'=>'IN_PROGRESS'])->push(['status'=>'COMPLETED']),
            'https://queue.fal.run/fal-ai/sam-3-1/requests/saved-receipt/response'=>Http::response(['masks'=>[['url'=>'https://v3b.fal.media/mask.png']]],200),
            'https://v3b.fal.media/mask.png'=>Http::response($this->png(2048),200)]);
        $this->assertFalse($service->process($record)); $this->assertSame('saved-receipt',$record->fresh()->provider_request_id);
        $this->assertTrue($service->process($record->fresh())); $this->assertTrue($service->process($record->fresh()));
        $this->assertSame('completed',$record->fresh()->status); $this->assertFalse($record->fresh()->result['humanReviewed']);
        $this->assertSame($record->id,$service->start($step->fresh())->id); $this->assertSame(1,IguideLabMachineSegmentation::count());
        $this->assertSame(1,Http::recorded()->filter(fn($pair)=>$pair[0]->method()==='POST')->count());
        $this->assertSame(0,$this->project->fresh()->used_runs);
    }

    public function test_ambiguous_paid_submit_never_repeats_on_worker_resume(): void
    {
        $service=app(LabMachineSegmentations::class); $record=$service->start($this->step('source-floor'));
        Http::fake(['queue.fal.run/*'=>Http::failedConnection()]); $service->process($record); $service->process($record->fresh());
        $this->assertSame('needs_attention',$record->fresh()->status); $this->assertSame('unknown',$record->fresh()->provider_attempts[0]['status']); Http::assertSentCount(1);
    }

    public function test_cancelled_workflow_blocks_paid_claim_after_reservation(): void
    {
        $service=app(LabMachineSegmentations::class); $record=$service->start($this->step('source-floor'));
        $this->workflow->update(['cancelled_at'=>now(),'status'=>'cancelled']); $service->process($record);
        $this->assertSame('needs_attention',$record->fresh()->status); $this->assertNull($record->fresh()->provider_attempts); Http::assertNothingSent();
    }

    public function test_changed_candidate_bytes_block_floor_spend_and_missing_floor_blocks_furniture(): void
    {
        $service=app(LabMachineSegmentations::class);
        try { $service->start($this->step('candidate-furniture')); $this->fail('Furniture started without floor support.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        $record=$service->start($this->step('source-floor')); Storage::disk(LabService::DISK)->put($this->run->output_path,'changed'); $service->process($record);
        $this->assertSame('needs_attention',$record->fresh()->status); Http::assertNothingSent();
    }

    public function test_missing_typed_assessment_binding_stops_before_any_record_or_call(): void
    {
        app()->offsetUnset(LabMachineSegmentationAuthority::class);
        try { app(LabMachineSegmentations::class)->start($this->step('source-floor')); $this->fail('Missing authority passed.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(503,$e->getStatusCode()); }
        $this->assertSame(0,IguideLabMachineSegmentation::count()); Http::assertNothingSent();
    }

    public function test_mutated_reserved_input_stops_before_paid_claim(): void
    {
        $service=app(LabMachineSegmentations::class); $step=$this->step('source-floor'); $record=$service->start($step);
        $step->update(['input'=>$step->input+['unreservedChange'=>'changed after reservation']]); $service->process($record);
        $this->assertSame('needs_attention',$record->fresh()->status); $this->assertNull($record->fresh()->provider_attempts); Http::assertNothingSent();
    }

    public function test_empty_model_result_is_retained_as_needs_review_without_furniture_spend(): void
    {
        $service=app(LabMachineSegmentations::class); $record=$service->start($this->step('source-floor'));
        Http::fakeSequence()->push(['request_id'=>'empty-receipt'])->push(['status'=>'COMPLETED'])->push(['masks'=>[]]);
        $service->process($record); $this->assertSame('needs_review',$record->fresh()->status);
        $this->assertSame([],$record->fresh()->result['receipt']['masks']); $this->assertSame('empty-receipt',$record->fresh()->provider_request_id);
        try { $service->start($this->step('candidate-furniture')); $this->fail('Empty floor support allowed furniture spending.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        $this->assertSame(1,Http::recorded()->filter(fn($pair)=>$pair[0]->method()==='POST')->count());
    }

    public function test_changed_floor_mask_blocks_the_second_paid_request(): void
    {
        $service=app(LabMachineSegmentations::class); $record=$service->start($this->step('source-floor'));
        Http::fakeSequence()->push(['request_id'=>'floor-receipt'])->push(['status'=>'COMPLETED'])->push(['masks'=>[['url'=>'https://v3b.fal.media/mask.png']]])->push($this->png(2048));
        $service->process($record); $this->assertSame('completed',$record->fresh()->status);
        Storage::disk(LabService::DISK)->put($record->fresh()->result['artifacts'][0]['path'],'changed');
        try { $service->start($this->step('candidate-furniture')); $this->fail('Changed floor mask allowed furniture spending.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        $this->assertSame(1,Http::recorded()->filter(fn($pair)=>$pair[0]->method()==='POST')->count());
    }

    private function step(string $key): IguideLabWorkflowStep
    {
        $step=app(LabWorkflowSteps::class)->reserve($this->item,$key,'segmentation',['protocolVersion'=>LabMachineSegmentations::PROTOCOL,'sectionId'=>$this->section->id,'candidateRunId'=>$this->run->id]);
        $plans=json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/machine-segmentation-python-plans.json')),true)['plans'];
        $plan=$plans[$key==='source-floor'?0:1]; $plan['requestId']=$step->request_id; $plan['inputSha256']=$key==='source-floor'?$this->cut->source_sha256:$this->run->output_sha256;
        unset($plan['inputFingerprint']); $plan['inputFingerprint']=LabMachineSegmentationPlan::canonicalHash($plan); $this->plans[$step->id]=$plan; return $step;
    }
    private function case(string $bytes): IguideLabCase
    {
        $id=(string)Str::uuid(); $path='iguide-lab/'.$this->project->id.'/cases/'.$id.'.png'; Storage::disk(LabService::DISK)->put($path,$bytes,['visibility'=>'private']); $size=getimagesizefromstring($bytes);
        return IguideLabCase::create(['id'=>$id,'project_id'=>$this->project->id,'label'=>'Synthetic','projection'=>$size[1]===1024?'equirectangular':'rectilinear','width'=>$size[0],'height'=>$size[1],
            'source_path'=>$path,'source_sha256'=>hash('sha256',$bytes),'source_mime'=>'image/png']);
    }
    private function png(int $height): string
    {
        $image=imagecreatetruecolor(2048,$height); imagefill($image,0,0,imagecolorallocate($image,0,0,0));
        imagefilledrectangle($image,300,300,1000,900,imagecolorallocate($image,255,255,255)); ob_start(); imagepng($image); $out=ob_get_clean(); imagedestroy($image); return $out;
    }
}
