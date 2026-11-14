<?php
namespace Tests\Feature;

use App\Models\{IguideLabAnalysis, IguideLabProject, IguideLabRun, IguideLabWorkflowItem};
use App\Services\IguideLab\{LabAnalysisRecipes, LabGrassAssessmentContext, LabImages, LabService, LabWorkflowSteps, LabWorkflows};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Queue, Storage};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real source/record/file binding; directional projection is independently exercised by Linux integration. */
class IguideLabGrassContextTest extends TestCase
{
    use RefreshDatabase;
    private GrassContextViewsFixture $context;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        if (function_exists('posix_getegid')) config(['filesystems.disks.'.LabService::DISK.'.shared_group' => posix_getgrgid(posix_getegid())['name']]);
        Storage::fake(LabService::DISK, config('filesystems.disks.'.LabService::DISK))->makeDirectory('');
        $this->context = new GrassContextViewsFixture;
    }
    private function fixture(): array
    {
        $project = IguideLabProject::create(['name' => 'Context', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDay(), 'max_runs' => 10]);
        $png = (string) \Intervention\Image\ImageManager::gd()->create(2048, 1024)->fill('888844')->toPng();
        $case = app(LabService::class)->addCase($project, UploadedFile::fake()->createWithContent('source.png', $png), ['label' => 'Original', 'projection' => 'equirectangular']);
        $workflow = app(LabWorkflows::class)->start($project, ['requestId' => (string) Str::uuid(), 'items' => [['caseId' => $case->id, 'preset' => 'grass']]]);
        $item = IguideLabWorkflowItem::where('workflow_id', $workflow->id)->sole(); $steps = app(LabWorkflowSteps::class);
        $analysisStep = $steps->reserve($item, 'source-analysis', 'analysis', ['caseId' => $case->id, 'preset' => 'grass']);
        $prepared = LabImages::prepared($png); $proposal = ['workflow' => ['mode' => 'whole_panorama'], 'generationPrompt' => 'Keep source texture.'];
        $analysis = IguideLabAnalysis::create(['project_id' => $project->id, 'case_id' => $case->id, 'request_id' => $analysisStep->request_id,
            'request_hash' => $analysisStep->input_sha256, 'source_sha256' => $case->source_sha256, 'status' => 'completed', 'settings' => ['preset' => 'grass'],
            'proposal' => $proposal, 'proposal_sha256' => LabAnalysisRecipes::hash($proposal), 'data' => ['workflowInvocation' => $steps->invocation($analysisStep), 'preparedSourceSha256' => hash('sha256', $prepared)]]);
        $steps->attach($analysisStep, $analysis->id);
        $imageStep = $steps->reserve($item, 'guidance-image', 'image', ['caseId' => $case->id, 'preset' => 'grass']);
        $run = IguideLabRun::create(['project_id' => $project->id, 'case_id' => $case->id, 'request_id' => $imageStep->request_id, 'request_hash' => $imageStep->input_sha256,
            'model_id' => 'gpt-image-2.6-sunburst', 'provider' => 'openai', 'preset' => 'grass', 'status' => 'completed', 'prompt' => $proposal['generationPrompt'],
            'options' => ['workflowInvocation' => $steps->invocation($imageStep), 'preparedInputSha256' => [hash('sha256', $prepared)],
                'sourceAnalysis' => ['id' => $analysis->id, 'proposalSha256' => $analysis->proposal_sha256]], 'output_width' => 2048, 'output_height' => 1024]);
        $output = 'iguide-lab/'.$project->id.'/runs/'.$run->id.'.png'; Storage::disk(LabService::DISK)->put($output, $prepared, ['visibility' => 'private']);
        $run->update(['output_path' => $output, 'output_sha256' => hash('sha256', $prepared)]); $steps->attach($imageStep, $run->id);
        return [$case, $item, $analysis, $run];
    }
    private function rejected(callable $action): void
    {
        try { $action(); $this->fail('Changed source authority passed.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }
    public function test_exact_native_preparation_and_sphere_context_are_bound_in_fixed_order(): void
    {
        [$case, $item, , $run] = $this->fixture(); $bundle = $this->context->capture($item, $run->id);
        $this->assertSame(['source_panorama', 'candidate_panorama', 'source_front', 'source_right', 'source_back', 'source_left', 'source_zenith', 'source_nadir'], array_column($bundle['assets'], 'id'));
        $this->assertSame($case->source_sha256, $bundle['context']['binding']['nativeSourceSha256']);
        $this->assertSame($run->options['preparedInputSha256'][0], $bundle['context']['binding']['guidanceSourceSha256']);
        $this->assertSame('LabImages::prepared/GD', $bundle['sourcePreparation']['method']);
        $this->assertSame($bundle, $this->context->capture($item, $run->id)); Http::assertNothingSent();
    }
    public function test_native_and_candidate_file_drift_stop_before_projection(): void
    {
        [$case, $item, , $run] = $this->fixture(); $disk = Storage::disk(LabService::DISK);
        foreach ([$case->source_path, $run->output_path] as $path) {
            $bytes = $disk->get($path); $disk->put($path, 'changed');
            $this->rejected(fn () => $this->context->capture($item, $run->id)); $disk->put($path, $bytes);
        }
        $this->assertSame(0, $this->context->calls); Http::assertNothingSent();
    }
    public function test_prepared_source_analysis_and_generation_binding_drift_are_rejected(): void
    {
        [, $item, $analysis, $run] = $this->fixture(); $options = $run->options; $data = $analysis->data;
        $run->update(['options' => array_replace($options, ['preparedInputSha256' => [str_repeat('f', 64)]])]);
        $this->rejected(fn () => $this->context->capture($item, $run->id)); $run->update(['options' => $options]);
        $analysis->update(['data' => array_replace($data, ['preparedSourceSha256' => str_repeat('f', 64)])]);
        $this->rejected(fn () => $this->context->capture($item, $run->id)); $analysis->update(['data' => $data]);
        $analysis->update(['proposal' => ['workflow' => ['mode' => 'whole_panorama'], 'changed' => true]]);
        $this->rejected(fn () => $this->context->capture($item, $run->id));
        $this->assertSame(0, $this->context->calls); Http::assertNothingSent();
    }
    public function test_directional_context_pose_drift_is_rejected(): void
    {
        [, $item, , $run] = $this->fixture(); $this->context->wrongPose = true;
        $this->rejected(fn () => $this->context->capture($item, $run->id)); Http::assertNothingSent();
    }
}

class GrassContextViewsFixture extends LabGrassAssessmentContext
{
    public int $calls = 0; public bool $wrongPose = false;
    protected function views(string $path): array
    {
        $this->calls++; $request = json_decode(file_get_contents($path), true); $out = $request['outputDirectory'];
        if (!is_dir($out.'/views')) mkdir($out.'/views', 02770, true);
        $png = (string) \Intervention\Image\ImageManager::gd()->create(1024, 1024)->fill('888844')->toPng(); $views = [];
        foreach (['front', 'right', 'back', 'left', 'zenith', 'nadir'] as $index => $id) {
            [$yaw, $pitch] = [[0,0],[90,0],[180,0],[270,0],[0,90],[0,-90]][$index];
            file_put_contents($out.'/views/'.$id.'.png', $png);
            $views[] = ['id' => $id, 'path' => $out.'/views/'.$id.'.png', 'sha256' => hash('sha256', $png), 'yaw_degrees' => $this->wrongPose ? 17 : $yaw,
                'pitch_degrees' => $pitch, 'hfov_degrees' => 100, 'width' => 1024, 'height' => 1024];
        }
        $manifest = ['adapterSha256' => hash_file('sha256', resource_path('iguide-lab/analysis_views.py')),
            'geometrySha256' => hash_file('sha256', resource_path('iguide-lab/pano_geometry.py')), 'source' => $request['source'], 'views' => $views];
        file_put_contents($out.'/manifest.json', json_encode($manifest));
        return ['status' => 'completed', 'manifest' => $manifest];
    }
}
