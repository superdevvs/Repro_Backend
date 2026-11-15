<?php
namespace Tests\Feature;
use App\Models\{IguideLabProject,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabRoomAssessment,IguideLabMachineSegmentation};
use App\Services\IguideLab\{LabService,LabWorkflowSources,LabWorkflowSteps,LabMachineSegmentations,LabMachineSegmentationTransport,LabRoomFurnishingMasks,LabInteriorRoomRoutes,LabInteriorRoomAssessments,LabRoomFloorWitness as W,LabInteriorRoomProfiles as R};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage,Http,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic typed assessment fixture; real mask capability, files, transport and durable claim. */
class IguideLabRoomFloorWitnessLifecycleTest extends TestCase
{
    use RefreshDatabase;
    private function png(int $height,bool $floor=false): string
    {$im=imagecreatetruecolor(2048,$height);if($floor)imagefilledrectangle($im,0,1000,2047,2047,0xffffff);else imagefilledrectangle($im,0,0,2047,$height-1,0x707070);ob_start();imagepng($im);$b=ob_get_clean();imagedestroy($im);return $b;}
    private function fixture(): array
    {
        Queue::fake();Http::preventStrayRequests();config(['services.fal.key'=>'test-only','iguide-lab.interior_room_routing_enabled'=>true,'iguide-lab.interior_floor_witness_enabled'=>true]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $project=IguideLabProject::create(['name'=>'Floor witness synthetic lifecycle','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>10]);
        $native=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('source.png',$this->png(1024)),['label'=>'Synthetic native','projection'=>'equirectangular']);
        $cut=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('cut.png',$this->png(2048)),['label'=>'Synthetic cut','projection'=>'rectilinear']);
        $workflow=IguideLabWorkflow::create(['project_id'=>$project->id,'request_id'=>(string)Str::uuid(),'request_hash'=>hash('sha256','workflow'),'status'=>'editing','policy'=>['version'=>'synthetic']]);
        $item=IguideLabWorkflowItem::create(['project_id'=>$project->id,'workflow_id'=>$workflow->id,'case_id'=>$native->id,'position'=>0,'preset'=>'interior-design','style'=>'boho','instructions'=>'','source'=>app(LabWorkflowSources::class)->capture($native),'status'=>'editing','data'=>[]]);
        $parent=app(LabWorkflowSteps::class)->reserve($item,'room-layout','analysis',['fixture'=>'typed parent already completed']);
        $value=['status'=>'ready_for_image','sourceFloor'=>['status'=>'opaque_matte','prompt'=>W::LABEL,'box'=>[350,1390,1820,1840]]];
        $assessment=IguideLabRoomAssessment::create(['project_id'=>$project->id,'step_id'=>$parent->id,'request_id'=>$parent->request_id,'request_hash'=>$parent->input_sha256,'phase'=>'layout','status'=>'completed',
            'result'=>$value,'result_sha256'=>hash('sha256',json_encode($value)),'data'=>['recipe'=>['implementation'=>['workflow'=>['floorContract'=>W::snapshot()]]],
                'contextSnapshot'=>['cutCaseId'=>$cut->id,'context'=>['nativeSourceSha256'=>$native->source_sha256,'sourceSha256'=>$cut->source_sha256]]]]);
        $assess=\Mockery::mock(LabInteriorRoomAssessments::class)->makePartial();$assess->shouldReceive('completed')->andReturnUsing(fn()=> $assessment->fresh());$this->app->instance(LabInteriorRoomAssessments::class,$assess);
        $routes=\Mockery::mock(LabInteriorRoomRoutes::class)->makePartial();$routes->shouldReceive('verify')->andReturn(['profileId'=>R::LIVING]);$this->app->instance(LabInteriorRoomRoutes::class,$routes);
        $this->app->instance(LabMachineSegmentationTransport::class,new class extends LabMachineSegmentationTransport {protected function addresses(string $host): array{return ['8.8.8.8'];}});
        $step=app(LabRoomFurnishingMasks::class)->reserve($item,$assessment);$record=app(LabMachineSegmentations::class)->start($step);
        return [$project,$workflow,$item,$native,$assessment,$step,$record];
    }
    public function test_text_only_durable_receipt_and_raw_pixels_replay_without_new_submission(): void
    {
        [$project,,$item,$native,$assessment,$step,$record]=$this->fixture();$mask=$this->png(2048,true);
        Http::fake(['https://queue.fal.run/fal-ai/sam-3-1/image'=>Http::response(['request_id'=>'floor-once'],200),
            'https://queue.fal.run/fal-ai/sam-3-1/requests/floor-once/status'=>Http::response(['status'=>'COMPLETED'],200),
            'https://queue.fal.run/fal-ai/sam-3-1/requests/floor-once/response'=>Http::response(['masks'=>[['url'=>'https://v3b.fal.media/floor.png']]],200),
            'https://v3b.fal.media/floor.png'=>Http::response($mask,200)]);
        $service=app(LabMachineSegmentations::class);$this->assertTrue($service->process($record));$record->refresh();
        $this->assertSame('completed',$record->status,(string)$record->error);$this->assertSame(W::VERSION,$step->input['protocolVersion']);
        $posts=Http::recorded()->filter(fn($pair)=>$pair[0]->method()==='POST');$this->assertCount(1,$posts);$body=json_decode($posts->first()[0]->body(),true);
        $this->assertSame([],$body['box_prompts']);$this->assertSame('floor',$body['prompt']);$this->assertSame(1,$body['max_masks']);
        $verified=app(LabRoomFurnishingMasks::class)->verifiedReceipt($step->fresh());$this->assertSame(hash('sha256',$mask),$verified['association']['floorWitness']['rawMaskSha256']);
        $this->assertSame($native->source_sha256,$verified['association']['floorWitness']['nativeSourceSha256']);
        $service->process($record);$this->assertSame($record->id,$service->start($step->fresh())->id);$this->assertSame(1,IguideLabMachineSegmentation::count());
        $this->assertSame(0,$project->fresh()->used_runs);$this->assertCount(1,Http::recorded()->filter(fn($pair)=>$pair[0]->method()==='POST'));
        $disk=Storage::disk(LabService::DISK);$base='iguide-lab/'.$project->id.'/machine-segmentations/'.$record->id;
        foreach([$base.'/provider-result.json',$base.'/mask-0.png',$native->source_path] as $path){$raw=$disk->get($path);$disk->put($path,$raw.'drift');$this->rejects(fn()=>app(LabRoomFurnishingMasks::class)->verifiedReceipt($step->fresh()));$disk->put($path,$raw);}
        $data=$assessment->data;$bad=$data;unset($bad['recipe']['implementation']['workflow']['floorContract']);$assessment->update(['data'=>$bad]);
        $this->rejects(fn()=>app(LabRoomFurnishingMasks::class)->plan($step->fresh()));
    }
    public function test_cancelled_or_disabled_reserved_v2_does_not_submit(): void
    {
        [,$workflow,,,,$step,$record]=$this->fixture();$workflow->update(['cancelled_at'=>now()]);app(LabMachineSegmentations::class)->process($record);
        $this->assertSame('needs_attention',$record->fresh()->status);Http::assertNothingSent();
        $workflow->update(['cancelled_at'=>null]);config(['iguide-lab.interior_floor_witness_enabled'=>false]);$this->rejects(fn()=>app(LabRoomFurnishingMasks::class)->plan($step->fresh()));Http::assertNothingSent();
    }
    private function rejects(callable $f): void
    {try{$f();$this->fail('Changed authority accepted.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException|\InvalidArgumentException $e){$this->assertTrue(true);}}
}
