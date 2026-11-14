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
        // Exact c54 budgets: analysis, section, image, segmentation, composition, evaluation.
        return [
            'legacy-lighting'=>['twilight',[],[2,8,3,6,8,8]],
            'repair-lighting'=>['golden-hour',['lightingRepair'=>['version'=>'fixture']],[2,8,3,6,5,5]],
            'legacy-staging'=>['staging',['stagingAssessment'=>LabStagingAssessmentPolicy::snapshot()],[3,8,1,2,8,1]],
            'layout-staging'=>['staging',['stagingAssessment'=>LabStagingAssessmentPolicy::snapshot(),'stagingLayout'=>['version'=>LabStagingLayouts::VERSION]],[4,2,1,2,1,1]],
            'lifestyle'=>['lifestyle',['lifestyleAssessment'=>LabLifestyleAssessmentPolicy::snapshot()],[3,1,1,2,1,1]],
            'legacy-grass'=>['grass',['grassAssessment'=>LabGrassAssessmentPolicy::snapshot()],[2,8,1,6,8,1]],
            'admission-grass'=>['grass',['grassAssessment'=>LabGrassAssessmentPolicy::snapshot(),'grassSourceAdmission'=>LabGrassSourceAdmissionPolicy::snapshot()],[4,8,1,6,8,1]],
            'legacy-declutter'=>['declutter',['declutterRemoval'=>LabDeclutterRemovalPolicy::snapshot()],[2,1,1,2,1,1]],
            'materials-declutter'=>['declutter',['declutterRemoval'=>LabDeclutterRemovalPolicy::snapshot(true)],[2,1,1,2,1,1]],
            'interior'=>['interior-design',['interiorDesign'=>LabInteriorDesignPolicy::snapshot()],[3,1,1,15,1,1]],
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
}
