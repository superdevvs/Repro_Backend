<?php
namespace Tests\Feature;
use App\Models\{IguideLabAnalysis,IguideLabLifestyleFraming,IguideLabProject,IguideLabRun,IguideLabSection,IguideLabWorkflowItem,IguideLabWorkflowStep};
use App\Services\IguideLab\{LabAnalyses,LabAnalysisRecipes,LabAnalysisSourceContext,LabLifestyleAssessmentPolicy,LabLifestyleCutReadiness,LabLifestyleFramings,LabLifestyleWorkflow,
    LabSections,LabService,LabVisualQualityProtocol,LabWorkflowArtifacts,LabWorkflowSources,LabWorkflows,LabWorkflowSteps};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Http,Queue,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/** The real advance() up to the image step, with model children replaced by fixed answers; no provider is called. */
class IguideLabLifestyleCapacityTest extends TestCase
{
    use RefreshDatabase;
    public static function fullCapacity(): array {return ['generation'=>['runs'],'composites'=>['composites'],'room'=>[null]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('fullCapacity')]
    public function test_capacity_is_checked_before_the_lifestyle_image_is_bought(?string $full): void
    {
        Queue::fake();Http::preventStrayRequests();config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'iguide-lab.lifestyle_workflow_enabled'=>true]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $project=IguideLabProject::create(['name'=>'Lifestyle budget','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $bytes=(string)\Intervention\Image\ImageManager::gd()->create(2048,1024)->fill('888888')->toPng();
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('source.png',$bytes),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$case->id,'preset'=>'lifestyle']]]);
        $policy=array_replace($workflow->policy,['lifestyleAssessment'=>LabLifestyleAssessmentPolicy::snapshot(),'lifestyleWorkflow'=>['fixture'=>true],'lifestyleFraming'=>['fixture'=>true],
            'analysisRecipe'=>[],'sectionAnalysisRecipe'=>[],'artifacts'=>[],'modelId'=>'gpt-image-2.6-sunburst','quality'=>'high','complexity'=>'low',
            'evaluationRecipeSha256'=>LabVisualQualityProtocol::recipeSha256(),'evaluationParserSha256'=>hash_file('sha256',(new \ReflectionClass(LabVisualQualityProtocol::class))->getFileName())]);
        $workflow->update(['policy'=>$policy]);$item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();
        $this->partialMock(LabLifestyleWorkflow::class,function($m){$m->shouldReceive('ready')->andReturn(true);$m->shouldReceive('validateSnapshot');});
        $this->partialMock(LabAnalysisRecipes::class,fn($m)=>$m->shouldReceive('validate'));
        $this->mock(LabWorkflowArtifacts::class,fn($m)=>$m->shouldReceive('preflight'));
        $this->mock(LabWorkflowSources::class,fn($m)=>$m->shouldReceive('verify')->andReturn($case));
        $framing=new IguideLabLifestyleFraming(['status'=>'completed']);$framing->id=(string)Str::uuid();
        $framing->result=['version'=>'fixture','status'=>'framing_candidate','view'=>['yaw'=>0.0,'pitch'=>-10.0,'hFov'=>100.0]];
        $this->mock(LabLifestyleFramings::class,function($m)use($framing){$m->shouldReceive('reserve');$m->shouldReceive('start')->andReturn($framing);$m->shouldReceive('completed')->andReturn($framing);});
        $ids=new \ArrayObject;
        $this->mock(LabSections::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function($p,$input)use($case,$ids){
            $s=new IguideLabSection(['status'=>'completed']);$s->id=$ids['section']=(string)Str::uuid();$s->case_id=$case->id;$s->source_sha256=$case->source_sha256;
            $s->view=$input['view'];$s->derived_case_id=$case->id;return $s;}));
        $this->mock(LabLifestyleCutReadiness::class,function($m){$m->shouldReceive('reference')->andReturn(['fixture'=>true]);
            $m->shouldReceive('verified')->andReturn(['checks'=>['source_floor'=>['status'=>'pass','reason'=>'Level floor.']]]);});
        $this->mock(LabAnalyses::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function()use($case,$ids){
            $a=new IguideLabAnalysis(['status'=>'completed']);$a->id=(string)Str::uuid();$a->settings=['options'=>[]];
            $a->proposal=['uncertainties'=>[],'generationPrompt'=>'Add two adults standing.','workflow'=>['mode'=>'whole_panorama','sections'=>[]],
                'targets'=>[['id'=>'adult_1','action'=>'add','objectCount'=>1],['id'=>'adult_2','action'=>'add','objectCount'=>1]]];
            $a->data=['sourceContext'=>['binding'=>['nativeCaseId'=>$case->id,'sectionId'=>$ids['section'],'nativeSourceSha256'=>$case->source_sha256]]];return $a;}));
        $this->mock(LabAnalysisSourceContext::class,fn($m)=>$m->shouldReceive('verify'));
        $ids['bought']=false;$this->partialMock(LabService::class,fn($m)=>$m->shouldReceive('start')->andReturnUsing(function()use($ids){$ids['bought']=true;
            $r=new IguideLabRun(['status'=>'processing']);$r->id=(string)Str::uuid();return $r;}));
        if($full==='runs')$project->update(['used_runs'=>$project->max_runs]);
        if($full==='composites')foreach(range(1,120) as $i)DB::table('iguide_lab_composites')->insert(['id'=>(string)Str::uuid(),'project_id'=>$project->id,'case_id'=>$case->id,
            'request_id'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'label'=>'c'.$i,'status'=>'completed','preview_path'=>'p.png','manifest'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        app(LabLifestyleWorkflow::class)->advance($workflow->fresh(),$item->fresh(),$project->fresh());$item->refresh();
        $imageStep=IguideLabWorkflowStep::where('item_id',$item->id)->where('step_key','lifestyle-image')->exists();$bought=$ids['bought'];
        if($full===null){$this->assertTrue($bought);$this->assertTrue($imageStep);return;}
        $this->assertFalse($bought,$full);$this->assertFalse($imageStep,$full);
        $this->assertSame('needs_review',$item->status);$this->assertSame('generation_capacity_unavailable',$item->data['reviewReasons'][0]['code']);
        Http::assertNothingSent();
    }
}
