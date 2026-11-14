<?php
namespace Tests\Feature;

use App\Models\{IguideLabAnalysis,IguideLabWorkflowStep};
use App\Services\IguideLab\{LabStagingLayoutReadiness,LabStagingCutReadiness,LabStagingLayoutProtocol,LabStagingAssessmentPolicy,
    LabAnalysisProvider,LabAnalysisRecipes,LabWorkflowSteps,LabWorkflowInvocation};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Shared fixture retains the existing framing receipt lifecycle; only new source-layout gates are mocked below. */
class IguideLabStagingLayoutAuthorityTest extends IguideLabStagingFramingTest
{
    private function layoutFixture(): array
    {
        $fixture=(new \ReflectionMethod(IguideLabStagingFramingTest::class,'fixture'))->invoke($this);
        $workflow=$fixture[1];$policy=$workflow->policy;$policy['stagingLayout']=['version'=>'automatic-staging-source-layout/1'];
        $workflow->update(['policy'=>$policy]);return $fixture;
    }
    public function test_layout_budget_is_four_distinct_analyses_one_image_and_two_source_sections():void
    {
        [,, $item]=$this->layoutFixture();$steps=app(LabWorkflowSteps::class);
        $initial=$steps->reserve($item,'staging-layout-initial','analysis',[]);
        $followup=$steps->reserve($item,'staging-layout-followup','analysis',['sourceOperation'=>'details']);
        $run=(string)Str::uuid();$steps->reserve($item,'placement-'.$run,'analysis',['protocolVersion'=>LabStagingAssessmentPolicy::VERSION,'candidateRunId'=>$run]);
        $this->assertSame(4,IguideLabWorkflowStep::where('item_id',$item->id)->where('kind','analysis')->count());
        $this->assertSame($followup->request_id,$steps->reserve($item,'staging-layout-followup','analysis',['sourceOperation'=>'details'])->request_id);
        try{$steps->reserve($item,'staging-layout-followup','analysis',['sourceOperation'=>'reframe']);$this->fail('Adaptive operation changed on replay.');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        try{$steps->reserve($item,'other-analysis','analysis',[]);$this->fail('Unbounded analysis accepted.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $steps->reserve($item,'staging-section','section',[]);$steps->reserve($item,'staging-reframed-section','section',[]);
        try{$steps->reserve($item,'third-section','section',[]);$this->fail();}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $steps->reserve($item,'staging-image','image',[]);
        try{$steps->reserve($item,'second-image','image',[]);$this->fail();}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        Http::assertNothingSent();
    }
    public function test_layout_image_claim_cannot_fall_back_to_legacy_cut_authority():void
    {
        [$project,,$item]=$this->layoutFixture();$steps=app(LabWorkflowSteps::class);
        $step=$steps->reserve($item,'staging-image','image',['caseId'=>$item->case_id,'options'=>[]]);$claimed=false;
        $this->mock(LabStagingCutReadiness::class)->shouldNotReceive('verifyImageStep');
        $this->mock(LabStagingLayoutReadiness::class)->shouldReceive('verifyImageStep')->once()->andThrow(new HttpException(422,'Original aperture evidence unresolved.'));
        try{app(LabWorkflowInvocation::class)->claim($steps->invocation($step),$project->id,$step->request_id,'image',function()use(&$claimed){$claimed=true;});$this->fail();}
        catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->assertFalse($claimed);Http::assertNothingSent();
    }
    public function test_layout_analysis_revalidates_authority_before_quota_fallback():void
    {
        [$project,,$item,$case]=$this->layoutFixture();$steps=app(LabWorkflowSteps::class);
        $step=$steps->reserve($item,'staging-layout-initial','analysis',[]);$payload=['model'=>'gpt-6-astra','input'=>[]];
        $record=IguideLabAnalysis::create(['project_id'=>$project->id,'case_id'=>$case->id,'request_id'=>$step->request_id,'request_hash'=>str_repeat('a',64),
            'source_sha256'=>$case->source_sha256,'settings'=>['preset'=>'staging','style'=>'modern'],'status'=>'submitting','started_at'=>now(),
            'data'=>['stagingLayout'=>['version'=>LabStagingLayoutProtocol::GATE],'workflowInvocation'=>$steps->invocation($step),'providerBodySha256'=>LabAnalysisRecipes::hash($payload)]]);
        $steps->attach($step,$record->id);$checks=0;
        $this->mock(LabStagingLayoutReadiness::class)->shouldReceive('verifyForAttempt')->twice()->andReturnUsing(function()use(&$checks){if(++$checks===2)throw new HttpException(422,'Prior receipt changed after quota.');});
        Http::fake(['api.openai.com/v1/responses'=>Http::response(['error'=>['code'=>'insufficient_quota']],429)]);
        try{app(LabAnalysisProvider::class)->analyze($record,$payload);$this->fail('Changed authority reached fallback.');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        Http::assertSentCount(1);$this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
        $this->assertSame(0,$project->fresh()->used_runs);
    }
}
