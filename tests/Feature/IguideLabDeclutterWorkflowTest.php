<?php
namespace Tests\Feature;
use App\Models\{IguideLabProject,IguideLabWorkflowItem};
use App\Services\IguideLab\{LabDeclutterRemovalPolicy,LabService,LabWorkflowSteps,LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IguideLabDeclutterWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        Queue::fake();Http::preventStrayRequests();config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32))]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $project=IguideLabProject::create(['name'=>'Removal budget','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $bytes=(string)\Intervention\Image\ImageManager::gd()->create(2048,1024)->fill('888888')->toPng();
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('source.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'declutter']]]);
        $policy=$workflow->policy;$policy['declutterRemoval']=LabDeclutterRemovalPolicy::snapshot();$workflow->update(['policy'=>$policy]);
        return [$project,$case,$workflow,IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole()];
    }
    public function test_each_paid_kind_has_its_own_fixed_ceiling_and_exact_idempotent_identity(): void
    {
        [,,,$item]=$this->fixture();$steps=app(LabWorkflowSteps::class);
        foreach(['analysis'=>2,'image'=>1,'segmentation'=>2,'evaluation'=>1,'section'=>1,'composition'=>1] as $kind=>$limit){
            for($i=0;$i<$limit;$i++){$a=$steps->reserve($item,$kind.'-'.$i,$kind,['ordinal'=>$i]);$this->assertSame($a->id,$steps->reserve($item,$kind.'-'.$i,$kind,['ordinal'=>$i])->id);}
            try{$steps->reserve($item,$kind.'-excess',$kind,['ordinal'=>99]);$this->fail('Exceeded '.$kind.' budget');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }Http::assertNothingSent();
    }
    public function test_client_cannot_create_internal_manual_prompt_authority(): void
    {
        [$project,$case]=$this->fixture();
        foreach([['promptMode'=>'machine-removal-plan'],['sourceMachinePlan'=>['humanReviewed'=>true]]] as $options){
            try{app(LabService::class)->start($project,['requestId'=>(string)Str::uuid(),'caseId'=>$case->id,'modelId'=>'gpt-image-2.6-sunburst','preset'=>'declutter','prompt'=>'A fake manual authority.','options'=>$options]);$this->fail('Client authority accepted');}
            catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }Http::assertNothingSent();$this->assertSame(0,\App\Models\IguideLabRun::where('project_id',$project->id)->count());
    }
    public function test_cancelled_workflow_cannot_reserve_removal_or_image(): void
    {
        [,,$workflow,$item]=$this->fixture();$workflow->update(['cancelled_at'=>now()]);
        $this->expectException(HttpException::class);app(LabWorkflowSteps::class)->reserve($item,'removal-plan','analysis',[]);
    }
    public function test_a_plan_that_needs_review_is_recorded_with_its_reasons_not_a_processing_guard(): void
    {
        [$project,$case,$workflow,$item]=$this->fixture();config(['iguide-lab.declutter_workflow_enabled'=>true]);
        $policy=$workflow->policy;$policy['declutterWorkflow']=app(\App\Services\IguideLab\LabDeclutterWorkflow::class)->snapshot();$workflow->update(['policy'=>$policy]);
        $this->partialMock(\App\Services\IguideLab\LabDeclutterWorkflow::class,fn($m)=>$m->shouldReceive('ready')->andReturn(true));
        $this->mock(\App\Services\IguideLab\LabWorkflowArtifacts::class,fn($m)=>$m->shouldReceive('preflight'));
        $proposal=['uncertainties'=>['A faint reflection may exist.'],'targets'=>[['id'=>'target_object','action'=>'remove','objectCount'=>2]],
            'workflow'=>['mode'=>'perspective_sections','sections'=>[['targetIds'=>['target_object'],'yawDegrees'=>10,'pitchDegrees'=>-30,'hfovDegrees'=>65]]]];
        $this->mock(\App\Services\IguideLab\LabAnalyses::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function()use($proposal){
            $a=new \App\Models\IguideLabAnalysis(['status'=>'completed']);$a->id=(string)Str::uuid();$a->proposal=$proposal;return $a;}));
        $this->mock(\App\Services\IguideLab\LabSections::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function($p,$input)use($case){
            $s=new \App\Models\IguideLabSection(['status'=>'completed']);$s->id=(string)Str::uuid();$s->case_id=$case->id;$s->source_sha256=$case->source_sha256;
            $s->view=$input['view'];$s->derived_case_id=$case->id;return $s;}));
        $plan=new \App\Models\IguideLabDeclutterRemovalPlan(['status'=>'completed']);$plan->id=(string)Str::uuid();
        $plan->result=['status'=>'needs_review','reviewReasons'=>['TARGET_TOUCHES_CUT_EDGE'],'caveats'=>[]];
        $this->mock(\App\Services\IguideLab\LabDeclutterRemovalPlans::class,function($m)use($item,$plan){
            $m->shouldReceive('reserve')->andReturnUsing(fn()=>app(LabWorkflowSteps::class)->reserve($item,'removal-plan','analysis',['fixture'=>true]));
            $m->shouldReceive('start')->andReturn($plan);
            $m->shouldReceive('verified')->andThrow(new HttpException(422,'The persisted source-removal plan does not authorize support.'));
        });
        app(\App\Services\IguideLab\LabDeclutterWorkflow::class)->advance($workflow->fresh(),$item->fresh(),$project);
        $item->refresh();
        $this->assertSame('needs_review',$item->status);
        $this->assertSame('removal_plan_requires_review',$item->data['reviewReasons'][0]['code']);
        $this->assertSame(['TARGET_TOUCHES_CUT_EDGE'],$item->data['removalReviewReasons']);
        $this->assertSame(['A faint reflection may exist.'],$item->data['sourceCaveats']);Http::assertNothingSent();
    }
}
