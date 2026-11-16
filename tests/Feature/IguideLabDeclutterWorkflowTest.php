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
    /** Shared harness: the real advance() with model children replaced by fixed answers; no provider is called. */
    private function advanceWith(array $proposal,array $planResult,?callable $before=null): array
    {
        [$project,$case,$workflow,$item]=$this->fixture();config(['iguide-lab.declutter_workflow_enabled'=>true]);
        $policy=$workflow->policy;$policy['declutterWorkflow']=app(\App\Services\IguideLab\LabDeclutterWorkflow::class)->snapshot();$workflow->update(['policy'=>$policy]);
        $this->partialMock(\App\Services\IguideLab\LabDeclutterWorkflow::class,function($m){$m->shouldReceive('ready')->andReturn(true);$m->shouldReceive('validateSnapshot');});
        $this->mock(\App\Services\IguideLab\LabWorkflowArtifacts::class,fn($m)=>$m->shouldReceive('preflight'));
        $this->mock(\App\Services\IguideLab\LabAnalyses::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function()use($proposal){
            $a=new \App\Models\IguideLabAnalysis(['status'=>'completed']);$a->id=(string)Str::uuid();$a->proposal=$proposal;return $a;}));
        $views=new \ArrayObject;
        $this->mock(\App\Services\IguideLab\LabSections::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function($p,$input)use($case,$views){
            $views[]=$input['view'];$s=new \App\Models\IguideLabSection(['status'=>'completed']);$s->id=(string)Str::uuid();$s->case_id=$case->id;$s->source_sha256=$case->source_sha256;
            $s->view=$input['view'];$s->derived_case_id=$case->id;return $s;}));
        $plan=new \App\Models\IguideLabDeclutterRemovalPlan(['status'=>'completed']);$plan->id=(string)Str::uuid();$plan->result=$planResult;
        $this->mock(\App\Services\IguideLab\LabDeclutterRemovalPlans::class,function($m)use($item,$plan){
            $m->shouldReceive('reserve')->andReturnUsing(fn()=>app(LabWorkflowSteps::class)->reserve($item,'removal-plan','analysis',['fixture'=>true]));
            $m->shouldReceive('start')->andReturn($plan);$m->shouldReceive('verified');$m->shouldReceive('reserveSegmentationSteps')->andReturn([]);
        });
        $this->mock(\App\Services\IguideLab\LabDeclutterSupport::class,fn($m)=>$m->shouldReceive('derive')->andReturn(
            ['reportPath'=>'support/report.json','reportSha256'=>str_repeat('f',64),'report'=>['status'=>'ready_for_generation','caveats'=>[],'reviewReasons'=>[]]]));
        if($before)$before($project,$case);
        app(\App\Services\IguideLab\LabDeclutterWorkflow::class)->advance($workflow->fresh(),$item->fresh(),$project->fresh());
        return [$item->fresh(),$views->getArrayCopy(),$project];
    }
    public function test_a_stored_manual_review_source_is_cut_from_its_cited_view_and_planned_instead_of_stopping(): void
    {
        $stored=json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/held-replay/declutter-f94e91.json')),true);
        [$item,$views]=$this->advanceWith($stored['proposal'],['status'=>'needs_review','reviewReasons'=>['TARGET_BOX_MISSING'],'caveats'=>[]]);
        $this->assertSame([['yaw'=>0.0,'pitch'=>-15.0,'hFov'=>120.0]],$views);
        // The removal plan (a free analysis on the cut, no image) now decides; its stop is still before any image.
        $this->assertSame('removal_plan_requires_review',$item->data['reviewReasons'][0]['code']);
        $this->assertSame($stored['proposal']['uncertainties'],array_slice($item->data['sourceCaveats'],0,count($stored['proposal']['uncertainties'])));
        $caveats=$item->data['sourceCaveats'];$this->assertStringContainsString('manual review',end($caveats));
        $this->assertSame(0,\App\Models\IguideLabRun::count());Http::assertNothingSent();
    }
    public function test_a_manual_review_source_with_no_cited_direction_still_stops_before_any_section(): void
    {
        $stored=json_decode(file_get_contents(base_path('tests/Fixtures/iguide-lab/held-replay/declutter-f94e91.json')),true);
        $proposal=$stored['proposal'];$proposal['targets'][0]['evidenceViewIds']=['source','nadir'];
        [$item,$views]=$this->advanceWith($proposal,['status'=>'needs_review','reviewReasons'=>[],'caveats'=>[]]);
        $this->assertSame([],$views);$this->assertSame('unsupported_removal_route',$item->data['reviewReasons'][0]['code']);
    }
    public static function fullCapacity(): array {return ['generation'=>['runs'],'composites'=>['composites']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('fullCapacity')]
    public function test_capacity_is_checked_before_the_removal_image_is_bought(string $full): void
    {
        $proposal=['uncertainties'=>[],'targets'=>[['id'=>'target_object','action'=>'remove','objectCount'=>1,'evidenceViewIds'=>['source','front']]],
            'workflow'=>['mode'=>'perspective_sections','sections'=>[['targetIds'=>['target_object'],'yawDegrees'=>10,'pitchDegrees'=>-30,'hfovDegrees'=>65]]]];
        [$item]=$this->advanceWith($proposal,['status'=>'ready_for_segmentation','reviewReasons'=>[],'caveats'=>[],'proposal'=>['generationPrompt'=>'Remove the canister.']],
            function($project,$case)use($full){
                if($full==='runs'){$project->update(['used_runs'=>$project->max_runs]);return;}
                foreach(range(1,120) as $i)\Illuminate\Support\Facades\DB::table('iguide_lab_composites')->insert(['id'=>(string)Str::uuid(),'project_id'=>$project->id,'case_id'=>$case->id,
                    'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'label'=>'c'.$i,'status'=>'completed','preview_path'=>'p.png','manifest'=>'{}','created_at'=>now(),'updated_at'=>now()]);
            });
        $this->assertSame('needs_review',$item->status);$this->assertSame('generation_capacity_unavailable',$item->data['reviewReasons'][0]['code'],$full);
        $this->assertFalse(\App\Models\IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key','declutter-image')->exists());
        $this->assertSame(0,\App\Models\IguideLabRun::count());Http::assertNothingSent();
    }
    /** Blending failure (reported or a broken harmonizer run) still composites the paid raw draft inside the mask. */
    public static function blendingFailures(): array { return ['reported'=>['reported'],'crashed'=>['crashed']]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('blendingFailures')]
    public function test_failed_blending_composites_the_raw_draft_instead_of_discarding_the_image(string $mode): void
    {
        [$project,$case,,$item]=$this->fixture();$disk=Storage::disk(LabService::DISK);
        $section=\App\Models\IguideLabSection::create(['id'=>(string)Str::uuid(),'project_id'=>$project->id,'case_id'=>$case->id,'derived_case_id'=>$case->id,
            'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'source_sha256'=>$case->source_sha256,'status'=>'completed',
            'view'=>['yaw'=>0,'pitch'=>-30,'hFov'=>65],'data'=>['geometryPath'=>'g.json','geometrySha256'=>str_repeat('b',64)]]);
        $source=new \App\Models\IguideLabAnalysis(['proposal_sha256'=>str_repeat('c',64)]);$source->id=(string)Str::uuid();
        $planStep=new \App\Models\IguideLabWorkflowStep(['input'=>['sectionId'=>$section->id,'sourceAnalysisId'=>$source->id]]);
        $plan=new \App\Models\IguideLabDeclutterRemovalPlan(['result_sha256'=>str_repeat('d',64)]);$plan->id=(string)Str::uuid();
        $plan->result=['schemaVersion'=>LabDeclutterRemovalPolicy::MATERIALS_VERSION];
        $run=new \App\Models\IguideLabRun(['output_path'=>'iguide-lab/'.$project->id.'/runs/raw.png','output_sha256'=>str_repeat('e',64),'request_id'=>(string)Str::uuid()]);$run->id=(string)Str::uuid();
        $step=new \App\Models\IguideLabWorkflowStep(['request_id'=>(string)Str::uuid(),'input'=>['recipe'=>[],'declutterRecipe'=>['support'=>[]],'harmonization'=>['v'=>1],
            'outputSha256'=>$run->output_sha256,'supportSha256'=>str_repeat('f',64),'removalPlanSha256'=>$plan->result_sha256]]);$step->id=(string)Str::uuid();
        $support=['reportSha256'=>str_repeat('f',64),'directory'=>'iguide-lab/'.$project->id.'/support','report'=>['status'=>'ready_for_generation','support'=>['path'=>'/m.png','sha256'=>str_repeat('9',64)]]];
        $this->mock(\App\Services\IguideLab\LabDeclutterWorkflow::class,fn($m)=>$m->shouldReceive('validateSnapshot'));
        $this->mock(\App\Services\IguideLab\LabDeclutterRemovalPlans::class,fn($m)=>$m->shouldReceive('verified')->andReturn([$planStep,$item]));
        $this->mock(\App\Services\IguideLab\LabDeclutterSupport::class,fn($m)=>$m->shouldReceive('derive')->andReturn($support));
        $this->mock(\App\Services\IguideLab\LabWorkflowSources::class,fn($m)=>$m->shouldReceive('verify')->andReturn($case));
        $this->mock(\App\Services\IguideLab\LabDeclutterImageDraft::class,fn($m)=>$m->shouldReceive('verifyRun'));
        $this->mock(\App\Services\IguideLab\LabDeclutterRemovalContext::class,fn($m)=>$m->shouldReceive('file')->andReturnUsing(fn($p,$rel)=>$disk->path($rel)));
        $this->mock(\App\Services\IguideLab\LabWorkflowSteps::class,fn($m)=>$m->shouldReceive('invocation')->andReturn([]));
        $this->mock(\App\Services\IguideLab\LabHarmonization::class,fn($m)=>$m->shouldReceive('run')->andReturnUsing(function($path)use($mode){
            if($mode==='crashed')throw new \RuntimeException('Local harmonization failed its bounded execution checks.');
            $request=json_decode(file_get_contents($path),true);$report=['status'=>'needs_attention','errorCode'=>'BOUNDARY_SOLVE_FAILED','automaticAcceptance'=>false];
            @mkdir($request['outputDirectory'],0777,true);file_put_contents($request['outputDirectory'].'/report.json',json_encode($report));return $report;}));
        $captured=null;
        $this->mock(\App\Services\IguideLab\LabComposites::class,function($m)use(&$captured){$m->shouldReceive('add')->andReturnUsing(function($p,$c,$preview,$manifest)use(&$captured){
            $captured=$manifest;return [new \App\Models\IguideLabComposite];});});
        $artifacts=\Mockery::mock(\App\Services\IguideLab\LabDeclutterArtifacts::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $artifacts->shouldReceive('validateSnapshot','runtime');$artifacts->shouldReceive('runHash')->andReturn($run->output_sha256);
        $artifacts->shouldReceive('asset')->andReturnUsing(fn($d)=>['path'=>'/asset.png','sha256'=>$d['sha256']]);
        $donors=[];
        $artifacts->shouldReceive('execute')->andReturnUsing(function($rel,$request)use(&$donors){
            $donors[]=$request['candidate'];@mkdir($request['outputDirectory'],0777,true);file_put_contents($request['outputDirectory'].'/report.json','{}');
            return ['artifacts'=>['preview'=>['sha256'=>'p'],'native'=>['sha256'=>'n'],'effective-alpha'=>['sha256'=>'a']],'qa'=>[]];});
        $this->assertNotNull($artifacts->composeRemoval($item,$step,$run,$source,$plan,$support),$mode);
        $this->assertSame([['path'=>$disk->path($run->output_path),'sha256'=>$run->output_sha256]],$donors,$mode);
        $this->assertSame($run->output_sha256,$captured['lineage']['harmonizedCandidateSha256'],$mode);
        $this->assertStringContainsString('raw generated draft',end($captured['lineage']['limitations']),$mode);
        $this->assertStringContainsString($mode==='crashed'?'harmonization_execution_failed':'BOUNDARY_SOLVE_FAILED',end($captured['lineage']['limitations']),$mode);
        $this->assertTrue($disk->exists($captured['lineage']['harmonizationReport']['path']),$mode);
    }
}
