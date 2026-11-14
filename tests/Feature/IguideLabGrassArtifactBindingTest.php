<?php
namespace Tests\Feature;

use App\Models\{IguideLabComposite, IguideLabProject, IguideLabRun, IguideLabWorkflowItem};
use App\Services\IguideLab\{LabGrassWorkflowArtifacts, LabService, LabWorkflowSteps, LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Queue, Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IguideLabGrassArtifactBindingTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
    }
    private function fixture(): array
    {
        $project = IguideLabProject::create(['name' => 'Artifact binding', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 10]);
        $png = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('778855')->toPng();
        $case = app(LabService::class)->addCase($project, UploadedFile::fake()->createWithContent('source.png', $png), ['label' => 'Source', 'projection' => 'equirectangular']);
        $workflow = app(LabWorkflows::class)->start($project, ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $case->id, 'preset' => 'grass']]]);
        $item = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole(); $steps = app(LabWorkflowSteps::class); $disk = Storage::disk(LabService::DISK);
        $run = IguideLabRun::create(['project_id' => $project->id, 'case_id' => $case->id, 'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'status' => 'completed', 'model_id' => 'gpt-image-2.6-sunburst', 'provider' => 'openai', 'preset' => 'grass', 'prompt' => 'Fixture', 'options' => [], 'output_sha256' => hash('sha256', $png)]);
        $run->update(['output_path' => 'iguide-lab/'.$project->id.'/runs/'.$run->id.'.png']); $disk->put($run->output_path, $png);
        $recipe = app(LabGrassWorkflowArtifacts::class)->snapshot();
        $step = $steps->reserve($item, 'grass-composition', 'composition', ['runId' => $run->id, 'outputSha256' => $run->output_sha256,
            'assessmentId' => (string) Str::uuid(), 'assessmentSha256' => str_repeat('b', 64), 'recipe' => $recipe]);
        $base = 'iguide-lab/'.$project->id.'/workflow-items/'.$item->id.'/grass-'.$step->id;
        $disk->put($base.'/effectiveAlpha.png', $png); $disk->put($base.'/report.json', '{"fixture":true}');
        $id = (string) Str::uuid(); $preview = 'iguide-lab/'.$project->id.'/composites/'.$id.'-preview.png'; $disk->put($preview, $png);
        $invocation = $steps->invocation($step); ksort($invocation); // Normal composite canonicalization is deliberately not array-order identity.
        $manifest = ['source' => ['sourceSha256' => $case->source_sha256], 'preview' => ['sha256' => hash('sha256', $png)],
            'children' => [['runId' => $run->id, 'requestId' => $run->request_id, 'outputSha256' => $run->output_sha256, 'maskSha256' => hash('sha256', $png)]],
            'qaManifestSha256' => hash('sha256', '{"fixture":true}'), 'lineage' => ['assessmentId' => $step->input['assessmentId'], 'assessmentSha256' => $step->input['assessmentSha256'],
                'workflowInvocation' => $invocation, 'recipe' => $recipe, 'effectiveAlpha' => ['path' => $base.'/effectiveAlpha.png', 'sha256' => hash('sha256', $png)]]];
        $composite = IguideLabComposite::create(['id' => $id, 'project_id' => $project->id, 'case_id' => $case->id, 'request_id' => $step->request_id,
            'request_hash' => str_repeat('c', 64), 'status' => 'completed', 'label' => 'Fixture only', 'preview_path' => $preview, 'manifest' => $manifest]);
        return [$item, $composite, $step, $run, $base];
    }
    private function rejected(callable $action): void
    {
        try { $action(); $this->fail('Artifact drift was accepted.'); }
        catch (HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
    }
    public function test_exact_replay_checks_preview_effective_alpha_and_bound_report(): void
    {
        [$item, $composite, $step, $run, $base] = $this->fixture(); $service = app(LabGrassWorkflowArtifacts::class); $disk = Storage::disk(LabService::DISK);
        $service->verifyComposite($item, $composite, $step, $run);
        foreach ([$composite->preview_path, $base.'/effectiveAlpha.png', $base.'/report.json'] as $path) {
            $bytes = $disk->get($path); $disk->put($path, $bytes.'changed');
            $this->rejected(fn () => $service->verifyComposite($item, $composite, $step, $run)); $disk->put($path, $bytes);
        }
        $service->verifyComposite($item, $composite, $step, $run); Http::assertNothingSent();
    }
    public function test_native_source_assessment_and_support_lineage_cannot_be_rebound(): void
    {
        [$item, $composite, $step, $run] = $this->fixture(); $service = app(LabGrassWorkflowArtifacts::class); $original = $composite->manifest;
        foreach (['source.sourceSha256', 'lineage.assessmentSha256', 'children.0.maskSha256'] as $key) {
            $manifest = $original; data_set($manifest, $key, str_repeat('f', 64)); $composite->manifest = $manifest;
            $this->rejected(fn () => $service->verifyComposite($item, $composite, $step, $run));
        }
        $composite->manifest = $original; $service->verifyComposite($item, $composite, $step, $run); Http::assertNothingSent();
    }
}
