<?php
namespace Tests\Feature;

use App\Models\{IguideLabComposite,IguideLabProject,IguideLabRun,IguideLabWorkflow,IguideLabWorkflowItem};
use App\Services\IguideLab\{LabInteriorDesignPolicy,LabInteriorRoomPolicy,LabInteriorRoomProfiles,LabInteriorRoomWorkflow,LabService,LabStagingRoomAlias,LabStagingWorkflow,LabWorkflowPolicy,LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\IguideLabInteriorRoomRouteProtocolTest as Fixtures;

/** Virtual staging runs on the interior room engine but stays "staging" for the editor. */
class IguideLabStagingRoomAliasTest extends TestCase
{
    use RefreshDatabase;
    private array $base;
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->base=app(LabWorkflowPolicy::class)->snapshot();
    }
    private function roomPolicy(): void
    {
        $policy=$this->base+['interiorDesign'=>LabInteriorDesignPolicy::snapshot(),'interiorRoomRouting'=>LabInteriorRoomPolicy::snapshot()];
        $this->partialMock(LabWorkflowPolicy::class,fn($m)=>$m->shouldReceive('snapshot')->andReturn($policy));
    }
    private function start(string $preset='staging',int $maxRuns=20): array
    {
        $project=IguideLabProject::create(['name'=>'Staging alias','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>$maxRuns]);
        [,$images]=Fixtures::fixture();$bytes=base64_decode(explode(',',$images[0],2)[1]);
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('native.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>$preset,'style'=>'modern','instructions'=>'']]]);
        return [$project,$workflow,IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole(),$case];
    }
    public function test_staging_runs_on_the_room_engine_and_still_reports_staging_with_the_old_item_shape(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);$this->roomPolicy();
        [,$workflow,$item]=$this->start();
        $this->assertSame('interior-design',$item->preset);$this->assertSame('staging',$item->data['publicPreset']);
        $this->assertSame(LabStagingRoomAlias::snapshot(),$workflow->policy['stagingRoomAlias']);
        $dto=app(LabWorkflows::class)->dto($workflow->fresh())['items'][0];
        $this->assertSame('staging',$dto['preset']);
        $this->assertSame(['id','caseId','preset','style','status','stageLabel','attemptCount','result','reviewReasons','automation','createdAt','updatedAt'],array_keys($dto));
        $this->mock(LabStagingWorkflow::class,fn($m)=>$m->shouldNotReceive('advance'));
        $this->mock(LabInteriorRoomWorkflow::class,fn($m)=>$m->shouldReceive('advance')->once());
        app(LabWorkflows::class)->process($workflow->fresh());
    }
    public function test_a_replayed_staging_request_returns_the_same_workflow(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);$this->roomPolicy();
        [$project,$workflow,$item,$case]=$this->start();
        $again=app(LabWorkflows::class)->start($project,['requestId'=>$workflow->request_id,'items'=>[['caseId'=>$case->id,'preset'=>'staging','style'=>'modern','instructions'=>'']]]);
        $this->assertSame($workflow->id,$again->id);$this->assertSame(1,IguideLabWorkflow::count());
    }
    public function test_interior_design_requests_are_not_marked_as_staging(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);$this->roomPolicy();
        [,$workflow,$item]=$this->start('interior-design');
        $this->assertSame('interior-design',$item->preset);$this->assertArrayNotHasKey('publicPreset',$item->data);
        $this->assertSame('interior-design',app(LabWorkflows::class)->dto($workflow->fresh())['items'][0]['preset']);
    }
    public function test_switched_off_alias_keeps_the_existing_staging_route(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>false]);$this->roomPolicy();
        [,$workflow,$item]=$this->start();
        $this->assertSame('staging',$item->preset);$this->assertArrayNotHasKey('stagingRoomAlias',$workflow->policy);
    }
    public function test_alias_needs_a_ready_room_engine_captured_in_the_policy(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);
        [,$workflow,$item]=$this->start();
        $this->assertSame('staging',$item->preset);$this->assertArrayNotHasKey('stagingRoomAlias',$workflow->policy);
    }
    public function test_alias_defaults_on(): void
    {
        $this->assertTrue(config('iguide-lab-staging-room.staging_room_alias_enabled'));
    }
    public function test_capabilities_report_staging_readiness_from_the_room_engine(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);
        $caps=['ready'=>true,'recipes'=>[['preset'=>'staging','ready'=>false,'reason'=>'Required automatic checking or explicit recipe enablement is not available yet.'],
            ['preset'=>'interior-design','ready'=>true,'reason'=>null]]];
        $mirrored=LabStagingRoomAlias::capabilities($caps);
        $this->assertSame(['preset'=>'staging','ready'=>true,'reason'=>null],$mirrored['recipes'][0]);
        $this->assertSame($caps['recipes'][1],$mirrored['recipes'][1]);
        $caps['recipes'][1]['ready']=false;$caps['recipes'][1]['reason']='x';$caps['recipes'][0]['ready']=true;$caps['recipes'][0]['reason']=null;
        $this->assertSame(['preset'=>'staging','ready'=>false,'reason'=>'x'],LabStagingRoomAlias::capabilities($caps)['recipes'][0]);
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>false]);
        $this->assertSame($caps,LabStagingRoomAlias::capabilities($caps));
    }
    public function test_a_furnished_room_is_a_hard_stop_for_staging_before_any_image(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);$this->roomPolicy();
        [,$workflow,$item]=$this->start();
        $stop=LabStagingRoomAlias::routeStop($workflow->fresh(),$item,LabInteriorRoomProfiles::MATERIALS);
        $this->assertSame('staging_room_not_empty',$stop[0]);$this->assertStringContainsString('empty room',$stop[1]);
        $this->assertNull(LabStagingRoomAlias::routeStop($workflow->fresh(),$item,LabInteriorRoomProfiles::BEDROOM));
        [,$interior,$design]=$this->start('interior-design');
        $this->assertNull(LabStagingRoomAlias::routeStop($interior->fresh(),$design,LabInteriorRoomProfiles::MATERIALS));
    }
    public function test_staging_buys_one_image_and_interior_design_keeps_its_correction_ordinal(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);$this->roomPolicy();
        [,$workflow,$item]=$this->start();
        $this->assertSame(1,LabStagingRoomAlias::imageLimit($workflow->fresh(),$item,2));
        [,$interior,$design]=$this->start('interior-design');
        $this->assertSame(2,LabStagingRoomAlias::imageLimit($interior->fresh(),$design,2));
    }
    public function test_capacity_is_checked_before_the_image_is_bought(): void
    {
        config(['iguide-lab-staging-room.staging_room_alias_enabled'=>true]);$this->roomPolicy();
        [$project,$workflow,$item]=$this->start();
        $this->assertNull(LabStagingRoomAlias::capacityStop($workflow->fresh(),$item));
        $project->update(['used_runs'=>$project->max_runs]);
        $stop=LabStagingRoomAlias::capacityStop($workflow->fresh(),$item->fresh());
        $this->assertSame('generation_capacity_unavailable',$stop[0]);$this->assertStringContainsString('No image was made',$stop[1]);
        $project->update(['used_runs'=>0]);
        $this->partialMock(LabStagingRoomAlias::class,fn($m)=>$m->shouldReceive('recordCount')->andReturnUsing(fn($model)=>$model===IguideLabComposite::class?120:0));
        $this->assertSame('generation_capacity_unavailable',LabStagingRoomAlias::capacityStop($workflow->fresh(),$item->fresh())[0]);
        $this->assertSame(0,IguideLabRun::count());
    }
}
