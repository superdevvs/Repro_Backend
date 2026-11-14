<?php
namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Models\IguideLabLifestyleAssessment;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabAnalysisRecipes;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabLifestyleAssessmentBridge;
use App\Services\IguideLab\LabLifestyleAssessmentContext;
use App\Services\IguideLab\LabLifestyleAssessmentPolicy;
use App\Services\IguideLab\LabLifestyleAssessmentProvider;
use App\Services\IguideLab\LabLifestyleAssessments;
use App\Services\IguideLab\LabWorkflowSteps;
use App\Services\IguideLab\LabWorkflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real durable claims/HTTP transport; Python semantics have their separate real bridge tests. */
class IguideLabLifestyleAssessmentTest extends TestCase
{
    use RefreshDatabase;
    private string $key;
    private LifestyleAssessmentContextFixture $context;
    private LifestyleAssessmentBridgeFixture $bridge;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        $this->key = tempnam(sys_get_temp_dir(), 'placement-key-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback', 'iguide-lab.openai_primary_key_path' => $this->key]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context = new LifestyleAssessmentContextFixture; $this->bridge = new LifestyleAssessmentBridgeFixture;
        $this->app->instance(LabLifestyleAssessmentContext::class, $this->context);
        $this->app->instance(LabLifestyleAssessmentBridge::class, $this->bridge);
    }
    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }
    private function fixture(string $preset = 'lifestyle'): array
    {
        $project = IguideLabProject::create(['name' => 'Machine placement', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $bytes = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $case = app(LabService::class)->addCase($project, UploadedFile::fake()->createWithContent('source.png', $bytes), ['label' => 'Original', 'projection' => 'equirectangular']);
        $workflow = app(LabWorkflows::class)->start($project, ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $case->id, 'preset' => $preset]]]);
        if ($preset === 'lifestyle') { $policy = $workflow->policy; $policy['lifestyleAssessment'] = LabLifestyleAssessmentPolicy::snapshot(); $workflow->update(['policy' => $policy]); }
        $item = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole();
        $this->context->bundle = ['context' => ['binding' => ['fixtureSourceSha256' => $case->source_sha256]],
            'assets' => [['id' => 'source_cut', 'path' => $case->source_path, 'sha256' => $case->source_sha256],
                ['id' => 'candidate_cut', 'path' => $case->source_path, 'sha256' => $case->source_sha256]]];
        $this->bridge->sourceSha = $case->source_sha256;
        return [$project, $workflow, $item, $case];
    }
    private function reserved(): array
    {
        $values = $this->fixture(); $service = app(LabLifestyleAssessments::class);
        $step = $service->reserve($values[2], (string) Str::uuid(), (string) Str::uuid());
        return [...$values, $step, $service->start($step)];
    }
    private function response(): array { return ['id' => 'resp-fixture', 'model' => 'gpt-6-astra', 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]]; }
    public function test_integral_camera_angles_survive_database_json_roundtrip_without_losing_drift_checks(): void
    {
        [, , $item] = $this->fixture();
        $this->context->bundle['context']['pose']=['yaw'=>0.0,'pitch'=>0.0,'hFov'=>90.0];
        $service=app(LabLifestyleAssessments::class); $step=$service->reserve($item,(string)Str::uuid(),(string)Str::uuid());
        $record=$service->start($step->fresh());
        $this->assertSame('queued',$record->status);
        $this->assertSame($record->id,$service->start($step->fresh())->id);
        $this->context->bundle['context']['pose']['yaw']=0.01;
        $this->expectException(HttpException::class);$service->start($step->fresh());
    }
    public function test_reservation_replay_and_known_receipt_resume_do_not_repeat_paid_submission(): void
    {
        [$project, , , , $step, $record] = $this->reserved(); $service = app(LabLifestyleAssessments::class);
        $this->assertSame($record->id, $service->start($step)->id);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(), 200, ['x-request-id' => 'fixture'])]);
        // Simulate a worker stopping after durable receipt storage, before local parsing.
        $record->update(['status' => 'submitting']);
        app(LabLifestyleAssessmentProvider::class)->assess($record, file_get_contents($record->data['payload']['path']));
        $this->assertSame('received', $record->fresh()->status);
        $service->process($record->fresh()); $service->process($record->fresh());
        $this->assertSame('completed', $record->fresh()->status); Http::assertSentCount(1);
        $this->assertSame(1, IguideLabLifestyleAssessment::count());
        $this->assertSame(0, \App\Models\IguideLabAnalysis::count());
        $this->assertSame(0, $project->fresh()->used_runs);
        $this->assertFalse($record->fresh()->result['automaticAcceptance']);
    }
    public function test_quota_fallback_preserves_exact_saved_payload_and_other_errors_do_not_retry(): void
    {
        [, , , , , $record] = $this->reserved();
        Http::fakeSequence('api.openai.com/v1/responses')->push(['error' => ['code' => 'insufficient_quota']], 429)->push($this->response(), 200);
        app(LabLifestyleAssessments::class)->process($record);
        $this->assertSame('received', $record->fresh()->status);
        app(LabLifestyleAssessments::class)->process($record->fresh());
        $this->assertSame('completed', $record->fresh()->status); Http::assertSentCount(2);
        $requests = Http::recorded(); $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertSame(file_get_contents($record->data['payload']['path']), $requests[0][0]->body());
        $this->assertSame(['lab-primary', 'dashboard-fallback'], array_column($record->fresh()->provider_attempts, 'credentialSource'));
    }
    public function test_cancellation_after_quota_rejection_blocks_fallback_claim(): void
    {
        [, $workflow, , , , $record] = $this->reserved(); $count = 0;
        Http::fake(function () use ($workflow, &$count) { $count++; app(LabWorkflows::class)->cancel($workflow); return Http::response(['error' => ['code' => 'insufficient_quota']], 429); });
        $service = app(LabLifestyleAssessments::class); $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $count); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertSame(['quota_rejected'], array_column($record->fresh()->provider_attempts, 'status'));
    }
    public function test_candidate_context_drift_after_quota_blocks_placement_fallback(): void
    {
        [,,,,,$record]=$this->reserved();
        Http::fake(function(){ $this->context->bundle['context']['candidateSha256']=str_repeat('e',64);return Http::response(['error'=>['code'=>'insufficient_quota']],429); });
        app(LabLifestyleAssessments::class)->process($record);
        Http::assertSentCount(1);$this->assertSame('needs_attention',$record->fresh()->status);
        $this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
    }
    public function test_compiled_payload_drift_after_quota_blocks_placement_fallback(): void
    {
        [,,,,,$record]=$this->reserved();$path=$record->data['payload']['path'];
        Http::fake(function()use($path){file_put_contents($path,'changed payload');return Http::response(['error'=>['code'=>'insufficient_quota']],429);});
        app(LabLifestyleAssessments::class)->process($record);
        Http::assertSentCount(1);$this->assertSame('needs_attention',$record->fresh()->status);
        $this->assertSame(['quota_rejected'],array_column($record->fresh()->provider_attempts,'status'));
    }
    public function test_ambiguous_submission_is_retained_without_retry(): void
    {
        [, , , , , $record] = $this->reserved(); $count = 0;
        Http::fake(function () use (&$count) { $count++; throw new \Illuminate\Http\Client\ConnectionException('fixture lost receipt'); });
        $service = app(LabLifestyleAssessments::class); $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $count); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertSame('unknown', $record->fresh()->provider_attempts[0]['status']);
    }
    public function test_changed_context_stops_before_submission_and_completed_receipt_association(): void
    {
        [, , , , , $record] = $this->reserved(); $this->context->reject = true;
        app(LabLifestyleAssessments::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); Http::assertNothingSent();
    }
    public function test_machine_association_rechecks_authority_after_receipt_and_rejects_result_tampering(): void
    {
        [, , , , , $record] = $this->reserved(); $service = app(LabLifestyleAssessments::class);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]); $service->process($record); $service->process($record->fresh());
        $steps = $service->reserveSegmentationSteps($record->fresh());
        $trusted = $service->forSegmentation($steps['source-floor']);
        $this->assertSame($steps['source-floor']->request_id, $trusted['boundPlan']['requestId']);
        $this->assertSame($record->id, $trusted['assessmentId']);
        $this->context->reject = true;
        try { $service->associate($steps['source-floor'], ['requestId' => $steps['source-floor']->request_id]); $this->fail('Drifted source associated.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->context->reject = false; $record->refresh(); file_put_contents($record->data['resultAsset']['path'], '{"tampered":true}');
        try { $service->forSegmentation($steps['source-floor']); $this->fail('Changed assessment authorized a call.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        Http::assertSentCount(1);
    }
    public function test_integral_floor_box_result_survives_database_json_roundtrip_but_value_drift_is_rejected(): void
    {
        [, , , , , $record] = $this->reserved(); $service = app(LabLifestyleAssessments::class);
        $this->bridge->floorBoundaryFloats = true;
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        $service->process($record); $service->process($record->fresh()); $record->refresh();
        $this->assertSame('completed', $record->status);
        $path = $record->data['resultAsset']['path'];
        $parsed = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0.0, $parsed['floorSelection']['box']['xMin']);
        $this->assertSame(0, $record->result['floorSelection']['box']['xMin']);
        $this->assertSame(hash_file('sha256', $path), $record->result_sha256);
        $steps = $service->reserveSegmentationSteps($record);
        $this->assertSame($record->id, $service->forSegmentation($steps['source-floor'])['assessmentId']);
        $result = $record->result; $result['floorSelection']['box']['xMin'] = 0.01;
        $record->update(['result' => $result]);
        try { $service->forSegmentation($steps['source-floor']); $this->fail('Changed floor box authorized segmentation.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->assertSame(hash_file('sha256', $path), $record->fresh()->result_sha256);
        Http::assertSentCount(1);
    }
    public function test_distinct_policy_adds_only_the_lifestyle_third_analysis_and_bounds_image_calls(): void
    {
        [, , $item] = $this->fixture(); $steps = app(LabWorkflowSteps::class);
        for ($n = 0; $n < 3; $n++) $steps->reserve($item, 'plan-'.$n, 'analysis', ['n' => $n]);
        try { $steps->reserve($item, 'plan-3', 'analysis', []); $this->fail('Fourth lifestyle analysis reserved.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $steps->reserve($item, 'image-1', 'image', []);
        try { $steps->reserve($item, 'image-2', 'image', []); $this->fail('Second lifestyle image reserved.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        [, , $lighting] = $this->fixture('twilight');
        $steps->reserve($lighting, 'plan-1', 'analysis', []); $steps->reserve($lighting, 'plan-2', 'analysis', []);
        try { $steps->reserve($lighting, 'plan-3', 'analysis', []); $this->fail('Lighting budget widened.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        Http::assertNothingSent();
    }
    public function test_completed_receipt_files_provider_ids_and_execution_are_rechecked_before_segmentation(): void
    {
        [, , , , , $record] = $this->reserved(); $service = app(LabLifestyleAssessments::class);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(), 200, ['x-request-id' => 'fixture-request'])]);
        $service->process($record); $service->process($record->fresh()); $record->refresh();
        $steps = $service->reserveSegmentationSteps($record); $step = $steps['source-floor'];
        $data = $record->data; $attempts = $record->provider_attempts; $disk = Storage::disk(LabService::DISK);
        $reject = function () use ($service, $step): void {
            try { $service->forSegmentation($step); $this->fail('Changed paid receipt authorized segmentation.'); }
            catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        };
        $path = $data['providerReceipt']['response']['path']; $bytes = $disk->get($path);
        $disk->delete($path); $reject(); $disk->put($path, $bytes.' '); $reject(); $disk->put($path, $bytes);
        $record->update(['provider_request_id' => 'changed-provider']); $reject(); $record->update(['provider_request_id' => 'fixture-request']);
        $changed = $data; $changed['providerReceipt']['providerResponseId'] = 'changed-response'; $record->update(['data' => $changed]); $reject();
        $changed = $data; $changed['execution']['requestPayloadSha256'] = str_repeat('a', 64); $record->update(['data' => $changed]); $reject();
        $record->update(['data' => $data]);
        $changedAttempts = $attempts; $changedAttempts[0]['status'] = 'unknown'; $record->update(['provider_attempts' => $changedAttempts]); $reject();
        $record->update(['provider_attempts' => $attempts]);
        $payload = $data['payload']['path']; $savedPayload = file_get_contents($payload); file_put_contents($payload, $savedPayload.' '); $reject(); file_put_contents($payload, $savedPayload);
        $this->assertSame($record->id, $service->forSegmentation($step)['assessmentId']);
        Http::assertSentCount(1);
    }
    public function test_completed_quota_fallback_receipt_remains_valid_but_nonquota_history_is_rejected(): void
    {
        [, , , , , $record] = $this->reserved(); $service = app(LabLifestyleAssessments::class);
        Http::fakeSequence('api.openai.com/v1/responses')->push(['error' => ['code' => 'insufficient_quota']], 429)->push($this->response(), 200);
        $service->process($record); $service->process($record->fresh()); $record->refresh();
        $steps = $service->reserveSegmentationSteps($record);
        $this->assertSame($record->id, $service->forSegmentation($steps['source-floor'])['assessmentId']);
        $attempts = $record->provider_attempts; $attempts[0]['status'] = 'unknown'; $record->update(['provider_attempts' => $attempts]);
        try { $service->forSegmentation($steps['source-floor']); $this->fail('Nonquota history authorized fallback result.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        Http::assertSentCount(2);
    }
    public function test_missing_runtime_and_foreign_output_path_fail_closed(): void
    {
        [$project, , $item] = $this->fixture(); $this->bridge->available = false;
        try { app(LabLifestyleAssessments::class)->reserve($item, (string) Str::uuid(), (string) Str::uuid()); $this->fail('Unavailable runtime reserved.'); }
        catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
        $path = tempnam(sys_get_temp_dir(), 'foreign-assessment-'); file_put_contents($path, '{}');
        try { (new LabLifestyleAssessmentBridge)->output($project->id, ['outputs' => ['payload' => ['path' => $path, 'bytes' => 2, 'sha256' => hash('sha256', '{}')]]], 'payload'); $this->fail('Foreign output accepted.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        finally { unlink($path); }
        $this->assertSame(0, IguideLabLifestyleAssessment::count()); Http::assertNothingSent();
    }
    public function test_scene_proposal_digest_retains_unsorted_key_order(): void
    {
        $proposal = ['z' => 'last alphabetically', 'a' => ['second' => 2, 'first' => 1]];
        $this->assertSame(hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)), LabAnalysisRecipes::hash($proposal));
        $sorted = $proposal; ksort($sorted); $this->assertNotSame(LabAnalysisRecipes::hash($sorted), LabAnalysisRecipes::hash($proposal));
    }
    public function test_replay_repairs_a_committed_unattached_queued_record_without_resubmitting_other_states(): void
    {
        [, , , , $step, $record] = $this->reserved(); $service = app(LabLifestyleAssessments::class);
        // Crash boundary: record committed, but attachment and dispatch never happened.
        $step->update(['child_id' => null, 'status' => 'reserved']); Queue::fake();
        $this->assertSame($record->id, $service->start($step->fresh())->id);
        $this->assertSame($record->id, $step->fresh()->child_id);
        Queue::assertPushed(\App\Jobs\ProcessIguideLabLifestyleAssessment::class, 1);
        foreach (['submitting', 'received', 'needs_attention'] as $status) {
            $record->update(['status' => $status]); Queue::fake();
            $this->assertSame($record->id, $service->start($step->fresh())->id);
            Queue::assertNothingPushed();
        }
        $this->assertSame(1, IguideLabLifestyleAssessment::count()); Http::assertNothingSent();
    }
}

class LifestyleAssessmentContextFixture extends LabLifestyleAssessmentContext
{
    public array $bundle = []; public bool $reject = false;
    public function capture(IguideLabWorkflowItem $item, string $sectionId, string $runId): array { abort_if($this->reject, 422, 'Fixture source drift'); return $this->bundle; }
}
class LifestyleAssessmentBridgeFixture extends LabLifestyleAssessmentBridge
{
    public bool $available = true; public string $sourceSha = ''; public bool $floorBoundaryFloats = false;
    public function ready(): bool { return $this->available; }
    public function snapshot(): array { return ['fixtureProtocol' => 1]; }
    public function run(string $projectId, string $relativeRequest, array $request, array $snapshot): array
    {
        $this->validate($snapshot); $disk = Storage::disk(LabService::DISK); $this->json($relativeRequest, $request);
        $operation = $request['operation'];
        if ($operation === 'compile') $outputs = ['payload' => ['model' => 'gpt-6-astra', 'store' => false, 'input' => [['content' => [['type' => 'input_text', 'text' => json_encode(['contextSha256' => str_repeat('c', 64)])]]]]]];
        elseif ($operation === 'parse') $outputs = ['assessment' => ['status' => 'ready_for_segmentation', 'automaticAcceptance' => false, 'authorOrigin' => 'machine', 'reviewReasons' => [],
            'segmentationPlan' => [['stepKey' => 'source-floor', 'inputSha256' => $this->sourceSha], ['stepKey' => 'candidate-people', 'inputSha256' => $this->sourceSha]]]];
        elseif ($operation === 'bind-plans') {
            $assessment = json_decode(file_get_contents($request['assessment']['path']), true); $reservations = json_decode(file_get_contents($request['reservations']['path']), true);
            $outputs = ['plans' => array_map(fn ($plan) => $plan + ['requestId' => $reservations[$plan['stepKey']]], $assessment['segmentationPlan'])];
        } else $outputs = ['association' => ['status' => 'ready_for_support_validation']];
        if ($operation === 'parse' && $this->floorBoundaryFloats) $outputs['assessment']['floorSelection'] = ['box' => ['xMin' => 0.0, 'yMin' => 0.5, 'xMax' => 1.0, 'yMax' => 1.0]];
        if ($operation === 'parse') $outputs['assessment']['execution'] = json_decode(file_get_contents($request['execution']['path']), true, flags: JSON_THROW_ON_ERROR);
        $root = $disk->path(''); $base = str_replace('\\', '/', substr($request['outputDirectory'], strlen($root)));
        $descriptors = [];
        foreach ($outputs as $key => $value) { $asset = $this->bytes(ltrim($base, '/').'/'.$key.'.json', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)); $descriptors[$key] = $asset + ['bytes' => filesize($asset['path'])]; }
        return ['operation' => $operation, 'outputs' => $descriptors];
    }
}
