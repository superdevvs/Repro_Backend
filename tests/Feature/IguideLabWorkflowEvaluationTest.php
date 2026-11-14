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
    private function fixture(string $instructions = ''): array
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
            $bytes = (string) \Intervention\Image\ImageManager::gd()->create(128, 64)->fill(sprintf('%02x8888', 30 + 20 * $offset))->toPng();
            $path = 'iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/'.$name.'.png'; Storage::disk(LabService::DISK)->put($path, $bytes, ['visibility' => 'private']);
            $context['views'][] = ['id' => $name, 'sha256' => hash('sha256', $bytes), 'width' => 128, 'height' => 64];
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
        return ['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
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
        [, , $step] = $this->fixture(); $service = app(LabWorkflowEvaluations::class); $record = $service->start($step);
        Http::fakeSequence('api.openai.com/v1/responses')->push(['error' => ['code' => 'insufficient_quota']], 429, ['x-request-id' => 'quota'])
            ->push($this->response($step->input['context']), 200, ['x-request-id' => 'evaluation']);
        $service->process($record); $service->process($record->fresh()); $record->refresh();
        $this->assertSame('completed', $record->status); $this->assertSame(['lab-primary', 'dashboard-fallback'], array_column($record->provider_attempts, 'credentialSource'));
        $requests = Http::recorded(); $this->assertSame($requests[0][0]->data(), $requests[1][0]->data()); Http::assertSentCount(2);
        $this->assertSame('passed_automated_checks', $record->result['qualityStatus']); $this->assertFalse($record->result['manualAcceptance']);
        $this->assertStringNotContainsString('PRIVATE_REASONING', json_encode($record->result));
        $this->assertSame(0, \App\Models\IguideLabReview::count());
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

    public function test_evidence_rejects_wrong_or_changed_scene_proposal_before_local_processing(): void
    {
        [$project, $item, , $composite] = $this->fixture();
        $proposal = ['summary' => 'Original source-bound proposal'];
        $sha = \App\Services\IguideLab\LabAnalysisRecipes::hash($proposal);
        $composite->manifest = $composite->manifest + ['scenePlanSha256' => $sha];
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
}
