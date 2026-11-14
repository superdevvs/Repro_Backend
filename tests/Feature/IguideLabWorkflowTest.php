<?php
namespace Tests\Feature;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Models\IguideLabWorkflow;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use App\Services\IguideLab\LabAnalyses;
use App\Services\IguideLab\LabAnalysisViews;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabWorkflowArtifacts;
use App\Services\IguideLab\LabWorkflowInvocation;
use App\Services\IguideLab\LabWorkflowSteps;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private IguideLabProject $project;
    private IguideLabCase $case;
    private string $key;
    private string $token;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        $this->key = tempnam(sys_get_temp_dir(), 'workflow-key-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback', 'iguide-lab.openai_primary_key_path' => $this->key]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory(''); $disk->setVisibility('', 'private');
        $this->token = str_repeat('a', 64); $this->project = IguideLabProject::create(['name' => 'Workflow', 'token_hash' => hash('sha256', $this->token), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $png = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $this->case = app(LabService::class)->addCase($this->project, UploadedFile::fake()->createWithContent('source.png', $png), ['label' => 'Original', 'projection' => 'equirectangular']);
        $this->mock(LabAnalysisViews::class)->shouldReceive('ready')->andReturn(true);
        $this->mock(LabWorkflowArtifacts::class, function ($mock) {
            $mock->shouldReceive('snapshot')->andReturn(['recipeVersion' => 'fixture/1']);
            $mock->shouldReceive('validateSnapshot')->with(['recipeVersion' => 'fixture/1'])->andReturnNull();
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('preflight')->andReturnNull();
        });
    }
    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }
    private function body(string $preset = 'twilight'): array
    {
        return ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $this->case->id, 'preset' => $preset, 'style' => null, 'instructions' => 'Keep all existing architecture.']]];
    }
    private function workflow(?array $body = null): IguideLabWorkflow { return app(LabWorkflows::class)->start($this->project, $body ?? $this->body()); }

    private function completedProposal(IguideLabAnalysis $analysis): void
    {
        $proposal=['generationPrompt'=>'Apply visible requested lighting while retaining the original sphere and matching reflected surfaces.', 'workflow'=>['mode'=>'whole_panorama'], 'uncertainties'=>[]];
        $sha=hash('sha256',\App\Services\IguideLab\LabImages::prepared(Storage::disk(LabService::DISK)->get($this->case->source_path)));
        $analysis->update(['status'=>'completed','proposal'=>$proposal,'proposal_sha256'=>\App\Services\IguideLab\LabAnalysisRecipes::hash($proposal),
            'data'=>array_merge($analysis->data,['preparedSourceSha256'=>$sha,'repairContextManifestSha256'=>str_repeat('e',64)])]);
    }
    private function qualityResult(string $failedCheck, string $action): array
    {
        $checks=[];foreach(\App\Services\IguideLab\LabVisualQualityProtocol::CHECKS as $key)$checks[$key]=['status'=>$key===$failedCheck?'fail':'pass','evidenceViewIds'=>[$key==='seam'?'wrap':($key==='poles'?'poles':'front')], 'reason'=>str_repeat('Full retained failure detail. ',12).' END_REASON'];
        return ['protocolVersion'=>\App\Services\IguideLab\LabVisualQualityProtocol::VERSION,'qualityStatus'=>$failedCheck===''?'passed_automated_checks':'needs_review','checks'=>$checks,'unresolvedDependencies'=>[], 'recommendedAction'=>$action,'summary'=>'The requested effect must remain visibly sufficient.', 'manualAcceptance'=>false,'crossViewVerified'=>false];
    }
    public static function repairCases(): array { return ['first repair then replan'=>[false], 'later guidance gets its own repair'=>[true]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('repairCases')]
    public function test_global_fallback_reuses_latest_run_once_and_failed_intent_replans_before_new_image(bool $laterGlobal): void
    {
        config(['iguide-lab.lighting_repair_enabled'=>true]);$workflow=$this->workflow();$service=app(LabWorkflows::class);
        $service->process($workflow);$this->completedProposal(IguideLabAnalysis::sole());$service->process($workflow->fresh());
        $firstRun=IguideLabRun::sole();$firstRun->update(['status'=>'completed']);
        $artifacts=app(LabWorkflowArtifacts::class);$artifacts->shouldReceive('runHash')->andReturn(str_repeat('a',64));
        $artifacts->shouldReceive('verifyComposite')->andReturnNull();
        $artifacts->shouldReceive('compose')->times($laterGlobal?4:3)->andReturnUsing(function($item,$step,$run){
            return \App\Models\IguideLabComposite::create(['project_id'=>$item->project_id,'case_id'=>$item->case_id,'request_id'=>$step->request_id,'request_hash'=>str_repeat('a',64),'label'=>'Fixture local artifact','status'=>'completed','preview_path'=>'test','manifest'=>['children'=>[['runId'=>$run->id]]]]);
        });
        $artifacts->shouldReceive('evidence')->times($laterGlobal?4:3)->andReturnUsing(fn($item,$composite)=>['compositeId'=>$composite->id,'context'=>['mechanicalQa'=>['passed'=>true],'evidenceManifestSha256'=>str_repeat('e',64)]]);
        $count=0;$this->mock(\App\Services\IguideLab\LabWorkflowEvaluations::class,function($mock)use(&$count,$laterGlobal){
            $mock->shouldReceive('ready')->andReturn(true);$mock->shouldReceive('payload')->andReturn([]);
            $mock->shouldReceive('verifiedCompleted')->andReturnUsing(fn($item,$record)=>$record->fresh()->result);
            $mock->shouldReceive('start')->times($laterGlobal?4:3)->andReturnUsing(function($step)use(&$count,$laterGlobal){
                $result=match(++$count){1=>$this->qualityResult('optical_dependencies','replan'),2=>$this->qualityResult('edit','regenerate'),3=>$laterGlobal?$this->qualityResult('optical_dependencies','recompose'):$this->qualityResult('','none'),default=>$this->qualityResult('','none')};
                return \App\Models\IguideLabWorkflowEvaluation::create(['project_id'=>$step->project_id,'step_id'=>$step->id,'request_id'=>$step->request_id,'request_hash'=>$step->input_sha256,'status'=>'completed','result'=>$result,
                    'data'=>['workflowInvocation'=>app(LabWorkflowSteps::class)->invocation($step),'evidence'=>$step->input,'responseReceipt'=>['sha256'=>str_repeat('f',64)]]]);
            });
        });
        // Real authority references and hashes; only the already completed quality provider is faked.
        $this->partialMock(\App\Services\IguideLab\LabWorkflowRepairContext::class)->shouldReceive('prepare')->andReturn(['repairContextManifestSha256'=>str_repeat('e',64)]);
        $service->process($workflow->fresh()); // Original local composition.
        $service->process($workflow->fresh()); // Optical failure reserves the free repair state.
        $item=IguideLabWorkflowItem::sole();$this->assertSame('pending',$item->data['globalRepair']['state']);
        $pendingData=$item->data;
        $service->process($workflow->fresh()); // Free retained-guidance composition.
        $this->assertSame(1,IguideLabRun::count());$this->assertSame(1,IguideLabAnalysis::count());
        $globalStep=IguideLabWorkflowStep::where('step_key','composition-global-1')->sole();
        $this->assertSame($firstRun->id,$globalStep->input['runId']);$this->assertFalse($globalStep->input['repair']['newPaidImage']);
        // Crash checkpoint: the child attach committed, but the item still has the old composite.
        $item->update(['data'=>$pendingData]);
        $service->process($workflow->fresh()); // Weak effect fails; full context is retained.
        $item->refresh();$this->assertSame('failed',$item->data['globalRepair']['state']);$this->assertSame('repairing',$item->status);
        $this->assertSame($globalStep->child_id,$item->data['globalRepair']['compositeId']);
        $this->assertSame(2,$item->data['planRevision']);$this->assertStringContainsString('END_REASON',json_encode($item->data['lastQualityFeedback']));
        $this->assertArrayNotHasKey('imageCorrection',$item->data);$this->assertSame(1,IguideLabRun::count());
        $this->assertFalse(app(\App\Services\IguideLab\LabLightingRepairs::class)->begin($workflow->fresh(),$item,$firstRun,IguideLabAnalysis::sole(),\App\Models\IguideLabWorkflowEvaluation::first(),$item->data));
        $service->process($workflow->fresh());$second=IguideLabAnalysis::whereKeyNot(IguideLabAnalysis::orderBy('created_at')->first()->id)->sole();
        $this->assertSame($item->data['repairContext'],$second->data['repairContext']);
        $this->completedProposal($second);$service->process($workflow->fresh());
        $this->assertSame(2,IguideLabRun::count());IguideLabRun::whereKeyNot($firstRun->id)->sole()->update(['status'=>'completed']);
        $service->process($workflow->fresh());$service->process($workflow->fresh());$service->process($workflow->fresh());
        if($laterGlobal){$service->process($workflow->fresh());$service->process($workflow->fresh());
            $latest=IguideLabRun::whereKeyNot($firstRun->id)->sole();$secondGlobal=IguideLabWorkflowStep::where('step_key','composition-global-2')->sole();
            $this->assertSame($latest->id,$secondGlobal->input['repair']['retainedRunId']);$this->assertSame(2,IguideLabRun::count());
            $this->assertCount(1,IguideLabWorkflowItem::sole()->data['globalRepairHistory']);
        }
        $this->assertSame('ready',IguideLabWorkflowItem::sole()->status);
        $this->assertSame(1,IguideLabWorkflowStep::where('step_key','composition-global-1')->count());
        $this->assertSame($laterGlobal?4:3,\App\Models\IguideLabWorkflowEvaluation::count());$this->assertSame(2,IguideLabAnalysis::count());
        $this->assertSame(0,\App\Models\IguideLabReview::count());Http::assertNothingSent();
    }
    public function test_enabled_repair_limits_and_snapshot_drift_fail_closed(): void
    {
        config(['iguide-lab.lighting_repair_enabled'=>true]);$workflow=$this->workflow();$item=IguideLabWorkflowItem::sole();$steps=app(LabWorkflowSteps::class);
        foreach(['image'=>3,'analysis'=>2,'evaluation'=>5,'composition'=>5] as $kind=>$limit){
            for($i=1;$i<=$limit;$i++)$steps->reserve($item,$kind.'-'.$i,$kind,[]);
            try{$steps->reserve($item,$kind.'-'.($limit+1),$kind,[]);$this->fail('Unbounded '.$kind);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }
        try{$steps->reserve($item,'composition-global-3','composition',[]);$this->fail('Third local fallback accepted.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $policy=$workflow->policy;$policy['lightingRepair']['maxLocalRepairsPerItem']=3;
        try{app(\App\Services\IguideLab\LabLightingRepairs::class)->validate($policy);$this->fail('Changed policy accepted.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        Http::assertNothingSent();
    }

    public function test_scoped_api_replay_binds_intent_and_does_not_charge_on_reservation(): void
    {
        $body = $this->body(); $this->postJson('/api/iguide-lab/workflows', $body)->assertUnauthorized();
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/workflows', $body)->assertAccepted()->json('data');
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows', $body)->assertAccepted()->assertJsonPath('data.id', $first['id']);
        $changed = $body; $changed['items'][0]['instructions'] = 'Different';
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows', $changed)->assertConflict();
        $this->withToken($this->token)->postJson('/api/iguide-lab/workflows', $body + ['modelId' => 'untrusted'])->assertUnprocessable();
        $this->assertSame(1, IguideLabWorkflow::count()); $this->assertSame(0, $this->project->fresh()->used_runs);
        $otherToken = str_repeat('b', 64); IguideLabProject::create(['name' => 'Other', 'token_hash' => hash('sha256', $otherToken), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $this->withToken($otherToken)->getJson('/api/iguide-lab/workflows/'.$first['id'])->assertNotFound();
        $this->withToken($otherToken)->postJson('/api/iguide-lab/workflows/'.$first['id'].'/cancel')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_optional_empty_instructions_replay_as_the_existing_omitted_identity(): void
    {
        $body = $this->body(); unset($body['items'][0]['instructions'], $body['items'][0]['style']);
        $first = $this->withToken($this->token)->postJson('/api/iguide-lab/workflows', $body)->assertAccepted()->json('data.id');
        $identity = IguideLabWorkflow::sole()->request_hash;
        foreach (['', null, '   '] as $instructions) {
            $replay = $body; $replay['items'][0]['instructions'] = $instructions; $replay['items'][0]['style'] = null;
            $this->withToken($this->token)->postJson('/api/iguide-lab/workflows', $replay)->assertAccepted()->assertJsonPath('data.id', $first);
            $this->assertSame($identity, IguideLabWorkflow::sole()->request_hash);
            $this->assertSame('', IguideLabWorkflowItem::sole()->instructions);
        }
        $this->assertSame(1, IguideLabWorkflow::count()); $this->assertSame(1, IguideLabWorkflowItem::count());
        Queue::assertPushed(\App\Jobs\ProcessIguideLabWorkflow::class, 1);
        $this->assertSame(0, IguideLabAnalysis::count()); $this->assertSame(0, $this->project->fresh()->used_runs);
        Http::assertNothingSent();
    }

    public function test_optional_instructions_still_reject_nonstrings_and_overlong_values_before_reservation(): void
    {
        foreach ([[], 123, false, str_repeat('x', 2001)] as $instructions) {
            $body = $this->body(); $body['items'][0]['instructions'] = $instructions;
            $this->withToken($this->token)->postJson('/api/iguide-lab/workflows', $body)
                ->assertUnprocessable()->assertJsonValidationErrors('items.0.instructions');
        }
        $this->assertSame(0, IguideLabWorkflow::count()); $this->assertSame(0, IguideLabWorkflowItem::count());
        $this->assertSame(0, $this->project->fresh()->used_runs); Queue::assertNothingPushed(); Http::assertNothingSent();
    }

    public function test_public_stage_dtos_keep_technical_paths_private_and_no_manual_acceptance(): void
    {
        $workflow = $this->workflow(); $service = app(LabWorkflows::class);
        $queued = $service->dto($workflow);
        $this->assertNull($queued['items'][0]['result']);
        $this->assertNull($queued['completedAt']);
        $this->assertSame('Preparing', $queued['items'][0]['stageLabel']);
        $service->stop($workflow, 'fixture_gate', 'This candidate requires review.');
        $held = $service->dto($workflow->fresh());
        $this->assertSame(1, $held['counts']['needsReview']);
        $this->assertFalse($held['items'][0]['automation']['manualAcceptance']);
        $this->assertFalse($held['items'][0]['automation']['crossViewVerified']);
        $this->assertSame(0, $held['items'][0]['automation']['operatorInterventions']);
        $this->assertArrayNotHasKey('policy', $held);
        $this->assertArrayNotHasKey('source', $held['items'][0]);
        $this->assertArrayNotHasKey('data', $held['items'][0]);
        $cancelled = $service->dto($service->cancel($workflow->fresh()));
        $fixtures = ['fixtureOnly' => true, 'providerCalls' => 0, 'queued' => ['data' => $queued],
            'needsReview' => ['data' => $held], 'cancelled' => ['data' => $cancelled],
            'index' => ['data' => ['capabilities' => app(\App\Services\IguideLab\LabWorkflowPolicy::class)->capabilities(), 'workflows' => [$queued, $held]]]];
        if ($path = getenv('WORKFLOW_DTO_FIXTURE_PATH')) file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        Http::assertNothingSent();
    }

    public function test_stable_reserved_child_recovers_after_dispatch_before_parent_attachment(): void
    {
        $workflow = $this->workflow(); $service = app(LabWorkflows::class);
        $service->process($workflow); $analysis = IguideLabAnalysis::sole();
        $step = IguideLabWorkflowStep::sole(); $step->update(['child_id' => null, 'status' => 'reserved']);
        $service->process($workflow->fresh());
        $this->assertSame(1, IguideLabAnalysis::count()); $this->assertSame($analysis->id, $step->fresh()->child_id);
        $this->assertSame($step->request_id, $analysis->request_id); $this->assertSame(true, $analysis->data['workflowInvocation']['automaticGeneration']);
        $this->assertSame(0, IguideLabRun::count()); Http::assertNothingSent();
    }

    public function test_cancel_blocks_later_paid_claim_and_retains_preexisting_child(): void
    {
        $workflow = $this->workflow(); $service = app(LabWorkflows::class); $service->process($workflow);
        $analysis = IguideLabAnalysis::sole(); $service->cancel($workflow->fresh());
        $called = false;
        try { app(LabWorkflowInvocation::class)->claim($analysis->data['workflowInvocation'], $analysis->project_id, $analysis->request_id, 'analysis', function () use (&$called) { $called = true; }); $this->fail('Cancelled claim passed.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $this->assertFalse($called); $this->assertSame(1, IguideLabAnalysis::count());
        $service->process($workflow->fresh()); $this->assertSame(0, IguideLabRun::count()); Http::assertNothingSent();
    }

    public function test_source_drift_stops_before_any_child_and_unsupported_recipe_is_explicit(): void
    {
        $workflow = $this->workflow(); Storage::disk(LabService::DISK)->put($this->case->source_path, 'changed');
        app(LabWorkflows::class)->process($workflow);
        $this->assertSame('needs_review', $workflow->fresh()->status); $this->assertSame(0, IguideLabWorkflowStep::count());
        Http::assertNothingSent();
    }
    public function test_unsupported_preset_is_not_sent_to_a_generic_model(): void
    {
        $workflow = $this->workflow($this->body('staging')); app(LabWorkflows::class)->process($workflow);
        $dto = app(LabWorkflows::class)->dto($workflow->fresh());
        $this->assertSame('recipe_unavailable', $dto['items'][0]['reviewReasons'][0]['code']);
        $this->assertSame('needs_review', $dto['items'][0]['automation']['qualityStatus']);
        $this->assertFalse($dto['items'][0]['automation']['manualAcceptance']); $this->assertSame(0, IguideLabAnalysis::count());
    }
    public function test_ambiguous_analysis_is_not_replayed_or_followed_by_an_image(): void
    {
        $workflow = $this->workflow(); $service = app(LabWorkflows::class); $service->process($workflow);
        IguideLabAnalysis::sole()->update(['status' => 'needs_attention', 'error' => 'unknown transport']);
        $service->process($workflow->fresh()); $service->process($workflow->fresh());
        $this->assertSame('needs_review', $workflow->fresh()->status); $this->assertSame(1, IguideLabAnalysis::count()); $this->assertSame(0, IguideLabRun::count());
        Http::assertNothingSent();
    }
    public function test_machine_invocation_cannot_change_reserved_input_or_exceed_attempt_bound(): void
    {
        $workflow = $this->workflow(); $item = IguideLabWorkflowItem::sole(); $steps = app(LabWorkflowSteps::class);
        $input = ['caseId' => $this->case->id, 'preset' => 'twilight', 'instructions' => 'Reserved'];
        $step = $steps->reserve($item, 'analysis-1', 'analysis', $input);
        try { app(LabAnalyses::class)->start($this->project, [...$input, 'requestId' => $step->request_id, 'instructions' => 'Changed'], $steps->invocation($step)); $this->fail('Changed input passed.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        $steps->reserve($item, 'analysis-2', 'analysis', $input);
        try { $steps->reserve($item, 'analysis-3', 'analysis', $input); $this->fail('Unbounded planning passed.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        $this->assertSame(0, IguideLabAnalysis::count());
    }
    public function test_twenty_items_advance_only_one_child_per_tick_and_keep_recovery_identity(): void
    {
        $body = $this->body(); $body['items'] = [];
        for ($i = 0; $i < 20; $i++) $body['items'][] = ['caseId' => $this->case->id, 'preset' => 'twilight', 'instructions' => 'Explicit lighting variant '.$i];
        $workflow = $this->workflow($body); $service = app(LabWorkflows::class);
        $this->assertSame(20, IguideLabWorkflowItem::count());
        $service->process($workflow); $first = IguideLabAnalysis::sole();
        for ($i = 0; $i < 3; $i++) $service->process($workflow->fresh());
        $this->assertSame(1, IguideLabAnalysis::count()); $this->assertSame($first->id, IguideLabAnalysis::sole()->id);
        $first->update(['status' => 'needs_attention']); $service->process($workflow->fresh());
        $this->assertSame(1, IguideLabAnalysis::count()); // One terminal transition is still one tick.
        $service->process($workflow->fresh()); $this->assertSame(2, IguideLabAnalysis::count());
        app(LabWorkflows::class)->cancel($workflow->fresh()); $service->process($workflow->fresh());
        $this->assertSame(2, IguideLabAnalysis::count()); $this->assertSame(0, IguideLabRun::count()); Http::assertNothingSent();
    }
    public function test_completed_composition_and_evidence_are_checkpointed_while_evaluation_waits(): void
    {
        $workflow = $this->workflow(); $service = app(LabWorkflows::class); $service->process($workflow);
        $analysis = IguideLabAnalysis::sole();
        $proposal = ['generationPrompt' => 'Apply restrained blue-hour lighting and preserve the complete source sphere.', 'workflow' => ['mode' => 'whole_panorama'], 'uncertainties' => ['Same capture cannot prove cross-camera consistency.']];
        $preparedHash = hash('sha256', \App\Services\IguideLab\LabImages::prepared(Storage::disk(LabService::DISK)->get($this->case->source_path)));
        $analysis->update(['status' => 'completed', 'proposal' => $proposal, 'proposal_sha256' => \App\Services\IguideLab\LabAnalysisRecipes::hash($proposal),
            'data' => $analysis->data + ['preparedSourceSha256' => $preparedHash]]);
        $service->process($workflow->fresh()); $run = IguideLabRun::sole();
        $this->assertTrue($run->options['sourceAnalysis']['automaticGeneration']);
        $this->assertSame(1, IguideLabAnalysis::count()); // A normal measurement caveat does not force reanalysis.
        $run->update(['status' => 'completed']);
        $composite = \App\Models\IguideLabComposite::create(['project_id' => $this->project->id, 'case_id' => $this->case->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'label' => 'Local test artifact', 'status' => 'completed',
            'preview_path' => 'test', 'manifest' => []]);
        $artifacts = app(LabWorkflowArtifacts::class);
        $artifacts->shouldReceive('runHash')->andReturn(str_repeat('a', 64));
        $artifacts->shouldReceive('compose')->once()->andReturn($composite);
        $artifacts->shouldReceive('verifyComposite')->andReturnNull();
        $artifacts->shouldReceive('evidence')->once()->andReturn(['context' => ['mechanicalQa' => ['passed' => true]]]);
        $this->mock(\App\Services\IguideLab\LabWorkflowEvaluations::class, function ($mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('start')->once()->andReturnUsing(fn ($step) => \App\Models\IguideLabWorkflowEvaluation::create([
                'project_id' => $step->project_id, 'step_id' => $step->id, 'request_id' => $step->request_id,
                'request_hash' => $step->input_sha256, 'status' => 'queued', 'data' => ['workflowInvocation' => app(LabWorkflowSteps::class)->invocation($step)]]));
        });
        $service->process($workflow->fresh());
        $this->assertSame(0, \App\Models\IguideLabWorkflowEvaluation::count()); // Composition and evidence use separate ticks.
        $service->process($workflow->fresh()); $service->process($workflow->fresh()); $service->process($workflow->fresh());
        $this->assertSame(1, \App\Models\IguideLabWorkflowEvaluation::count()); $this->assertSame('checking', $workflow->fresh()->status);
        $this->assertSame(1, IguideLabRun::count()); Http::assertNothingSent();
    }
    public function test_committed_analysis_without_attachment_or_dispatch_is_reconciled_once_per_minute(): void
    {
        $workflow = $this->workflow(); $service = app(LabWorkflows::class); $service->process($workflow);
        $analysis = IguideLabAnalysis::sole(); $step = IguideLabWorkflowStep::sole();
        $step->update(['child_id' => null, 'status' => 'reserved']); Queue::fake();
        $service->process($workflow->fresh()); Queue::assertNothingPushed(); // Normal queue grace period.
        $this->travel(61)->seconds();
        $service->process($workflow->fresh()); $service->process($workflow->fresh());
        Queue::assertPushed(\App\Jobs\ProcessIguideLabAnalysis::class, 1);
        $this->assertSame($analysis->id, $step->fresh()->child_id);
        $this->assertSame($step->request_id, $analysis->fresh()->request_id);
        $this->assertSame(1, IguideLabAnalysis::count()); Http::assertNothingSent();
    }
    private function queuedFixture(string $kind): array
    {
        $workflow = $this->workflow(); $item = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole();
        $step = app(LabWorkflowSteps::class)->reserve($item, 'recover-'.$kind, $kind, ['caseId' => $this->case->id, 'preset' => 'twilight']);
        $invocation = app(LabWorkflowSteps::class)->invocation($step);
        $base = ['project_id' => $this->project->id, 'request_id' => $step->request_id, 'request_hash' => $step->input_sha256, 'status' => 'queued'];
        $child = match ($kind) {
            'analysis' => IguideLabAnalysis::create($base + ['case_id' => $this->case->id, 'source_sha256' => $this->case->source_sha256,
                'settings' => ['preset' => 'twilight'], 'data' => ['workflowInvocation' => $invocation]]),
            'image' => IguideLabRun::create($base + ['case_id' => $this->case->id, 'model_id' => 'gpt-image-2.6-sunburst', 'provider' => 'openai',
                'preset' => 'twilight', 'prompt' => 'Fixture', 'options' => ['workflowInvocation' => $invocation]]),
            'evaluation' => \App\Models\IguideLabWorkflowEvaluation::create($base + ['step_id' => $step->id, 'data' => ['workflowInvocation' => $invocation]]),
        };
        $child->forceFill(['created_at' => now()->subMinutes(2)])->save();
        return [$workflow, $step, $child];
    }
    public function test_all_three_never_submitted_child_types_reuse_the_original_job_identity(): void
    {
        $service = app(LabWorkflows::class); $method = new \ReflectionMethod($service, 'reconcileQueuedChild');
        foreach (['analysis' => \App\Jobs\ProcessIguideLabAnalysis::class, 'image' => \App\Jobs\ProcessIguideLabRun::class, 'evaluation' => \App\Jobs\ProcessIguideLabWorkflowEvaluation::class] as $kind => $job) {
            [, $step, $child] = $this->queuedFixture($kind); Queue::fake();
            $method->invoke($service, $step, $child); $method->invoke($service, $step->fresh(), $child->fresh());
            Queue::assertPushed($job, 1); $this->assertSame($step->request_id, $child->fresh()->request_id);
        }
        $this->assertSame(0, $this->project->fresh()->used_runs); Http::assertNothingSent();
    }
    public function test_submitted_or_ambiguous_children_never_qualify_for_queue_recovery(): void
    {
        $service = app(LabWorkflows::class); $method = new \ReflectionMethod($service, 'reconcileQueuedChild');
        foreach (['analysis', 'image', 'evaluation'] as $kind) {
            [, $step, $child] = $this->queuedFixture($kind); Queue::fake();
            foreach (['submitting', 'processing', 'received', 'needs_attention', 'completed'] as $status) {
                $child->update(['status' => $status]); $method->invoke($service, $step, $child);
            }
            $child->update(['status' => 'queued', 'started_at' => now()]); $method->invoke($service, $step, $child);
            $child->update(['started_at' => null, 'provider_request_id' => 'known-receipt']); $method->invoke($service, $step, $child);
            $child->update(['provider_request_id' => null]);
            if ($kind === 'image') { $options = $child->options; $options['providerAttempts'] = [['status' => 'unknown']]; $child->update(['options' => $options]); }
            else $child->update(['provider_attempts' => [['status' => 'unknown']]]);
            $method->invoke($service, $step, $child); Queue::assertNothingPushed();
        }
        Http::assertNothingSent();
    }
    public function test_cancel_source_drift_and_foreign_child_block_queue_recovery(): void
    {
        $service = app(LabWorkflows::class); $method = new \ReflectionMethod($service, 'reconcileQueuedChild');
        [$workflow, $step, $child] = $this->queuedFixture('analysis'); Queue::fake();
        $wrong = clone $child; $wrong->request_id = (string) Str::uuid();
        try { $method->invoke($service, $step, $wrong); $this->fail('Foreign child dispatched.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        $bytes = Storage::disk(LabService::DISK)->get($this->case->source_path); Storage::disk(LabService::DISK)->put($this->case->source_path, 'changed');
        try { $method->invoke($service, $step, $child); $this->fail('Changed source dispatched.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        Storage::disk(LabService::DISK)->put($this->case->source_path, $bytes); $service->cancel($workflow);
        try { $method->invoke($service, $step, $child); $this->fail('Cancelled child dispatched.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        Queue::assertNothingPushed(); Http::assertNothingSent();
    }
}
