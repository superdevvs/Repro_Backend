<?php
namespace Tests\Feature;

use App\Models\{IguideLabAnalysis,IguideLabCase,IguideLabProject,IguideLabRoomAssessment,IguideLabRun,IguideLabTourBudget,IguideLabWorkflow,IguideLabWorkflowEvaluation,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Services\IguideLab\{LabAnalyses,LabAnalysisRecipes,LabInteriorDesignPolicy,LabInteriorRoomAssessments,LabInteriorRoomPolicy,LabInteriorRoomProfiles,LabRoomEvidenceCandidateProtocol,LabRoomEvidenceDraftPolicy,LabRoomFloorWitness,LabService,LabTourProcessingBudget,LabWorkflowCapacity,LabWorkflowEvaluations,LabWorkflowInvocation,LabWorkflowPolicy,LabWorkflowRecordCapacity,LabWorkflowScheduler,LabWorkflowSteps,LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Actual locked child reservations; semantic payloads are explicit stubs, no provider or quality claim. */
class IguideLabTourProcessingBudgetTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabCase $source;
    private string $key;
    protected function setUp():void
    {
        parent::setUp();$this->travelTo(now()->startOfSecond());Queue::fake();Http::preventStrayRequests();
        $this->key=tempnam(sys_get_temp_dir(),'budget-key');file_put_contents($this->key,'synthetic-only');
        config(['iguide-lab.tour_scheduler_enabled'=>true,'iguide-lab.tour_processing_budget_enabled'=>true,
            'iguide-lab.openai_primary_key_path'=>$this->key,'services.openai.api_key'=>'synthetic-only']);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->project=IguideLabProject::create(['name'=>'Finite tour budget fixture','token_hash'=>hash('sha256',str_repeat('a',64)),
            'expires_at'=>now()->addDays(10),'max_runs'=>240]);
        $png=(string)\Intervention\Image\ImageManager::gd()->create(2048,1024)->fill('888888')->toPng();
        $this->source=app(LabService::class)->addCase($this->project,UploadedFile::fake()->createWithContent('original.png',$png),['label'=>'Original','projection'=>'equirectangular']);
    }
    protected function tearDown():void {unlink($this->key);$this->travelBack();parent::tearDown();}
    private function policy(array $p=[]):array
    {
        $policy=$p+['analysisRecipe'=>app(LabAnalysisRecipes::class)->active()];
        $this->mock(LabWorkflowPolicy::class)->shouldReceive('snapshot')->andReturn($policy);return $policy;
    }
    private function body(int $count,string $preset='twilight'):array
    {
        return ['requestId'=>(string)Str::uuid(),'items'=>array_map(fn($n)=>['caseId'=>$this->source->id,'preset'=>$preset,'instructions'=>'Synthetic capture choice '.$n],range(1,$count))];
    }
    private function grant(int $count,array $policy,string $preset='twilight'):array
    {
        $limits=app(LabWorkflowSteps::class)->limits($policy,$preset);
        return app(LabTourProcessingBudget::class)->create($this->project->id,$count,array_map(fn($n)=>$n*$count,$limits),now()->addDays(9)->timestamp);
    }
    private function expectHttp(int $status,callable $call):void
    {
        try{$call();$this->fail('Guard did not reject.');}catch(HttpException $e){$this->assertSame($status,$e->getStatusCode());}
    }
    public function test_eighty_lighting_items_cross_multiple_120_limits_through_real_locked_reservations():void
    {
        $policy=$this->policy();$grant=$this->grant(80,$policy);$body=$this->body(80);$owner=app(LabWorkflows::class);$w=$owner->start($this->project,$body);
        $this->assertSame($w->id,$owner->start($this->project,$body)->id);$this->assertSame(80,IguideLabTourBudget::sole()->reserved_items);
        $analysis=\Mockery::mock(LabAnalyses::class)->makePartial();$analysis->shouldReceive('ready')->andReturn(true);
        $analysis->shouldReceive('appliedDraft')->andReturn(['id'=>(string)Str::uuid(),'sourceSha256'=>$this->source->source_sha256]);$this->app->instance(LabAnalyses::class,$analysis);
        $qa=\Mockery::mock(LabWorkflowEvaluations::class)->makePartial();$qa->shouldReceive('ready')->andReturn(true);$qa->shouldReceive('payload')->andReturn(['model'=>'gpt-6-astra','synthetic'=>'quality payload only']);
        $steps=app(LabWorkflowSteps::class);$scheduler=app(LabWorkflowScheduler::class);$replays=[];
        foreach(IguideLabWorkflowItem::where('workflow_id',$w->id)->orderBy('position')->get() as $item){
            $this->assertTrue($scheduler->admit($w,$item,$this->project));
            for($ordinal=1;$ordinal<=2;$ordinal++){
                $input=['caseId'=>$this->source->id,'preset'=>'twilight','instructions'=>'Plan '.$ordinal,'options'=>LabAnalyses::EDIT_DEFAULTS];
                $step=$steps->reserve($item,'analysis-'.$ordinal,'analysis',$input);$record=$analysis->start($this->project,$input+['requestId'=>$step->request_id],$steps->invocation($step));
                $this->assertSame($record->id,$analysis->start($this->project,$input+['requestId'=>$step->request_id],$steps->invocation($step))->id);
                $imageInput=['caseId'=>$this->source->id,'modelId'=>'gpt-image-2.6-sunburst','preset'=>'twilight','prompt'=>'Synthetic authority fixture',
                    'options'=>['analysisId'=>$record->id,'promptMode'=>'scene-plan']];
                $image=$steps->reserve($item,'image-'.$ordinal,'image',$imageInput);$run=app(LabService::class)->start($this->project,$imageInput+['requestId'=>$image->request_id],$steps->invocation($image));
                $this->assertSame($run->id,app(LabService::class)->start($this->project,$imageInput+['requestId'=>$image->request_id],$steps->invocation($image))->id);
                $eval=$steps->reserve($item,'evaluation-'.$ordinal,'evaluation',['fixtureOrdinal'=>$ordinal]);$evaluation=$qa->start($eval);
                $this->assertSame($evaluation->id,$qa->start($eval)->id);
            }
            $item->update(['status'=>'needs_review','completed_at'=>now()]); // No provider executed, no Ready claim.
        }
        $this->assertSame(160,IguideLabAnalysis::count());$this->assertSame(160,IguideLabRun::count());$this->assertSame(160,IguideLabWorkflowEvaluation::count());
        $this->assertSame(160,$this->project->fresh()->used_runs);$this->assertSame(240,$this->project->fresh()->max_runs);
        $this->assertSame(0,IguideLabWorkflowItem::whereNotNull('scheduler->waitingSince')->count());
        $this->expectHttp(429,fn()=>app(LabWorkflowRecordCapacity::class)->assertRoom(IguideLabAnalysis::class,$this->project->id,null));
        $this->expectHttp(429,fn()=>app(LabWorkflowRecordCapacity::class)->assertRoom(IguideLabWorkflowEvaluation::class,$this->project->id,null));
        $this->expectHttp(429,fn()=>$scheduler->assertImageCapacity($this->project->fresh()));
        $this->expectHttp(429,fn()=>$owner->start($this->project,$this->body(1)));$this->assertSame(1,IguideLabWorkflow::count());
        $owner->cancel($w);$this->assertSame(80,IguideLabTourBudget::sole()->reserved_items);Http::assertNothingSent();
    }
    public function test_eighty_furnishing_items_reserve_all_400_room_assessments_without_240_wait():void
    {
        config(['iguide-lab.interior_floor_witness_enabled'=>true,'iguide-lab.interior_room_evidence_drafts_enabled'=>true]);
        $policy=$this->policy(['interiorDesign'=>LabInteriorDesignPolicy::snapshot(),'interiorRoomRouting'=>LabInteriorRoomPolicy::snapshot(),
            'interiorRoomAssessments'=>['implementation'=>['workflow'=>['floorContract'=>LabRoomFloorWitness::snapshot(),'evidenceDrafts'=>LabRoomEvidenceDraftPolicy::snapshot()]]]]);
        $this->grant(80,$policy,'interior-design');$w=app(LabWorkflows::class)->start($this->project,$this->body(80,'interior-design'));
        $service=\Mockery::mock(LabInteriorRoomAssessments::class)->makePartial();$service->shouldReceive('verifyStep')->andReturnUsing(fn($step)=>IguideLabWorkflowItem::findOrFail($step->item_id));$service->shouldReceive('payload')->andReturn('{}');
        $steps=app(LabWorkflowSteps::class);
        foreach(IguideLabWorkflowItem::where('workflow_id',$w->id)->orderBy('position')->get() as $item){
            $this->assertTrue(app(LabWorkflowScheduler::class)->admit($w,$item,$this->project));
            foreach(['route','framing','layout','candidate','candidate-2'] as $phase){
                if($phase==='framing')$item->update(['data'=>['interiorRoomRoute'=>['version'=>LabRoomEvidenceDraftPolicy::ROUTE_VERSION,'profileId'=>LabInteriorRoomProfiles::BEDROOM]]]);
                $input=['phase'=>$phase==='candidate-2'?'candidate':$phase,'contextSnapshot'=>[],'recipe'=>[]];
                if($phase==='candidate-2')$input+=['parents'=>['imageOrdinal'=>2],'protocolVersion'=>LabRoomEvidenceCandidateProtocol::VERSION];
                $step=$steps->reserve($item,'room-'.$phase,'analysis',$input);$record=$service->start($step);
                $this->assertSame($record->id,$service->start($step->fresh())->id);
            }
            $this->expectHttp(422,fn()=>$steps->reserve($item,'room-candidate-3','analysis',[]));
            $item->update(['status'=>'needs_review','completed_at'=>now()]);
        }
        $this->assertSame(400,IguideLabRoomAssessment::count());$this->assertSame(0,$this->project->fresh()->used_runs);
        $this->expectHttp(429,fn()=>app(LabWorkflowRecordCapacity::class)->assertRoom(IguideLabRoomAssessment::class,$this->project->id,null,240,240));
        $this->assertSame(400,IguideLabTourBudget::sole()->reserved['analysis']);Http::assertNothingSent();
    }
    public function test_explicit_budget_admission_replay_revocation_and_exact_ledger_guards():void
    {
        $policy=$this->policy();$this->project->update(['max_runs'=>2]);
        $this->expectHttp(422,fn()=>$this->grant(1,$policy));$this->assertSame(0,IguideLabTourBudget::count());
        $this->project->update(['max_runs'=>6]);$grant=$this->grant(2,$policy);$body=$this->body(1);$owner=app(LabWorkflows::class);$w=$owner->start($this->project,$body);
        config(['iguide-lab.tour_processing_budget_enabled'=>false,'iguide-lab.tour_scheduler_enabled'=>false]);
        $this->assertSame($w->id,$owner->start($this->project,$body)->id);$this->assertSame(1,IguideLabTourBudget::sole()->reserved_items);
        $item=IguideLabWorkflowItem::sole();$this->assertTrue(app(LabWorkflowScheduler::class)->admit($w,$item,$this->project));
        $step=app(LabWorkflowSteps::class)->reserve($item,'analysis-1','analysis',[]);$inv=app(LabWorkflowSteps::class)->invocation($step);
        app(LabTourProcessingBudget::class)->revoke($grant['id']);
        $this->expectHttp(409,fn()=>app(LabWorkflowInvocation::class)->claim($inv,$this->project->id,$step->request_id,'analysis',fn()=>$this->fail('Revoked claim ran.')));
        $this->assertSame(1,IguideLabTourBudget::sole()->reserved_items);$this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
    public function test_competing_admission_no_refund_expiry_and_ledger_tamper():void
    {
        $policy=$this->policy();$grant=$this->grant(1,$policy);$owner=app(LabWorkflows::class);$w=$owner->start($this->project,$this->body(1));
        $this->expectHttp(429,fn()=>$owner->start($this->project,$this->body(1)));$this->assertSame(1,IguideLabWorkflow::count());
        $saved=IguideLabTourBudget::sole()->reserved;$changed=$saved;$changed['analysis']++;
        IguideLabTourBudget::sole()->update(['reserved'=>$changed]);
        $this->expectHttp(422,fn()=>app(LabWorkflowScheduler::class)->policy($w));
        IguideLabTourBudget::sole()->update(['reserved'=>$saved]);$owner->cancel($w);
        $this->expectHttp(429,fn()=>$owner->start($this->project,$this->body(1)));$this->assertSame(1,IguideLabTourBudget::sole()->reserved_items);
        $this->travel(9)->days();$this->expectHttp(409,fn()=>app(LabWorkflowScheduler::class)->policy($w));Http::assertNothingSent();
    }
    public function test_private_budget_bounds_unknown_fields_and_current_project_cap_cannot_be_bypassed():void
    {
        $policy=$this->policy();$budget=app(LabTourProcessingBudget::class);$limits=app(LabWorkflowSteps::class)->limits($policy,'twilight');
        $this->expectHttp(422,fn()=>$budget->create($this->project->id,81,$limits,now()->addDay()->timestamp));
        $oversized=$limits;$oversized['image']=4;
        $this->expectHttp(422,fn()=>$budget->create($this->project->id,1,$oversized,now()->addDay()->timestamp));
        $this->expectHttp(422,fn()=>$budget->create($this->project->id,1,$limits,now()->addDays(11)->timestamp));
        $this->expectHttp(429,fn()=>app(LabWorkflows::class)->start($this->project,$this->body(1)));
        $grant=$this->grant(1,$policy);$this->expectHttp(409,fn()=>$this->grant(1,$policy));
        $this->withToken(str_repeat('a',64))->postJson('/api/iguide-lab/workflows',$this->body(1)+['tourProcessingAllowance'=>$grant])->assertUnprocessable();
        $w=app(LabWorkflows::class)->start($this->project,$this->body(1));
        $saved=$w->policy;$forged=$saved;$forged['tourProcessingAllowance']['workflowId']=(string)Str::uuid();$w->update(['policy'=>$forged]);
        $this->expectHttp(422,fn()=>app(LabWorkflowScheduler::class)->policy($w));$w->update(['policy'=>$saved]);
        $this->project->update(['max_runs'=>0]);
        $this->expectHttp(429,fn()=>app(LabWorkflowScheduler::class)->assertImageCapacity($this->project->fresh(),$w));
        $this->assertSame(0,IguideLabRun::count());$this->assertSame(0,$this->project->fresh()->used_runs);Http::assertNothingSent();
    }
}
