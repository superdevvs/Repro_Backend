<?php
namespace Tests\Feature;
use App\Models\{IguideLabProject,IguideLabWorkflowItem,IguideLabInteriorAssessment,IguideLabRun};
use App\Services\IguideLab\{LabService,LabWorkflows,LabWorkflowSteps,LabInteriorDesignPolicy,LabInteriorDesignAssessments,LabInteriorDesignMaterials,LabInteriorDesignImageDraft,LabProvider,LabImages};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http,Queue,Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IguideLabInteriorImageAuthorityTest extends TestCase
{
    use RefreshDatabase;
    private ?string $key=null;
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();Http::preventStrayRequests();$this->key=tempnam(sys_get_temp_dir(),'interior-image-key');file_put_contents($this->key,'fixture-primary');
        config(['app.key'=>'base64:'.base64_encode(str_repeat('k',32)),'services.openai.api_key'=>'fixture-fallback','iguide-lab.openai_primary_key_path'=>$this->key,'iguide-lab.interior_design_workflow_enabled'=>true]);
        if(function_exists('posix_getegid'))config(['filesystems.disks.'.LabService::DISK.'.shared_group'=>posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK,config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
    }
    protected function tearDown(): void {if($this->key&&is_file($this->key))unlink($this->key);parent::tearDown();}
    private function png(int $w,int $h): string {$im=imagecreatetruecolor($w,$h);imagefill($im,0,0,0x808080);ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;}
    private function fixture(): array
    {
        $project=IguideLabProject::create(['name'=>'Material image authority','token_hash'=>hash('sha256',Str::random(64)),'expires_at'=>now()->addDay(),'max_runs'=>20]);
        $native=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('native.png',$this->png(2048,1024)),['label'=>'Original','projection'=>'equirectangular']);
        $workflow=app(LabWorkflows::class)->start($project,['requestId'=>(string)Str::uuid(),'items'=>[['caseId'=>$native->id,'preset'=>'interior-design','style'=>'modern']]]);
        $policy=$workflow->policy;$policy['interiorDesign']=LabInteriorDesignPolicy::snapshot();$policy['interiorMaterials']=['fixture'=>true];$workflow->update(['policy'=>$policy]);
        $item=IguideLabWorkflowItem::where('workflow_id',$workflow->id)->sole();$bytes=$this->png(2048,2048);$sectionId=(string)Str::uuid();
        $case=app(LabService::class)->addCase($project,UploadedFile::fake()->createWithContent('cut.png',$bytes),['label'=>'Original cut','projection'=>'rectilinear']);$case->update(['metadata'=>['sectionId'=>$sectionId]]);
        $planStep=app(LabWorkflowSteps::class)->reserve($item,'interior-cut','analysis',[]);
        $record=IguideLabInteriorAssessment::create(['id'=>(string)Str::uuid(),'project_id'=>$project->id,'step_id'=>$planStep->id,'request_id'=>$planStep->request_id,'request_hash'=>$planStep->input_sha256,
            'phase'=>'cut','status'=>'completed','result_sha256'=>str_repeat('b',64),'result'=>['status'=>'ready_for_source_masks','generationPrompt'=>'Restyle the two existing textiles with coherent modern woven material.','targets'=>[['id'=>'material_1'],['id'=>'material_2']]],
            'data'=>['contextSnapshot'=>['cutCaseId'=>$case->id,'sectionId'=>$sectionId,'context'=>['sourceSha256'=>$case->source_sha256],
                'assets'=>[['id'=>'source','sha256'=>hash('sha256',LabImages::prepared($bytes))]],'geometry'=>['sha256'=>str_repeat('c',64)],
                'sourceContextSnapshot'=>['binding'=>['nativeSourceSha256'=>$native->source_sha256],'bindingSha256'=>str_repeat('d',64)]]]]);
        $this->mock(LabInteriorDesignAssessments::class)->shouldReceive('completed')->andReturn($record);
        $materials=new ImageSupportFixture;$this->app->instance(LabInteriorDesignMaterials::class,$materials);
        $input=['caseId'=>$case->id,'modelId'=>'gpt-image-2.6-sunburst','preset'=>'interior-design','style'=>'modern','interiorPlanId'=>$record->id,
            'supportSha256'=>str_repeat('e',64),'prompt'=>$record->result['generationPrompt'],'options'=>['sectionId'=>$sectionId,'complexity'=>'low','promptMode'=>'machine-interior-material-plan']];
        $step=app(LabWorkflowSteps::class)->reserve($item,'interior-image','image',$input);$inv=app(LabWorkflowSteps::class)->invocation($step);
        $saved=app(LabInteriorDesignImageDraft::class)->apply($record,$case,$input,$inv);
        $run=IguideLabRun::create(['project_id'=>$project->id,'case_id'=>$case->id,'request_id'=>$step->request_id,'request_hash'=>str_repeat('f',64),'status'=>'submitting','provider'=>'openai',
            'model_id'=>'gpt-image-2.6-sunburst','preset'=>'interior-design','style'=>'modern','prompt'=>$input['prompt'],'options'=>[
                'promptMode'=>'machine-interior-material-plan','sourceMachinePlan'=>$saved,'workflowInvocation'=>$inv,'sectionId'=>$sectionId,'quality'=>'high','complexity'=>'low','width'=>2048,'height'=>2048,
                'referenceCaseIds'=>[],'referenceRunIds'=>[]]]);app(LabWorkflowSteps::class)->attach($step,$run->id);
        return [$project,$workflow,$item,$case,$record,$step,$run,$materials,$input,$inv,$bytes];
    }
    public function test_source_support_is_not_an_image_or_style_acceptance_claim(): void
    {
        [,,,$case,,,$run]=$this->fixture();app(LabInteriorDesignImageDraft::class)->verifyRun($run,$case);
        $this->assertSame(LabInteriorDesignImageDraft::VERSION,$run->options['sourceMachinePlan']['version']);$this->assertFalse($run->options['sourceMachinePlan']['humanReviewed']);
        $this->assertArrayNotHasKey('sourceAnalysis',$run->options);$this->assertSame(0,$run->options['sourceMachinePlan']['operatorInterventions']);
    }
    public function test_source_support_drift_after_primary_quota_blocks_paid_fallback(): void
    {
        [,,,,,,$run,$materials,,,$bytes]=$this->fixture();$count=0;
        Http::fake(function()use(&$count,$materials){$count++;$materials->sha=str_repeat('a',64);return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        try{app(LabProvider::class)->submit($run,[$bytes]);$this->fail('Changed source support permitted fallback.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->assertSame(1,$count);$this->assertSame(['quota_rejected'],array_column($run->fresh()->options['providerAttempts'],'status'));
    }
    public function test_held_support_and_added_reference_cannot_authorize_a_run(): void
    {
        [,,,$case,$record,,$run,$materials,$input,$inv]=$this->fixture();$materials->status='needs_review';
        try{app(LabInteriorDesignImageDraft::class)->apply($record,$case,$input,$inv);$this->fail('Held source accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $materials->status='eligible_for_donor_request';$options=$run->options;$options['referenceRunIds']=[(string)Str::uuid()];$run->options=$options;
        $this->expectException(HttpException::class);app(LabInteriorDesignImageDraft::class)->verifyRun($run,$case);
    }
    public function test_public_call_cannot_assert_internal_material_plan(): void
    {
        [$project,,,,,,,,$input]=$this->fixture();$this->expectException(HttpException::class);
        app(LabService::class)->start($project,$input+['requestId'=>(string)Str::uuid()]);
    }
    public function test_changed_report_and_local_commit_cannot_replace_the_persisted_source_support_digest(): void
    {
        [,,$item,,$record]=$this->fixture();$materials=new PersistedSupportFixture;
        $first=$materials->sourceSupport($item,$record,[]);
        $this->assertSame($first['reportSha256'],$record->fresh()->data['sourceSupport']['reportSha256']);
        $this->assertSame($first,$materials->sourceSupport($item,$record,[]));
        $materials->sha=str_repeat('a',64);
        try{$materials->sourceSupport($item,$record,[]);$this->fail('A replacement report changed immutable image authority.');}
        catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->assertSame($first['reportSha256'],$record->fresh()->data['sourceSupport']['reportSha256']);
        Http::assertNothingSent();
    }
}
class ImageSupportFixture extends LabInteriorDesignMaterials
{
    public string $sha='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';public string $status='eligible_for_donor_request';
    public function sourceSupport(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $cut,array $snapshot): array
    {return ['reportSha256'=>$this->sha,'report'=>['status'=>$this->status]];}
}
class PersistedSupportFixture extends LabInteriorDesignMaterials
{
    public string $sha='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    public function validate(array $snapshot): void {}
    protected function request(IguideLabWorkflowItem $item,IguideLabInteriorAssessment $cut,?IguideLabInteriorAssessment $candidate,array $snapshot,string $requestId): array{return [];}
    protected function execute(IguideLabWorkflowItem $item,array $request,array $snapshot): array
    {return ['reportPath'=>'private-fixture/report.json','reportSha256'=>$this->sha,'requestSha256'=>str_repeat('c',64),
        'report'=>['operation'=>'source-support','status'=>'eligible_for_donor_request','imageGenerationAuthorized'=>false,'materialTransferAuthorized'=>false]];}
}
