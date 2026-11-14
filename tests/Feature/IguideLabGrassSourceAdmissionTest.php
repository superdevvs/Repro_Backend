<?php
namespace Tests\Feature;

use App\Models\{IguideLabGrassSourceAdmission, IguideLabProject, IguideLabWorkflowItem, IguideLabWorkflowStep};
use App\Services\IguideLab\{LabGrassAssessmentPolicy, LabGrassSourceAdmissionPolicy, LabGrassSourceAdmissionContext, LabGrassSourceAdmissionBridge, LabGrassSourceAdmissionProvider, LabGrassSourceAdmissions, LabService, LabWorkflowSteps, LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Queue, Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IguideLabGrassSourceAdmissionTest extends TestCase
{
    use RefreshDatabase;
    private string $key;
    private SourceAdmissionContextFixture $context;
    private SourceAdmissionBridgeFixture $bridge;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        $this->key = tempnam(sys_get_temp_dir(), 'source-admission-'); file_put_contents($this->key, 'fixture-primary');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.openai.api_key' => 'fixture-fallback', 'iguide-lab.openai_primary_key_path' => $this->key,
            'iguide-lab.grass_source_admission_enabled' => true]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context = new SourceAdmissionContextFixture; $this->bridge = new SourceAdmissionBridgeFixture;
        $this->app->instance(LabGrassSourceAdmissionContext::class, $this->context);
        $this->app->instance(LabGrassSourceAdmissionBridge::class, $this->bridge);
    }
    protected function tearDown(): void { if (is_file($this->key)) unlink($this->key); parent::tearDown(); }
    private function reserved(): array
    {
        $project = IguideLabProject::create(['name' => 'Original-only admission', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 20]);
        $bytes = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888888')->toPng();
        $case = app(LabService::class)->addCase($project, UploadedFile::fake()->createWithContent('source.png', $bytes), ['label' => 'Original', 'projection' => 'equirectangular']);
        $workflow = app(LabWorkflows::class)->start($project, ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $case->id, 'preset' => 'grass']]]);
        $policy = $workflow->policy; $policy['grassAssessment'] = LabGrassAssessmentPolicy::snapshot(); $policy['grassSourceAdmission'] = LabGrassSourceAdmissionPolicy::snapshot(); $workflow->update(['policy' => $policy]);
        $item = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole();
        $this->context->bundle = ['context' => ['binding' => ['sourceSha256' => $case->source_sha256], 'round' => 0],
            'assets' => [['id' => 'source_panorama', 'path' => $case->source_path, 'sha256' => $case->source_sha256]]];
        $service = app(LabGrassSourceAdmissions::class); $step = $service->reserve($item);
        return [$project, $workflow, $item, $step, $service->start($step)];
    }
    private function response(): array { return ['id' => 'resp-source', 'model' => 'gpt-6-astra', 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]]; }
    public function test_known_receipt_resume_is_once_and_creates_no_image_or_sam(): void
    {
        [$project, , $item, $step, $record] = $this->reserved(); $service = app(LabGrassSourceAdmissions::class);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(), 200, ['x-request-id' => 'req-source'])]);
        $record->update(['status' => 'submitting']);
        app(LabGrassSourceAdmissionProvider::class)->assess($record, file_get_contents($record->data['payload']['path']));
        $this->assertSame('received', $record->fresh()->status);
        $service->process($record->fresh()); $service->process($record->fresh());
        $this->assertSame('eligible_for_image', $service->verifiedReport($record->fresh(), $item)['status']);
        $this->assertSame($record->id, $service->start($step)->id);
        $this->assertSame(0, IguideLabWorkflowStep::whereIn('kind', ['image', 'segmentation'])->count());
        $this->assertSame(0, $project->fresh()->used_runs); Http::assertSentCount(1);
    }
    public function test_semantic_hold_is_completed_with_no_segmentation_authority(): void
    {
        [, , $item, , $record] = $this->reserved(); $this->bridge->status = 'needs_review';
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        $service = app(LabGrassSourceAdmissions::class); $service->process($record); $service->process($record->fresh());
        $report = $service->verifiedReport($record->fresh(), $item);
        $this->assertSame('completed', $record->fresh()->status); $this->assertSame('needs_review', $report['status']);
        $this->assertFalse($report['segmentationAuthority']); $this->assertSame(0, IguideLabWorkflowStep::where('kind', 'image')->count());
        Http::assertSentCount(1);
    }
    public function test_unknown_transport_never_retries(): void
    {
        [, , , , $record] = $this->reserved();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('fixture unknown'));
        $service = app(LabGrassSourceAdmissions::class); $service->process($record); $service->process($record->fresh());
        $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertCount(1, $record->fresh()->provider_attempts);
        $this->assertSame('unknown', $record->fresh()->provider_attempts[0]['status']);
    }
    public function test_cancellation_after_quota_rejection_blocks_fallback(): void
    {
        [, $workflow, , , $record] = $this->reserved(); $attempts = 0;
        Http::fake(function () use ($workflow, &$attempts) { $attempts++; app(LabWorkflows::class)->cancel($workflow); return Http::response(['error' => ['code' => 'insufficient_quota']], 429); });
        $service = app(LabGrassSourceAdmissions::class); $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $attempts); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertSame('quota_rejected', $record->fresh()->provider_attempts[0]['status']);
    }
    public function test_completed_receipt_and_current_source_are_required_for_reuse(): void
    {
        [, , $item, , $record] = $this->reserved();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        $service = app(LabGrassSourceAdmissions::class); $service->process($record); $service->process($record->fresh()); $record->refresh();
        $disk = Storage::disk(LabService::DISK); $path = $record->data['providerReceipt']['response']['path']; $bytes = $disk->get($path);
        $disk->delete($path);
        try { $service->verifiedReport($record, $item); $this->fail('Missing retained response authorized image.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $disk->put($path, $bytes); $this->context->reject = true;
        try { $service->verifiedReport($record, $item); $this->fail('Changed source authorized image.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        Http::assertSentCount(1);
    }
    public function test_quota_response_cannot_fallback_after_original_context_drift(): void
    {
        [, , , , $record] = $this->reserved(); $attempts = 0;
        Http::fake(function () use (&$attempts) { $attempts++; $this->context->reject = true; return Http::response(['error' => ['code' => 'insufficient_quota']], 429); });
        $service = app(LabGrassSourceAdmissions::class); $service->process($record); $service->process($record->fresh());
        $this->assertSame(1, $attempts); $this->assertSame('needs_attention', $record->fresh()->status);
        $this->assertCount(1, $record->fresh()->provider_attempts);
    }
    public function test_disabling_feature_before_submission_holds_without_provider(): void
    {
        [, , , , $record] = $this->reserved(); config(['iguide-lab.grass_source_admission_enabled' => false]);
        app(LabGrassSourceAdmissions::class)->process($record);
        $this->assertSame('needs_attention', $record->fresh()->status); Http::assertNothingSent();
    }
    public function test_four_analysis_budget_does_not_expand_image_budget(): void
    {
        [, , $item] = $this->reserved(); $steps = app(LabWorkflowSteps::class);
        for ($i = 1; $i < 4; $i++) $steps->reserve($item, 'bounded-'.$i, 'analysis', ['fixture' => $i]);
        try { $steps->reserve($item, 'too-many', 'analysis', []); $this->fail('Unbounded analysis admitted.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $steps->reserve($item, 'image-one', 'image', []);
        try { $steps->reserve($item, 'image-two', 'image', []); $this->fail('Second grass image admitted.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        Http::assertNothingSent();
    }
}

class SourceAdmissionContextFixture extends LabGrassSourceAdmissionContext
{
    public array $bundle = []; public bool $reject = false;
    public function capture(IguideLabWorkflowItem $item, ?string $priorAdmissionId): array { abort_if($this->reject, 422, 'Original source drift'); return $this->bundle; }
}
class SourceAdmissionBridgeFixture extends LabGrassSourceAdmissionBridge
{
    public string $status = 'eligible_for_image';
    public function ready(): bool { return true; }
    public function snapshot(): array { return ['sourceFixtureProtocol' => 1]; }
    public function run(string $projectId, string $relativeRequest, array $request, array $snapshot): array
    {
        $this->validate($snapshot); $this->json($relativeRequest, $request); $disk = Storage::disk(LabService::DISK);
        if ($request['operation'] === 'compile') $outputs = ['payload' => ['model' => 'gpt-6-astra', 'store' => false,
            'input' => [['content' => [['type' => 'input_text', 'text' => json_encode(['contextSha256' => str_repeat('c', 64)])]]]]]];
        else $outputs = ['assessment' => ['status' => $this->status, 'automaticAcceptance' => false, 'segmentationAuthority' => false,
            'authorOrigin' => 'machine', 'round' => 0, 'reviewReasons' => [], 'detailPlan' => [], 'contextSha256' => str_repeat('c', 64),
            'execution' => json_decode(file_get_contents($request['execution']['path']), true, flags: JSON_THROW_ON_ERROR)]];
        $base = str_replace('\\', '/', substr($request['outputDirectory'], strlen($disk->path('')))); $descriptors = [];
        foreach ($outputs as $name => $value) { $asset = $this->json(ltrim($base, '/').'/'.$name.'.json', $value); $descriptors[$name] = $asset + ['bytes' => filesize($asset['path'])]; }
        return ['operation' => $request['operation'], 'outputs' => $descriptors];
    }
}
