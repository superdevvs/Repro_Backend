<?php
namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Models\IguideLabGrassAssessment;
use App\Models\IguideLabWorkflowItem;
use App\Services\IguideLab\LabAnalysisRecipes;
use App\Services\IguideLab\LabService;
use App\Services\IguideLab\LabGrassAssessmentBridge;
use App\Services\IguideLab\LabGrassAssessmentContext;
use App\Services\IguideLab\LabGrassAssessmentPolicy;
use App\Services\IguideLab\LabGrassAssessmentProvider;
use App\Services\IguideLab\LabGrassAssessments;
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
class IguideLabGrassAssessmentTest extends TestCase
{
    use RefreshDatabase;
    private string $key;
    private GrassAssessmentContextFixture $context;
    private GrassAssessmentBridgeFixture $bridge;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        $this->key = tempnam(sys_get_temp_dir(), 'placement-key-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback', 'iguide-lab.openai_primary_key_path' => $this->key]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context = new GrassAssessmentContextFixture; $this->bridge = new GrassAssessmentBridgeFixture;
        $this->app->instance(LabGrassAssessmentContext::class, $this->context);
        $this->app->instance(LabGrassAssessmentBridge::class, $this->bridge);
    }
    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }
    private function fixture(string $preset = 'grass'): array
    {
        $project = IguideLabProject::create(['name' => 'Machine placement', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $bytes = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $case = app(LabService::class)->addCase($project, UploadedFile::fake()->createWithContent('source.png', $bytes), ['label' => 'Original', 'projection' => 'equirectangular']);
        $workflow = app(LabWorkflows::class)->start($project, ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $case->id, 'preset' => $preset]]]);
        if ($preset === 'grass') { $policy = $workflow->policy; $policy['grassAssessment'] = LabGrassAssessmentPolicy::snapshot(); $workflow->update(['policy' => $policy]); }
        $item = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole();
        $this->context->bundle = ['context' => ['binding' => ['fixtureSourceSha256' => $case->source_sha256]],
            'assets' => [['id' => 'source_panorama', 'path' => $case->source_path, 'sha256' => $case->source_sha256],
                ['id' => 'candidate_panorama', 'path' => $case->source_path, 'sha256' => $case->source_sha256]]];
        $this->bridge->sourceSha = $case->source_sha256;
        return [$project, $workflow, $item, $case];
    }
    private function reserved(): array
    {
        $values = $this->fixture(); $service = app(LabGrassAssessments::class);
        $step = $service->reserve($values[2], (string) Str::uuid());
        return [...$values, $step, $service->start($step)];
    }
    private function response(): array { return ['id' => 'resp-fixture', 'model' => 'gpt-6-astra', 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]]; }
    public function test_reservation_replay_and_known_receipt_resume_do_not_repeat_paid_submission(): void
    {
        [$project, , , , $step, $record] = $this->reserved(); $service = app(LabGrassAssessments::class);
        $this->assertSame($record->id, $service->start($step)->id);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(), 200, ['x-request-id' => 'fixture'])]);
        // Simulate a worker stopping after durable receipt storage, before local parsing.
        $record->update(['status' => 'submitting']);
        app(LabGrassAssessmentProvider::class)->assess($record, file_get_contents($record->data['payload']['path']));
        $this->assertSame('received', $record->fresh()->status);
        $service->process($record->fresh()); $service->process($record->fresh());
        $this->assertSame('completed', $record->fresh()->status); Http::assertSentCount(1);
        $this->assertSame(1, IguideLabGrassAssessment::count());
        $this->assertSame(0, \App\Models\IguideLabAnalysis::count());
        $this->assertSame(0, $project->fresh()->used_runs);
        $this->assertFalse($record->fresh()->result['automaticAcceptance']);
    }
    public function test_quota_fallback_preserves_exact_saved_payload_and_other_errors_do_not_retry(): void
    {
        [, , , , , $record] = $this->reserved();
        Http::fakeSequence('api.openai.com/v1/responses')->push(['error' => ['code' => 'insufficient_quota']], 429)->push($this->response(), 200);
        app(LabGrassAssessments::class)->process($record);
        $this->assertSame('received', $record->fresh()->status);
        app(LabGrassAssessments::class)->process($record->fresh());
        $this->assertSame('completed', $record->fresh()->status); Http::assertSentCount(2);
        $requests = Http::recorded(); $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertSame(file_get_contents($record->data['payload']['path']), $requests[0][0]->body());
        $this->assertSame(['lab-primary', 'dashboard-fallback'], array_column($record->fresh()->provider_attempts, 'credentialSource'));
    }
    public function test_cancellation_after_quota_rejection_blocks_fallback_claim(): void
    {
        [, $workflow, , , , $record] = $this->reserved(); $count = 0;
        Http::fake(function () use ($workflow, &$count) { $count++; app(LabWorkflows::class)->cancel($workflow); return Http::response(['error' => ['code' => 'insufficient_quota']], 429); });
        $service = app(LabGrassAssessments::class); $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $count); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertSame(['quota_rejected'], array_column($record->fresh()->provider_attempts, 'status'));
    }
    public function test_ambiguous_submission_is_retained_without_retry(): void
    {
        [, , , , , $record] = $this->reserved(); $count = 0;
        Http::fake(function () use (&$count) { $count++; throw new \Illuminate\Http\Client\ConnectionException('fixture lost receipt'); });
        $service = app(LabGrassAssessments::class); $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $count); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertSame('unknown', $record->fresh()->provider_attempts[0]['status']);
    }
    public function test_changed_context_stops_before_submission_and_completed_receipt_association(): void
    {
        [, , , , , $record] = $this->reserved(); $this->context->reject = true;
        app(LabGrassAssessments::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); Http::assertNothingSent();
    }
    public function test_machine_association_rechecks_authority_after_receipt_and_rejects_result_tampering(): void
    {
        [, , , , , $record] = $this->reserved(); $service = app(LabGrassAssessments::class);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]); $service->process($record); $service->process($record->fresh());
        $steps = $service->reserveSegmentationSteps($record->fresh());
        $trusted = $service->forSegmentation($steps['source-grass-lawn']);
        $this->assertSame($steps['source-grass-lawn']->request_id, $trusted['boundPlan']['requestId']);
        $this->assertSame($record->id, $trusted['assessmentId']);
        $this->context->reject = true;
        try { $service->associate($steps['source-grass-lawn'], ['requestId' => $steps['source-grass-lawn']->request_id]); $this->fail('Drifted source associated.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->context->reject = false; $record->refresh(); file_put_contents($record->data['resultAsset']['path'], '{"tampered":true}');
        try { $service->forSegmentation($steps['source-grass-lawn']); $this->fail('Changed assessment authorized a call.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        Http::assertSentCount(1);
    }
    public function test_missing_runtime_and_foreign_output_path_fail_closed(): void
    {
        [$project, , $item] = $this->fixture(); $this->bridge->available = false;
        try { app(LabGrassAssessments::class)->reserve($item, (string) Str::uuid()); $this->fail('Unavailable runtime reserved.'); }
        catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
        $path = tempnam(sys_get_temp_dir(), 'foreign-assessment-'); file_put_contents($path, '{}');
        try { (new LabGrassAssessmentBridge)->output($project->id, ['outputs' => ['payload' => ['path' => $path, 'bytes' => 2, 'sha256' => hash('sha256', '{}')]]], 'payload'); $this->fail('Foreign output accepted.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        finally { unlink($path); }
        $this->assertSame(0, IguideLabGrassAssessment::count()); Http::assertNothingSent();
    }
    public function test_scene_proposal_digest_retains_unsorted_key_order(): void
    {
        $proposal = ['z' => 'last alphabetically', 'a' => ['second' => 2, 'first' => 1]];
        $this->assertSame(hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)), LabAnalysisRecipes::hash($proposal));
        $sorted = $proposal; ksort($sorted); $this->assertNotSame(LabAnalysisRecipes::hash($sorted), LabAnalysisRecipes::hash($proposal));
    }
    public function test_grass_policy_bounds_each_paid_child_kind_independently(): void
    {
        [, , $item] = $this->fixture(); $steps = app(LabWorkflowSteps::class);
        foreach (['analysis' => 2, 'image' => 1, 'segmentation' => 6, 'evaluation' => 1] as $kind => $limit) {
            for ($i = 0; $i < $limit; $i++) $steps->reserve($item, $kind.'-'.$i, $kind, ['fixture' => $i]);
            try { $steps->reserve($item, $kind.'-overflow', $kind, ['fixture' => $limit]); $this->fail('Grass child budget exceeded.'); }
            catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        }
        Http::assertNothingSent();
    }
    public function test_custom_grass_scope_is_held_before_analysis_or_any_paid_child(): void
    {
        [$project, $workflow, $item] = $this->fixture();
        $driver = app(\App\Services\IguideLab\LabGrassWorkflow::class);
        $workflow->update(['policy' => $workflow->policy + ['grassRecipe' => $driver->snapshot()]]);
        $item->update(['instructions' => 'Only recolor the front patch.']);
        $driver->advance($workflow->fresh(), $item->fresh(), $project);
        $this->assertSame('needs_review', $item->fresh()->status);
        $this->assertSame('grass_custom_scope_unsupported', $item->fresh()->data['reviewReasons'][0]['code']);
        $this->assertSame(0, \App\Models\IguideLabWorkflowStep::count()); Http::assertNothingSent();
    }
    public function test_replay_repairs_a_committed_unattached_queued_record_without_resubmitting_other_states(): void
    {
        [, , , , $step, $record] = $this->reserved(); $service = app(LabGrassAssessments::class);
        // Crash boundary: record committed, but attachment and dispatch never happened.
        $step->update(['child_id' => null, 'status' => 'reserved']); Queue::fake();
        $this->assertSame($record->id, $service->start($step->fresh())->id);
        $this->assertSame($record->id, $step->fresh()->child_id);
        Queue::assertPushed(\App\Jobs\ProcessIguideLabGrassAssessment::class, 1);
        foreach (['submitting', 'received', 'needs_attention'] as $status) {
            $record->update(['status' => $status]); Queue::fake();
            $this->assertSame($record->id, $service->start($step->fresh())->id);
            Queue::assertNothingPushed();
        }
        $this->assertSame(1, IguideLabGrassAssessment::count()); Http::assertNothingSent();
    }
}

class GrassAssessmentContextFixture extends LabGrassAssessmentContext
{
    public array $bundle = []; public bool $reject = false;
    public function capture(IguideLabWorkflowItem $item, string $runId): array { abort_if($this->reject, 422, 'Fixture source drift'); return $this->bundle; }
}
class GrassAssessmentBridgeFixture extends LabGrassAssessmentBridge
{
    public bool $available = true; public string $sourceSha = '';
    public function ready(): bool { return $this->available; }
    public function snapshot(): array { return ['fixtureProtocol' => 1]; }
    public function run(string $projectId, string $relativeRequest, array $request, array $snapshot): array
    {
        $this->validate($snapshot); $disk = Storage::disk(LabService::DISK); $this->json($relativeRequest, $request);
        $operation = $request['operation'];
        if ($operation === 'compile') $outputs = ['payload' => ['model' => 'gpt-6-astra', 'store' => false, 'input' => [['content' => [['type' => 'input_text', 'text' => json_encode(['contextSha256' => str_repeat('c', 64)])]]]]]];
        elseif ($operation === 'parse') $outputs = ['assessment' => ['status' => 'ready_for_source_segmentation', 'automaticAcceptance' => false, 'authorOrigin' => 'machine', 'reviewReasons' => [],
            'segmentationPlan' => [['stepKey' => 'source-grass-lawn', 'maskRole' => 'lawn', 'inputSha256' => $this->sourceSha], ['stepKey' => 'source-grass-paving', 'maskRole' => 'paving', 'inputSha256' => $this->sourceSha]]]];
        elseif ($operation === 'bind-plans') {
            $assessment = json_decode(file_get_contents($request['assessment']['path']), true); $reservations = json_decode(file_get_contents($request['reservations']['path']), true);
            $outputs = ['plans' => array_map(fn ($plan) => $plan + ['requestId' => $reservations[$plan['stepKey']]], $assessment['segmentationPlan'])];
        } else $outputs = ['association' => ['status' => 'ready_for_source_support']];
        $root = $disk->path(''); $base = str_replace('\\', '/', substr($request['outputDirectory'], strlen($root)));
        $descriptors = [];
        foreach ($outputs as $key => $value) { $asset = $this->json(ltrim($base, '/').'/'.$key.'.json', $value); $descriptors[$key] = $asset + ['bytes' => filesize($asset['path'])]; }
        return ['operation' => $operation, 'outputs' => $descriptors];
    }
}
