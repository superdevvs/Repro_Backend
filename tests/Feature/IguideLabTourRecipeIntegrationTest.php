<?php
namespace Tests\Feature;

use App\Models\{IguideLabCase,IguideLabInteriorAssessment,IguideLabProject,IguideLabSection,IguideLabWorkflow,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Services\IguideLab\{LabAnalysisRecipes,LabDeclutterRemovalPolicy,LabGrassAssessmentPolicy,LabGrassSourceAdmissionPolicy,LabInteriorDesignAssessments,LabInteriorDesignPolicy,LabLifestyleAssessmentPolicy,LabSectionGeometry,LabSections,LabService,LabStagingAssessmentPolicy,LabStagingLayouts,LabWorkflowCapacity,LabWorkflowRecordCapacity,LabWorkflowScheduler,LabWorkflowSources,LabWorkflowSteps};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Scheduler integration tests use synthetic records, never learned or manually accepted scene authority. */
class IguideLabTourRecipeIntegrationTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabCase $source;
    protected function setUp():void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();$this->travelTo(now()->startOfSecond());
        config(['iguide-lab.tour_scheduler_enabled'=>true]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->project=IguideLabProject::create(['name'=>'Recipe budget integration','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDays(10),'max_runs'=>400]);
        $png=(string)\Intervention\Image\ImageManager::gd()->create(2048,1024)->fill('888888')->toPng();
        $this->source=app(LabService::class)->addCase($this->project,UploadedFile::fake()->createWithContent('original.png',$png),['label'=>'Original','projection'=>'equirectangular']);
    }
    protected function tearDown():void {$this->travelBack();parent::tearDown();}
    private function workflow(string $preset,array $policy,bool $scheduled=true):array
    {
        if($scheduled)$policy['scheduler']=app(LabWorkflowScheduler::class)->snapshot($this->project,[['preset'=>$preset]],$policy);
        $workflow=IguideLabWorkflow::create(['project_id'=>$this->project->id,'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'policy'=>$policy,'status'=>'queued']);
        $item=IguideLabWorkflowItem::create(['project_id'=>$this->project->id,'workflow_id'=>$workflow->id,'position'=>0,'case_id'=>$this->source->id,
            'preset'=>$preset,'style'=>null,'instructions'=>'','source'=>app(LabWorkflowSources::class)->capture($this->source),'status'=>'queued','data'=>[]]);
        if($scheduled)$this->assertTrue(app(LabWorkflowScheduler::class)->admit($workflow,$item,$this->project));
        return [$workflow,$item];
    }
    public static function budgets():array
    {
        // Exact released fe58 budgets: analysis, section, image, segmentation, composition, evaluation.
        return [
            'legacy-lighting'=>['twilight',[],[2,8,3,6,8,8]],
            'repair-lighting'=>['golden-hour',['lightingRepair'=>['version'=>'fixture']],[2,8,3,6,5,5]],
            'legacy-staging'=>['staging',['stagingAssessment'=>LabStagingAssessmentPolicy::snapshot()],[3,8,1,2,8,1]],
            'layout-staging'=>['staging',['stagingAssessment'=>LabStagingAssessmentPolicy::snapshot(),'stagingLayout'=>['version'=>LabStagingLayouts::VERSION]],[4,2,1,2,1,1]],
            'lifestyle'=>['lifestyle',['lifestyleAssessment'=>LabLifestyleAssessmentPolicy::snapshot()],[3,1,1,2,1,1]],
            'lifestyle-optical'=>['lifestyle',['lifestyleAssessment'=>LabLifestyleAssessmentPolicy::snapshot(),'lifestyleOpticalDetails'=>['version'=>'fixture']],[4,1,1,2,1,1]],
            'legacy-grass'=>['grass',['grassAssessment'=>LabGrassAssessmentPolicy::snapshot()],[2,8,1,6,8,1]],
            'admission-grass'=>['grass',['grassAssessment'=>LabGrassAssessmentPolicy::snapshot(),'grassSourceAdmission'=>LabGrassSourceAdmissionPolicy::snapshot()],[4,8,1,6,8,1]],
            'legacy-declutter'=>['declutter',['declutterRemoval'=>LabDeclutterRemovalPolicy::snapshot()],[2,1,1,2,1,1]],
            'materials-declutter'=>['declutter',['declutterRemoval'=>LabDeclutterRemovalPolicy::snapshot(true)],[2,1,1,2,1,1]],
            'interior'=>['interior-design',['interiorDesign'=>LabInteriorDesignPolicy::snapshot()],[3,1,1,15,1,1]],
            'interior-room'=>['interior-design',['interiorDesign'=>LabInteriorDesignPolicy::snapshot(),'interiorRoomRouting'=>\App\Services\IguideLab\LabInteriorRoomPolicy::snapshot()],[5,2,1,15,1,1]],
        ];
    }
    #[DataProvider('budgets')]
    public function test_frozen_recipe_budgets_and_eighty_item_allowances_remain_exact(string $preset,array $policy,array $expected):void
    {
        $expected=array_combine(['analysis','section','image','segmentation','composition','evaluation'],$expected);
        $this->assertSame($expected,app(LabWorkflowSteps::class)->limits($policy,$preset));
        $snapshot=app(LabWorkflowScheduler::class)->snapshot($this->project,array_fill(0,80,['preset'=>$preset]),$policy);
        $this->assertSame(array_map(fn($n)=>80*$n,$expected),$snapshot['recordAllowances']);
        $this->assertSame(80,$snapshot['itemCount']);Http::assertNothingSent();
    }
    public function test_interior_reservations_use_exact_limits_and_replay_without_extra_allowance():void
    {
        [$workflow,$item]=$this->workflow('interior-design',['interiorDesign'=>LabInteriorDesignPolicy::snapshot()]);$steps=app(LabWorkflowSteps::class);
        foreach(['analysis'=>3,'section'=>1,'image'=>1,'segmentation'=>15,'composition'=>1,'evaluation'=>1] as $kind=>$limit){
            for($i=1;$i<=$limit;$i++)$last=$steps->reserve($item,$kind.'-'.$i,$kind,['ordinal'=>$i]);
            $this->assertSame($last->request_id,$steps->reserve($item,$kind.'-'.$limit,$kind,['ordinal'=>$limit])->request_id);
            try{$steps->reserve($item,$kind.'-overflow',$kind,[]);$this->fail('Recipe budget increased.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }
        $this->assertSame(22,IguideLabWorkflowStep::count());
        $this->assertSame(3,app(LabWorkflowScheduler::class)->policy($workflow)['recordAllowances']['analysis']);
        Http::assertNothingSent();
    }
    public function test_layout_reframe_extraction_carries_owned_invocation_and_exact_replay():void
    {
        [$workflow,$item]=$this->workflow('staging',['stagingAssessment'=>LabStagingAssessmentPolicy::snapshot(),'stagingLayout'=>['version'=>LabStagingLayouts::VERSION]]);
        $this->mock(LabSectionGeometry::class)->shouldReceive('ready')->andReturn(true);
        $method=new \ReflectionMethod(LabStagingLayouts::class,'section');$service=app(LabStagingLayouts::class);
        foreach(['staging-section'=>0,'staging-reframed-section'=>20] as $key=>$yaw){
            $view=['yaw'=>$yaw,'pitch'=>-20,'hFov'=>100];
            $section=$method->invoke($service,$item,$this->project,$this->source,$key,$view);
            $step=IguideLabWorkflowStep::where('step_key',$key)->sole();
            $this->assertSame(app(LabWorkflowSteps::class)->invocation($step),$section->data['workflowInvocation']);
            $this->assertSame($section->id,$method->invoke($service,$item,$this->project,$this->source,$key,$view)->id);
        }
        $this->assertSame(2,IguideLabSection::count());Queue::assertPushed(\App\Jobs\ProcessIguideLabSection::class,2);
        try{$method->invoke($service,$item,$this->project,$this->source,'third-section',['yaw'=>0,'pitch'=>-20,'hFov'=>100]);$this->fail();}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $workflow->update(['cancelled_at'=>now()]);
        $this->expectException(HttpException::class);$method->invoke($service,$item,$this->project,$this->source,'staging-section',['yaw'=>0,'pitch'=>-20,'hFov'=>100]);
    }
    public function test_interior_source_section_requires_exact_reserved_framing_input():void
    {
        [, $item]=$this->workflow('interior-design',['interiorDesign'=>LabInteriorDesignPolicy::snapshot()]);$steps=app(LabWorkflowSteps::class);
        $input=['caseId'=>$this->source->id,'view'=>['yaw'=>0,'pitch'=>-10,'hFov'=>100],'framingId'=>(string)Str::uuid()];
        $step=$steps->reserve($item,'interior-section','section',$input);$invocation=$steps->invocation($step);
        $this->mock(LabSectionGeometry::class)->shouldReceive('ready')->andReturn(true);
        $section=app(LabSections::class)->start($this->project,$input+['requestId'=>$step->request_id],$invocation);
        $this->assertSame($invocation,$section->data['workflowInvocation']);
        $changed=$input;$changed['framingId']=(string)Str::uuid();
        $this->expectException(HttpException::class);app(LabSections::class)->start($this->project,$changed+['requestId'=>$step->request_id],$invocation);
    }
    private function interiorHistory(int $count,bool $recent):void
    {
        [, $item]=$this->workflow('interior-design',[],false);$date=$recent?now():now()->subDays(2);$steps=[];$records=[];
        for($i=0;$i<$count;$i++){$id=(string)Str::uuid();$request=(string)Str::uuid();
            $steps[]=['id'=>$id,'project_id'=>$this->project->id,'item_id'=>$item->id,'step_key'=>'historical-'.$i,'kind'=>'analysis','request_id'=>$request,'input'=>'{}','input_sha256'=>str_repeat('b',64),'status'=>'completed','created_at'=>$date,'updated_at'=>$date];
            $records[]=['id'=>(string)Str::uuid(),'project_id'=>$this->project->id,'step_id'=>$id,'request_id'=>$request,'request_hash'=>str_repeat('b',64),'phase'=>'framing','status'=>'needs_attention','data'=>'{}','created_at'=>$date,'updated_at'=>$date];}
        IguideLabWorkflowStep::insert($steps);IguideLabInteriorAssessment::insert($records);
    }
    private function interiorStart():array
    {
        [$workflow,$item]=$this->workflow('interior-design',['interiorDesign'=>LabInteriorDesignPolicy::snapshot()]);
        $step=app(LabWorkflowSteps::class)->reserve($item,'interior-framing','analysis',['phase'=>'framing','contextSnapshot'=>[],'recipe'=>[]]);
        // Synthetic context avoids providers; actual locked reservation/capacity/replay remain real.
        $service=\Mockery::mock(LabInteriorDesignAssessments::class)->makePartial();
        $service->shouldReceive('verifyStep')->andReturn($item);$service->shouldReceive('payload')->andReturn('{}');
        return [$workflow,$item,$step,$service];
    }
    public function test_interior_legacy_ceiling_stays_240_but_owned_tour_excludes_old_history():void
    {
        $this->interiorHistory(239,false);$capacity=app(LabWorkflowRecordCapacity::class);
        $capacity->assertRoom(IguideLabInteriorAssessment::class,$this->project->id,null,240,240);
        $this->interiorHistory(1,false);
        try{$capacity->assertRoom(IguideLabInteriorAssessment::class,$this->project->id,null,240,240);$this->fail();}catch(HttpException $e){$this->assertSame(429,$e->getStatusCode());}
        [,, $step,$service]=$this->interiorStart();$record=$service->start($step);
        $this->assertSame(241,IguideLabInteriorAssessment::count());$this->assertSame($record->id,$service->start($step->fresh())->id);
        $this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_interior_daily_240_wait_preserves_uuid_and_unspent_reservation():void
    {
        $this->interiorHistory(240,true);[$workflow,$item,$step,$service]=$this->interiorStart();
        try{$service->start($step);$this->fail('Daily bound weakened.');}catch(LabWorkflowCapacity $hold){
            $this->assertSame('daily_analysis_capacity',$hold->reason);app(LabWorkflowScheduler::class)->hold($workflow,$item,$hold);}
        $this->assertSame(240,IguideLabInteriorAssessment::count());$this->assertNull($step->fresh()->child_id);Queue::assertNothingPushed();
        $this->travel(24)->hours();$this->travel(2)->seconds();$this->assertTrue(app(LabWorkflowScheduler::class)->admit($workflow,$item->fresh(),$this->project));
        $record=$service->start($step->fresh());$this->assertSame($step->request_id,$record->request_id);
        $this->assertSame($record->id,$service->start($step->fresh())->id);$this->assertSame(241,IguideLabInteriorAssessment::count());
        $this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }

    public function test_room_profile_allowance_and_two_mask_specialization_survive_scheduler_merge():void
    {
        $policy=['interiorDesign'=>LabInteriorDesignPolicy::snapshot(),'interiorRoomRouting'=>\App\Services\IguideLab\LabInteriorRoomPolicy::snapshot()];
        [$workflow,$item]=$this->workflow('interior-design',$policy);$steps=app(LabWorkflowSteps::class);
        $route=$steps->reserve($item,'room-route','analysis',[]);
        $item->update(['data'=>['interiorRoomRoute'=>['version'=>\App\Services\IguideLab\LabInteriorRoomProfiles::VERSION,'profileId'=>\App\Services\IguideLab\LabInteriorRoomProfiles::BEDROOM]]]);
        foreach(['room-framing','room-layout','room-candidate'] as $key)$steps->reserve($item,$key,'analysis',[]);
        foreach(['objects','floor'] as $key)$steps->reserve($item,$key,'segmentation',[]);
        $this->assertSame(5,app(LabWorkflowScheduler::class)->policy($workflow)['recordAllowances']['analysis']);
        $this->assertSame(15,app(LabWorkflowScheduler::class)->policy($workflow)['recordAllowances']['segmentation']);
        $this->assertSame($route->request_id,$steps->reserve($item,'room-route','analysis',[])->request_id);
        foreach([['third-mask','segmentation'],['interior-cut','analysis'],['unknown-room-plan','analysis']] as [$key,$kind]){
            try{$steps->reserve($item,$key,$kind,[]);$this->fail('Profile constraint weakened.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }
        $workflow->update(['cancelled_at'=>now()]);
        try{$steps->reserve($item,'room-layout','analysis',[]);$this->fail('Cancelled replay admitted.');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        Http::assertNothingSent();
    }

    public function test_actual_room_driver_passes_full_owned_section_input_and_replays_once():void
    {
        config(['iguide-lab.interior_room_routing_enabled'=>true]);
        $driver=app(\App\Services\IguideLab\LabInteriorRoomWorkflow::class);
        $policy=['interiorDesign'=>LabInteriorDesignPolicy::snapshot(),'interiorRoomRouting'=>\App\Services\IguideLab\LabInteriorRoomPolicy::snapshot(),
            'interiorRoomWorkflow'=>$driver->snapshot(),'interiorRoomSupport'=>[],'artifacts'=>[],
            'modelId'=>'gpt-image-2.6-sunburst','quality'=>'high','complexity'=>'low',
            'evaluationRecipeSha256'=>\App\Services\IguideLab\LabVisualQualityProtocol::recipeSha256(),
            'evaluationParserSha256'=>hash_file('sha256',(new \ReflectionClass(\App\Services\IguideLab\LabVisualQualityProtocol::class))->getFileName())];
        [$workflow,$item]=$this->workflow('interior-design',$policy);
        $route=['version'=>\App\Services\IguideLab\LabInteriorRoomProfiles::VERSION,'profileId'=>\App\Services\IguideLab\LabInteriorRoomProfiles::BEDROOM];
        $view=['yaw'=>15,'pitch'=>-12,'hFov'=>100];
        $records=[];
        foreach(['route','framing'] as $phase){
            $record=new \App\Models\IguideLabRoomAssessment();$record->id=(string)Str::uuid();$record->status='completed';
            $record->result=$phase==='route'?['route'=>['status'=>'route_candidate']]:['status'=>'framing_candidate','view'=>$view];
            $records[$phase]=$record;
        }
        // Typed source findings are synthetic. The actual driver, step reservation,
        // owned section API, queue dispatch and cancellation/replay guards remain real.
        $service=$this->mock(\App\Services\IguideLab\LabInteriorRoomAssessments::class);
        $service->shouldReceive('reserve')->andReturnUsing(fn($owned,$phase,$parents=[])=>app(LabWorkflowSteps::class)->reserve($owned,'room-'.$phase,'analysis',['phase'=>$phase]+$parents));
        $service->shouldReceive('start')->andReturnUsing(fn($step)=>$records[$step->input['phase']]);
        $service->shouldReceive('completed')->andReturnUsing(fn($owned,$id,$phase)=>$records[$phase]);
        $this->mock(\App\Services\IguideLab\LabInteriorRoomRoutes::class)->shouldReceive('bind')->andReturnUsing(function($owned)use($route){$owned->update(['data'=>['interiorRoomRoute'=>$route]]);return $route;});
        $this->mock(\App\Services\IguideLab\LabRoomFurnishingSupport::class)->shouldReceive('validate')->andReturnNull();
        $this->mock(\App\Services\IguideLab\LabWorkflowArtifacts::class)->shouldReceive('preflight')->andReturnNull();
        $this->mock(LabSectionGeometry::class)->shouldReceive('ready')->andReturn(true);
        $driver->advance($workflow,$item,$this->project);
        $section=IguideLabSection::sole();$step=IguideLabWorkflowStep::where('step_key','room-section')->sole();
        $this->assertSame($records['framing']->id,$step->input['framingId']);
        $this->assertSame(\App\Services\IguideLab\LabMachineSegmentationPlan::canonicalHash($route),$step->input['routeSha256']);
        $this->assertSame($view+['width'=>2048,'height'=>2048],$step->input['view']);
        $this->assertSame(app(LabWorkflowSteps::class)->invocation($step),$section->data['workflowInvocation']);
        $driver->advance($workflow,$item->fresh(),$this->project);
        $this->assertSame($section->id,IguideLabSection::sole()->id);Queue::assertPushed(\App\Jobs\ProcessIguideLabSection::class,1);
        $workflow->update(['cancelled_at'=>now()]);
        try{$driver->advance($workflow->fresh(),$item->fresh(),$this->project);$this->fail('Cancelled extraction replay admitted.');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        Http::assertNothingSent();
    }

    private function roomHistory(int $count,bool $recent):void
    {
        [, $item]=$this->workflow('interior-design',[],false);$date=$recent?now():now()->subDays(2);$steps=[];$records=[];
        for($i=0;$i<$count;$i++){$id=(string)Str::uuid();$request=(string)Str::uuid();
            $steps[]=['id'=>$id,'project_id'=>$this->project->id,'item_id'=>$item->id,'step_key'=>'historical-room-'.$i,'kind'=>'analysis','request_id'=>$request,'input'=>'{}','input_sha256'=>str_repeat('c',64),'status'=>'completed','created_at'=>$date,'updated_at'=>$date];
            $records[]=['id'=>(string)Str::uuid(),'project_id'=>$this->project->id,'step_id'=>$id,'request_id'=>$request,'request_hash'=>str_repeat('c',64),'phase'=>'route','status'=>'needs_attention','data'=>'{}','created_at'=>$date,'updated_at'=>$date];}
        IguideLabWorkflowStep::insert($steps);\App\Models\IguideLabRoomAssessment::insert($records);
    }
    private function roomStart():array
    {
        [$workflow,$item]=$this->workflow('interior-design',['interiorDesign'=>LabInteriorDesignPolicy::snapshot(),'interiorRoomRouting'=>\App\Services\IguideLab\LabInteriorRoomPolicy::snapshot()]);
        $step=app(LabWorkflowSteps::class)->reserve($item,'room-route','analysis',['phase'=>'route','contextSnapshot'=>[],'recipe'=>[]]);
        $service=\Mockery::mock(\App\Services\IguideLab\LabInteriorRoomAssessments::class)->makePartial();
        $service->shouldReceive('verifyStep')->andReturn($item);$service->shouldReceive('payload')->andReturn('{}');
        return [$workflow,$item,$step,$service];
    }
    public function test_room_records_preserve_legacy_240_but_use_owned_tour_allowance():void
    {
        $this->roomHistory(240,false);$capacity=app(LabWorkflowRecordCapacity::class);
        try{$capacity->assertRoom(\App\Models\IguideLabRoomAssessment::class,$this->project->id,null,240,240);$this->fail();}catch(HttpException $e){$this->assertSame(429,$e->getStatusCode());}
        [,, $step,$service]=$this->roomStart();$record=$service->start($step);
        $this->assertSame(241,\App\Models\IguideLabRoomAssessment::count());$this->assertSame($record->id,$service->start($step->fresh())->id);
        $this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_room_daily_240_wait_replays_exact_uuid_without_image_charge():void
    {
        $this->roomHistory(240,true);[$workflow,$item,$step,$service]=$this->roomStart();
        try{$service->start($step);$this->fail('Room daily limit weakened.');}catch(LabWorkflowCapacity $hold){
            $this->assertSame('daily_analysis_capacity',$hold->reason);app(LabWorkflowScheduler::class)->hold($workflow,$item,$hold);}
        $this->assertSame(240,\App\Models\IguideLabRoomAssessment::count());$this->assertNull($step->fresh()->child_id);Queue::assertNothingPushed();
        $this->travel(24)->hours();$this->travel(2)->seconds();$this->assertTrue(app(LabWorkflowScheduler::class)->admit($workflow,$item->fresh(),$this->project));
        $record=$service->start($step->fresh());$this->assertSame($step->request_id,$record->request_id);
        $this->assertSame($record->id,$service->start($step->fresh())->id);$this->assertSame(241,\App\Models\IguideLabRoomAssessment::count());
        $this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
}
