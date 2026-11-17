<?php
namespace Tests\Feature;
use App\Models\{IguideLabAnalysis,IguideLabProject,IguideLabRun,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Services\IguideLab\{LabAnalyses,LabAnalysisRecipes,LabGrassAssessmentPolicy,LabGrassWorkflow,LabGrassWorkflowArtifacts,LabService,
    LabVisualQualityProtocol,LabWorkflowSources,LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Http,Queue,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/** The real advance() up to the grass image step, with model children replaced by fixed answers; no provider is called. */
class IguideLabGrassCapacityTest extends TestCase
{
    use RefreshDatabase;
    public static function fullCapacity(): array {return ['generation'=>['runs'],'composites'=>['composites'],'grass assessments'=>['assessments'],'daily assessments'=>['daily'],'room'=>[null]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('fullCapacity')]
    public function test_capacity_is_checked_before_the_grass_image_is_bought(?string $full): void
    {
        Queue::fake();Http::preventStrayRequests();config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32))]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $project=IguideLabProject::create(['name'=>'Grass budget','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $other=IguideLabProject::create(['name'=>'Other lab project','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $bytes=(string)\Intervention\Image\ImageManager::gd()->create(2048,1024)->fill('888888')->toPng();
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('source.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'grass']]]);
        $filler=IguideLabWorkflowItem::where('workflow_id',app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'grass']]])->id)->sole();
        $recipe=['fixture'=>true,'artifacts'=>['fixture'=>true]];
        $policy=array_replace($workflow->policy,['grassAssessment'=>LabGrassAssessmentPolicy::snapshot(),'grassRecipe'=>$recipe,
            'analysisRecipe'=>[],'modelId'=>'gpt-image-2.6-sunburst','quality'=>'high','complexity'=>'low',
            'evaluationRecipeSha256'=>LabVisualQualityProtocol::recipeSha256(),'evaluationParserSha256'=>hash_file('sha256',(new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName())]);
        unset($policy['grassSourceAdmission']);
        $workflow->update(['policy'=>$policy]);$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();
        $this->partialMock(LabGrassWorkflow::class,function($m)use($recipe){$m->shouldReceive('ready')->andReturn(true);$m->shouldReceive('snapshot')->andReturn($recipe);});
        $this->partialMock(LabAnalysisRecipes::class,fn($m)=>$m->shouldReceive('validate'));
        $this->mock(LabGrassWorkflowArtifacts::class,fn($m)=>$m->shouldReceive('preflight'));
        $this->mock(LabWorkflowSources::class,fn($m)=>$m->shouldReceive('verify')->andReturn($case));
        $this->mock(LabAnalyses::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function(){
            $a=new IguideLabAnalysis(['status'=>'completed']);$a->id=(string)Str::uuid();$a->settings=['options'=>[]];
            $a->proposal=['uncertainties'=>[],'generationPrompt'=>'Recolor the existing lawn.','workflow'=>['mode'=>'whole_panorama','sections'=>[]],'targets'=>[]];return $a;}));
        $bought=new \ArrayObject(['bought'=>false]);
        $this->partialMock(LabService::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function()use($bought){$bought['bought']=true;
            $r=new IguideLabRun(['status'=>'processing']);$r->id=(string)Str::uuid();return $r;}));
        if($full==='runs')$project->update(['used_runs'=>$project->max_runs]);
        if($full==='composites')foreach(range(1,120) as $i)DB::table('iguide_lab_composites')->insert(['id'=>(string)Str::uuid(),'project_id'=>$project->id,'case_id'=>$case->id,
            'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'label'=>'c'.$i,'status'=>'completed','preview_path'=>'p.png','manifest'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        if($full==='assessments'||$full==='daily'){
            $owner=$full==='assessments'?$project:$other;
            foreach(range(1,120) as $i){$stepId=(string)Str::uuid();
                DB::table('iguide_lab_workflow_steps')->insert(['id'=>$stepId,'project_id'=>$owner->id,'item_id'=>$filler->id,
                    'step_key'=>'fixture-'.$i,'kind'=>'analysis','request_id'=>(string)Str::uuid(),'input'=>'{}','input_sha256'=>str_repeat('b',64),'status'=>'completed','created_at'=>now(),'updated_at'=>now()]);
                DB::table('iguide_lab_grass_assessments')->insert(['id'=>(string)Str::uuid(),'step_id'=>$stepId,'project_id'=>$owner->id,'request_id'=>(string)Str::uuid(),
                    'request_hash'=>str_repeat('a',64),'status'=>'completed','data'=>'{}','created_at'=>now(),'updated_at'=>now()]);}
        }
        app(LabGrassWorkflow::class)->advance($workflow->fresh(),$item->fresh(),$project->fresh());$item->refresh();
        $imageStep=IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key','grass-image')->exists();
        if($full===null){$this->assertTrue($bought['bought']);$this->assertTrue($imageStep);return;}
        $this->assertFalse($bought['bought'],$full);$this->assertFalse($imageStep,$full);
        $this->assertSame('needs_review',$item->status);$this->assertSame('generation_capacity_unavailable',$item->data['reviewReasons'][0]['code']);
        Http::assertNothingSent();
    }
}
