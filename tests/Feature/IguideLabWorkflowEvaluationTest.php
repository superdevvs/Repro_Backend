<?php
namespace Tests\Feature;

use App\Models\IguideLabComposite;
use App\Models\IguideLabProject;
use App\Models\IguideLabWorkflowEvaluation;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabStorage;
use App\Services\IguideLab\LabVisualQualityProtocol as Protocol;
use App\Services\IguideLab\LabWorkflowEvaluations;
use App\Services\IguideLab\LabWorkflowSteps;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IguideLabWorkflowEvaluationTest extends TestCase
{
    use RefreshDatabase;
    private string $key;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        $this->key = tempnam(sys_get_temp_dir(), 'eval-key-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback', 'iguide-lab.openai_primary_key_path' => $this->key]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        $disk = Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK));
        $disk->makeDirectory(''); $disk->setVisibility('', 'private');
    }
    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }
    private function fixture(string $instructions = '', bool $nativePairs = false): array
    {
        $project = IguideLabProject::create(['name' => 'Evaluation', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $png = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $case = app(LabService::class)->addCase($project, UploadedFile::fake()->createWithContent('source.png', $png), ['label' => 'Source', 'projection' => 'equirectangular']);
        $workflow = app(LabWorkflows::class)->start($project, ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $case->id, 'preset' => 'twilight', 'instructions' => $instructions]]]);
        $item = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole();
        $id = (string) Str::uuid(); $path = 'iguide-lab/'.$project->id.'/composites/'.$id.'-preview.png';
        Storage::disk(LabService::DISK)->put($path, $png, ['visibility' => 'private']);
        $composite = IguideLabComposite::create(['id' => $id, 'project_id' => $project->id, 'case_id' => $case->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('c', 64), 'label' => 'Fixture', 'status' => 'completed', 'preview_path' => $path,
            'manifest' => ['preview' => ['sha256' => hash('sha256', $png), 'width' => 2048, 'height' => 1024]]]);
        app(LabStorage::class)->prepareWorkflow($project->id, $item->id);
        $context = ['sourceSha256' => $case->source_sha256, 'candidateSha256' => hash('sha256', $png), 'intentSha256' => str_repeat('c', 64),
            'evidenceManifestSha256' => str_repeat('d', 64), 'projection' => 'equirectangular', 'preset' => 'twilight', 'style' => null,
            'intent' => 'Shift the existing source to blue hour.', 'protectedFeatures' => ['Existing building and floor detail.'],
            'opticalDependencies' => [], 'sourceCaveats' => [], 'reviewRequirements' => ['Retain source floor detail.'], 'mechanicalQa' => ['passed' => true, 'blockingReasons' => []], 'views' => []];
        $assets = [];
        foreach (Protocol::VIEW_IDS as $offset => $name) {
            [$width,$height]=$nativePairs?[2048,1024]:[128,64];
            $bytes = (string) \Intervention\Image\ImageManager::gd()->create($width, $height)->fill(sprintf('%02x8888', 30 + 20 * $offset))->toPng();
            $path = 'iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/'.$name.'.png'; Storage::disk(LabService::DISK)->put($path, $bytes, ['visibility' => 'private']);
            $context['views'][] = ['id' => $name, 'sha256' => hash('sha256', $bytes), 'width' => $width, 'height' => $height];
            $assets[] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
        }
        $intent = ['sourceSha256' => $item->source['sha256'], 'preset' => $item->preset, 'style' => $item->style, 'instructions' => $item->instructions];
        $context['intent'] = json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $context['intentSha256'] = \App\Services\IguideLab\LabAnalysisRecipes::hash($intent);
        $manifestPath = 'iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/report.json';
        $manifestBytes = json_encode(['sourceSha256' => $context['sourceSha256'], 'candidateSha256' => $context['candidateSha256']], JSON_THROW_ON_ERROR);
        Storage::disk(LabService::DISK)->put($manifestPath, $manifestBytes, ['visibility' => 'private']);
        $context['evidenceManifestSha256'] = hash('sha256', $manifestBytes);
        $evidence = ['compositeId' => $composite->id, 'manifestPath' => $manifestPath, 'context' => $context, 'assets' => $assets];
        $step = app(LabWorkflowSteps::class)->reserve($item, 'evaluation-1', 'evaluation', $evidence);
        return [$project, $item, $step, $composite];
    }
    private function response(array $context): array
    {
        $checks = [];
        foreach (Protocol::CHECKS as $key) $checks[$key] = ['status' => 'pass', 'evidenceViewIds' => [match ($key) { 'seam' => 'wrap', 'poles' => 'poles', default => 'front' }], 'reason' => 'Matched evidence preserves the source.'];
        $evaluation = array_intersect_key($context, array_flip(['sourceSha256', 'candidateSha256', 'intentSha256', 'evidenceManifestSha256']))
            + ['protocolVersion' => Protocol::VERSION, 'checks' => $checks, 'unresolvedDependencies' => [], 'recommendedAction' => 'none', 'summary' => 'The paired evidence passes.'];
        return ['id' => 'resp_fixture_quality', 'model' => Protocol::MODEL, 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            'output' => [['type' => 'reasoning', 'private' => 'PRIVATE_REASONING'], ['type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => json_encode($evaluation)]]]]];
    }
    public function test_evaluation_has_its_own_durable_receipt_and_ambiguous_attempt_is_not_repeated(): void
    {
        [$project, , $step] = $this->fixture(); $service = app(LabWorkflowEvaluations::class); $record = $service->start($step);
        $this->assertSame($record->id, $service->start($step)->id);
        $count = 0; Http::fake(function () use (&$count) { $count++; throw new \Illuminate\Http\Client\ConnectionException('unknown'); });
        $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $count); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertSame('unknown', $record->fresh()->provider_attempts[0]['status']);
        $this->assertSame(0, \App\Models\IguideLabAnalysis::count()); $this->assertSame(0, $project->fresh()->used_runs);
    }
    public function test_explicit_quota_only_fallback_preserves_payload_and_never_creates_human_acceptance(): void
    {
        [, $item, $step] = $this->fixture(); $service = app(LabWorkflowEvaluations::class); $record = $service->start($step);
        Http::fakeSequence('api.openai.com/v1/responses')->push(['error' => ['code' => 'insufficient_quota']], 429, ['x-request-id' => 'quota'])
            ->push($this->response($step->input['context']), 200, ['x-request-id' => 'evaluation']);
        $service->process($record); $service->process($record->fresh()); $record->refresh();
        $this->assertSame('completed', $record->status); $this->assertSame(['lab-primary', 'dashboard-fallback'], array_column($record->provider_attempts, 'credentialSource'));
        $requests = Http::recorded(); $this->assertSame($requests[0][0]->data(), $requests[1][0]->data()); Http::assertSentCount(2);
        $this->assertSame('passed_automated_checks', $record->result['qualityStatus']); $this->assertFalse($record->result['manualAcceptance']);
        $this->assertStringNotContainsString('PRIVATE_REASONING', json_encode($record->result));
        $this->assertSame(0, \App\Models\IguideLabReview::count());
        app(LabWorkflowSteps::class)->attach($step,$record->id);
        $this->assertSame($record->result,$service->verifiedCompleted($item,$record));
    }

    public function test_completed_quality_receipt_and_payload_are_revalidated_before_repair_authority(): void
    {
        [, $item, $step] = $this->fixture(); $service=app(LabWorkflowEvaluations::class);$record=$service->start($step);
        Http::fakeSequence('api.openai.com/v1/responses')->push($this->response($step->input['context']),200,['x-request-id'=>'quality-primary']);
        $service->process($record);$record->refresh();app(LabWorkflowSteps::class)->attach($step,$record->id);
        $this->assertSame($record->result,$service->verifiedCompleted($item,$record));
        $disk=Storage::disk(LabService::DISK);$savedData=$record->data;$path=$savedData['responseReceipt']['path'];$bytes=$disk->get($path);$savedResult=$record->result;
        foreach(['missing','raw','path','response-id','provider-id','body','parsed-result'] as $variant){
            $data=$savedData;$result=$savedResult;
            match($variant){
                'missing'=>$disk->delete($path),'raw'=>$disk->put($path,'{}'),
                'path'=>$data['responseReceipt']['path']='other/response.json',
                'response-id'=>$data['responseReceipt']['responseId']='another-response',
                'provider-id'=>$data['responseReceipt']['providerRequestId']='another-provider',
                'body'=>$data['providerBodySha256']=str_repeat('f',64),
                'parsed-result'=>$result['summary']='Tampered result.',
            };
            $record->update(['data'=>$data,'result'=>$result]);
            try{$service->verifiedCompleted($item,$record->fresh());$this->fail('Accepted '.$variant);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
            $disk->put($path,$bytes);$record->update(['data'=>$savedData,'result'=>$savedResult]);
        }
        $this->assertSame($savedResult,$service->verifiedCompleted($item,$record->fresh()));Http::assertSentCount(1);
    }

    public function test_failed_native_pairs_and_full_feedback_reach_repair_analysis_without_public_private_fields(): void
    {
        [$project,$item,$step]=$this->fixture('Visible twilight, same source geometry.',true);
        $workflow=\App\Models\IguideLabWorkflow::findOrFail($item->workflow_id);$policy=$workflow->policy;
        $policy['lightingRepair']=app(\App\Services\IguideLab\LabLightingRepairs::class)->snapshot();$workflow->update(['policy'=>$policy]);
        $raw=$this->response($step->input['context']);$text=json_decode($raw['output'][1]['content'][0]['text'],true);
        $text['checks']['optical_dependencies']=['status'=>'fail','evidenceViewIds'=>['front','back'],'reason'=>str_repeat('Direct and reflected surfaces differ. ',15).' FINAL_OPTICAL_REASON'];
        $text['recommendedAction']='replan';$text['unresolvedDependencies']=[str_repeat('Preserve matching reflected source material. ',10).' END_DEPENDENCY'];
        $raw['output'][1]['content'][0]['text']=json_encode($text);
        $service=app(LabWorkflowEvaluations::class);$record=$service->start($step);
        Http::fakeSequence('api.openai.com/v1/responses')->push($raw,200,['x-request-id'=>'quality-repair']);$service->process($record);$record->refresh();app(LabWorkflowSteps::class)->attach($step,$record->id);
        $reference=app(\App\Services\IguideLab\LabWorkflowRepairContext::class)->reference($item,$record);
        $input=['caseId'=>$item->case_id,'preset'=>$item->preset,'style'=>$item->style,'instructions'=>$item->instructions,'repairContext'=>$reference];
        $analysisStep=app(LabWorkflowSteps::class)->reserve($item,'analysis-2','analysis',$input);
        $this->mock(\App\Services\IguideLab\LabAnalysisViews::class)->shouldReceive('ready')->andReturn(true);
        $analyses=app(\App\Services\IguideLab\LabAnalyses::class);
        $analysis=$analyses->start($project,$input+['requestId'=>$analysisStep->request_id],app(LabWorkflowSteps::class)->invocation($analysisStep));
        $contextService=app(\App\Services\IguideLab\LabWorkflowRepairContext::class);
        $prepared=$contextService->prepare($analysis,\App\Models\IguideLabCase::findOrFail($item->case_id));
        $this->assertSame($prepared,$contextService->prepare($analysis,\App\Models\IguideLabCase::findOrFail($item->case_id)));
        $this->assertCount(7,$prepared['views']);$this->assertSame($record->result,$prepared['repairContextManifest']['feedback']);
        $this->assertStringContainsString('FINAL_OPTICAL_REASON',json_encode($prepared));
        $this->assertStringContainsString('END_DEPENDENCY',json_encode($prepared));
        $analysis->update(['data'=>array_merge($analysis->data,$prepared)]);$dto=$analyses->dto($analysis->fresh());
        $this->assertSame([],$dto['views']);$this->assertArrayNotHasKey('repairContext',$dto);
        $this->assertSame('failed-native-paired7/1',$dto['settings']['viewRecipe']);
        if($path=getenv('REPAIR_ANALYSIS_DTO_FIXTURE_PATH'))file_put_contents($path,json_encode(['data'=>$dto],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        $disk=Storage::disk(LabService::DISK);$disk->put($record->data['responseReceipt']['path'],'{}');
        try{$contextService->prepare($analysis,\App\Models\IguideLabCase::findOrFail($item->case_id));$this->fail('Changed authority accepted.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        Http::assertSentCount(1);
    }
    public function test_changed_candidate_or_evidence_stops_before_provider_submission(): void
    {
        [, , $step, $composite] = $this->fixture(); $service = app(LabWorkflowEvaluations::class); $record = $service->start($step);
        Storage::disk(LabService::DISK)->put($composite->preview_path, 'changed');
        $service->process($record); $this->assertSame('needs_attention', $record->fresh()->status); Http::assertNothingSent();
    }
    public function test_cancel_after_primary_quota_rejection_blocks_fallback_claim(): void
    {
        [, $item, $step] = $this->fixture(); $service = app(LabWorkflowEvaluations::class); $record = $service->start($step);
        $count = 0;
        Http::fake(function () use ($item, &$count) {
            $count++;
            app(LabWorkflows::class)->cancel(\App\Models\IguideLabWorkflow::findOrFail($item->workflow_id));
            return Http::response(['error' => ['code' => 'insufficient_quota']], 429, ['x-request-id' => 'quota-before-cancel']);
        });
        $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $count); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertSame(['quota_rejected'], array_column($record->fresh()->provider_attempts, 'status'));
        $this->assertSame(['lab-primary'], array_column($record->fresh()->provider_attempts, 'credentialSource'));
    }
    public function test_foreign_evidence_path_is_rejected_even_with_matching_hash(): void
    {
        [$project, $item, $step] = $this->fixture(); $evidence = $step->input; $path = 'iguide-lab/'.$project->id.'/cases/not-owned.png';
        Storage::disk(LabService::DISK)->put($path, Storage::disk(LabService::DISK)->get($evidence['assets'][0]['path']));
        $evidence['assets'][0]['path'] = $path;
        try { app(LabWorkflowEvaluations::class)->payload($evidence, $project->id, $item->id); $this->fail('Foreign evidence accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        Http::assertNothingSent();
    }
    public function test_planner_substituted_intent_is_rejected_before_provider(): void
    {
        [$project, $item, $step] = $this->fixture(); $evidence = $step->input;
        $evidence['context']['intent'] = 'An easier but different edit.';
        $evidence['context']['intentSha256'] = hash('sha256', $evidence['context']['intent']);
        try { app(LabWorkflowEvaluations::class)->payload($evidence, $project->id, $item->id); $this->fail('Substituted original intent accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        Http::assertNothingSent();
    }

    public function test_staging_intent_binds_original_user_text_and_fixed_server_constraints(): void
    {
        [$project,$item,$step]=$this->fixture('Use muted earth tones.');$item->update(['preset'=>'staging','style'=>'modern']);
        $workflow=\App\Models\IguideLabWorkflow::findOrFail($item->workflow_id);
        $workflow->update(['policy'=>$workflow->policy+['stagingAssessment'=>\App\Services\IguideLab\LabStagingAssessmentPolicy::snapshot(),
            'stagingWorkflow'=>app(\App\Services\IguideLab\LabStagingWorkflow::class)->snapshot()]]);
        $service=app(LabWorkflowEvaluations::class);$intent=$service->intent($item);$evidence=$step->input;
        $this->assertSame('Use muted earth tones.',$intent['instructions']);
        $this->assertSame(\App\Services\IguideLab\LabStagingArtifacts::REVIEW_CONSTRAINTS,$intent['fixedRecipeConstraints']);
        $evidence['context']['preset']='staging';$evidence['context']['style']='modern';
        $evidence['context']['intent']=json_encode($intent,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $evidence['context']['intentSha256']=\App\Services\IguideLab\LabAnalysisRecipes::hash($intent);
        $this->assertIsArray($service->payload($evidence,$project->id,$item->id));
        unset($intent['fixedRecipeConstraints']);
        $evidence['context']['intent']=json_encode($intent,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $evidence['context']['intentSha256']=\App\Services\IguideLab\LabAnalysisRecipes::hash($intent);
        try{$service->payload($evidence,$project->id,$item->id);$this->fail('Missing fixed constraints accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        Http::assertNothingSent();
    }

    public function test_evidence_rejects_wrong_or_changed_scene_proposal_before_local_processing(): void
    {
        $runtime = new class extends \App\Services\IguideLab\LabWorkflowArtifacts {
            public function snapshot(): array { return ['recipeVersion' => 'fixture', 'adapterSha256' => str_repeat('a', 64)]; }
        };
        $runtime->validateSnapshot(['adapterSha256' => str_repeat('a', 64), 'recipeVersion' => 'fixture']);
        try { $runtime->validateSnapshot(['adapterSha256' => str_repeat('b', 64), 'recipeVersion' => 'fixture']); $this->fail('Changed code accepted.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        [$project, $item, , $composite] = $this->fixture();
        $proposal = ['summary' => 'Original source-bound proposal', 'sourceSha256' => $item->source['sha256']];
        // Match the persisted scene-proposal encoding, whose key order is preserved.
        $sha = hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR));
        $composite->manifest = $composite->manifest + ['scenePlanSha256' => $sha, 'lineage' => ['recipe' => []]];
        $values = ['project_id' => $project->id, 'case_id' => $item->case_id, 'source_sha256' => $item->source['sha256'],
            'status' => 'completed', 'proposal' => $proposal, 'proposal_sha256' => $sha];
        foreach ([['project_id' => (string) Str::uuid()], ['case_id' => (string) Str::uuid()],
            ['source_sha256' => str_repeat('f', 64)], ['proposal_sha256' => str_repeat('f', 64)], ['proposal' => ['summary' => 'Changed']]] as $changes) {
            $analysis = new \App\Models\IguideLabAnalysis(array_replace($values, $changes));
            try { (new \App\Services\IguideLab\LabWorkflowArtifacts)->evidence($item, $composite, $analysis); $this->fail('Unbound scene context accepted.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertSame(422, $error->getStatusCode()); $this->assertStringContainsString('scene proposal', $error->getMessage());
            }
        }
        $guard = new class extends \App\Services\IguideLab\LabWorkflowArtifacts {
            public function validateSnapshot(array $snapshot): void { throw new \LogicException('valid-binding-reached'); }
        };
        try { $guard->evidence($item, $composite, new \App\Models\IguideLabAnalysis($values)); $this->fail('Expected local processing boundary.'); }
        catch (\LogicException $error) { $this->assertSame('valid-binding-reached', $error->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_full_length_unicode_intent_and_all_source_caveats_fit_without_truncation(): void
    {
        $instructions = str_repeat('光/', 1000);
        [$project, $item, $step] = $this->fixture($instructions); $evidence = $step->input;
        $caveats = array_map(fn ($i) => 'Original source caveat '.$i, range(1, 12));
        $evidence['context']['sourceCaveats'] = [...$caveats, 'The scene plan has no separate optical dependency inventory.'];
        $payload = app(LabWorkflowEvaluations::class)->payload($evidence, $project->id, $item->id);
        $context = json_decode($payload['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($instructions, json_decode($context['intent'], true, flags: JSON_THROW_ON_ERROR)['instructions']);
        $this->assertLessThan(4000, mb_strlen($context['intent']));
        $this->assertSame($evidence['context']['sourceCaveats'], $context['sourceCaveats']);
        $this->assertCount(13, $context['sourceCaveats']); Http::assertNothingSent();
    }

    public function test_first_composition_requires_the_persisted_final_output_digest(): void
    {
        [$project] = $this->fixture(); $id = (string) Str::uuid();
        $path = 'iguide-lab/'.$project->id.'/runs/'.$id.'.png';
        $bytes = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $disk = Storage::disk(LabService::DISK); $disk->put($path, $bytes, ['visibility' => 'private']);
        $run = new \App\Models\IguideLabRun(['id' => $id, 'project_id' => $project->id, 'status' => 'completed',
            'output_path' => $path, 'output_sha256' => hash('sha256', $bytes)]);
        $service = new \App\Services\IguideLab\LabWorkflowArtifacts;
        $this->assertSame($run->output_sha256, $service->runHash($run));
        $changed = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('999999')->toPng();
        $disk->put($path, $changed, ['visibility' => 'private']);
        foreach ([$run->output_sha256, null] as $digest) {
            $run->output_sha256 = $digest;
            try { $service->runHash($run); $this->fail('Unverified candidate accepted before composition.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        }
        Http::assertNothingSent();
    }

    public function test_altered_fixed_evaluation_recipe_is_not_ready_before_paid_work(): void
    {
        $service = new LabWorkflowEvaluations; $this->assertTrue($service->ready());
        $original = app()->basePath(); $temp = sys_get_temp_dir().'/invalid-quality-'.Str::uuid();
        mkdir($temp.'/resources/iguide-lab/quality-recipes', 0770, true);
        file_put_contents($temp.'/resources/iguide-lab/quality-recipes/visual-quality-1.json', '{"changed":true}');
        app()->setBasePath($temp);
        try { $this->assertFalse($service->ready()); }
        finally { app()->setBasePath($original); unlink($temp.'/resources/iguide-lab/quality-recipes/visual-quality-1.json'); rmdir($temp.'/resources/iguide-lab/quality-recipes'); rmdir($temp.'/resources/iguide-lab'); rmdir($temp.'/resources'); rmdir($temp); }
        Http::assertNothingSent();
    }

    public function test_cancel_at_item_write_boundary_preserves_cancelled_item_state(): void
    {
        [, $item] = $this->fixture(); $service = new LabWorkflows; $injected = false;
        $workflow = \App\Models\IguideLabWorkflow::findOrFail($item->workflow_id);
        \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) use (&$injected, $service, $workflow) {
            if (! $injected && str_contains($sql, 'update "iguide_lab_workflow_items"') && str_contains($sql, 'exists')) {
                $injected = true; $service->cancel($workflow);
            }
        });
        (new \ReflectionMethod($service, 'save'))->invoke($service, $item, 'checking', $item->data);
        $this->assertTrue($injected); $this->assertSame('cancelled', $workflow->fresh()->status);
        $this->assertSame('cancelled', $item->fresh()->status); Http::assertNothingSent();
    }
}
