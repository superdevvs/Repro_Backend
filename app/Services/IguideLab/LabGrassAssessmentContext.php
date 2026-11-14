<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabRun;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Original same-capture sphere authority. Generated pixels supply color guidance only. */
class LabGrassAssessmentContext
{
    public function capture(IguideLabWorkflowItem $item, string $runId): array
    {
        $native = app(LabWorkflowSources::class)->verify($item->project_id, $item->source);
        $run = IguideLabRun::where('project_id', $item->project_id)->where('status', 'completed')->findOrFail($runId);
        abort_unless($item->preset === 'grass' && $native->projection === 'equirectangular' && $native->width >= 2048
            && $run->case_id === $native->id && $run->preset === 'grass' && $run->style === $item->style
            && [$run->output_width, $run->output_height] === [2048, 1024], 422, 'The grass candidate is not the same original sphere.');
        $imageStep = $this->child($item, 'image', $run->id);
        abort_unless(($run->options['workflowInvocation'] ?? null) === app(LabWorkflowSteps::class)->invocation($imageStep), 422, 'The candidate is not an actual machine image child.');
        $analysis = IguideLabAnalysis::where('project_id', $item->project_id)->where('status', 'completed')->findOrFail($run->options['sourceAnalysis']['id'] ?? '');
        $analysisStep = $this->child($item, 'analysis', $analysis->id);
        abort_unless($analysis->case_id === $native->id && $analysis->source_sha256 === $native->source_sha256
            && ($analysis->settings['preset'] ?? null) === 'grass' && ($analysis->proposal['workflow']['mode'] ?? null) === 'whole_panorama'
            && ($analysis->data['workflowInvocation'] ?? null) === app(LabWorkflowSteps::class)->invocation($analysisStep)
            && $analysis->proposal_sha256 === LabAnalysisRecipes::hash($analysis->proposal)
            && ($run->options['sourceAnalysis']['proposalSha256'] ?? null) === $analysis->proposal_sha256, 422, 'The grass source analysis binding changed.');
        $disk = Storage::disk(LabService::DISK);
        $this->file($item->project_id, $native->source_path, $native->source_sha256, 128 * 1024 * 1024);
        abort_unless($run->output_path === 'iguide-lab/'.$item->project_id.'/runs/'.$run->id.'.png' && is_string($run->output_sha256), 422);
        $candidate = $this->file($item->project_id, $run->output_path, $run->output_sha256, 32 * 1024 * 1024);
        $size = @getimagesize($candidate);
        abort_unless($size && [$size[0], $size[1], $size['mime']] === [2048, 1024, 'image/png'], 422);
        $prepared = LabImages::prepared($disk->get($native->source_path)); $preparedSha = hash('sha256', $prepared);
        abort_unless(($run->options['preparedInputSha256'] ?? null) === [$preparedSha]
            && ($analysis->data['preparedSourceSha256'] ?? null) === $preparedSha, 422, 'The exact original submitted source changed.');
        $preparation = ['nativeSourceSha256' => $native->source_sha256, 'guidanceSourceSha256' => $preparedSha,
            'preparerSha256' => hash_file('sha256', (new \ReflectionClass(LabImages::class))->getFileName()),
            'method' => 'LabImages::prepared/GD', 'verifiedByCaller' => true];
        // Only scalar strings/boolean: canonical encoding agrees with the frozen Python fixture.
        $preparationSha = LabMachineSegmentationPlan::canonicalHash($preparation);
        app(LabStorage::class)->prepareWorkflow($item->project_id, $item->id);
        $base = 'iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/grass-context-'.$run->id;
        $this->putOnce($base.'/source.png', $prepared);
        $viewRequest = ['schemaVersion' => 1, 'source' => ['path' => $disk->path($base.'/source.png'), 'sha256' => $preparedSha], 'outputDirectory' => $disk->path($base.'/views')];
        $this->putOnce($base.'/views-request.json', json_encode($viewRequest, JSON_THROW_ON_ERROR));
        $views = $this->views($disk->path($base.'/views-request.json'));
        $manifest = $views['manifest']; $geometrySha = hash_file('sha256', $disk->path($base.'/views/manifest.json'));
        abort_unless(($manifest['adapterSha256'] ?? null) === hash_file('sha256', resource_path('iguide-lab/analysis_views.py'))
            && ($manifest['geometrySha256'] ?? null) === hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'))
            && ($manifest['source']['sha256'] ?? null) === $preparedSha
            && array_column($manifest['views'] ?? [], 'id') === ['front', 'right', 'back', 'left', 'zenith', 'nadir'], 422, 'Source context derivation changed.');
        $evidence = $assets = [];
        $append = function ($id, $path, $sha, $width, $height, $projection, $pose, $derivation) use (&$evidence, &$assets, $native) {
            $evidence[] = ['id' => $id, 'sha256' => $sha, 'captureId' => $native->id, 'nativeSourceSha256' => $native->source_sha256,
                'derivationSha256' => $derivation, 'width' => $width, 'height' => $height, 'projection' => $projection, 'pose' => $pose];
            $assets[] = ['id' => $id, 'path' => $path, 'sha256' => $sha];
        };
        $generationSha = LabAnalysisRecipes::hash(['runId' => $run->id, 'requestId' => $run->request_id, 'modelId' => $run->model_id,
            'provider' => $run->provider, 'prompt' => $run->prompt, 'options' => $run->options, 'outputSha256' => $run->output_sha256]);
        $append('source_panorama', $base.'/source.png', $preparedSha, 2048, 1024, 'equirectangular', null, $preparationSha);
        $append('candidate_panorama', $run->output_path, $run->output_sha256, 2048, 1024, 'equirectangular', null, $generationSha);
        foreach ($manifest['views'] as $index => $view) {
            [$yaw, $pitch] = [[0, 0], [90, 0], [180, 0], [270, 0], [0, 90], [0, -90]][$index];
            $path = $base.'/views/views/'.$view['id'].'.png'; $file = $this->file($item->project_id, $path, $view['sha256'], 8 * 1024 * 1024);
            abort_unless(realpath($view['path']) === $file && (float) $view['yaw_degrees'] === (float) $yaw
                && (float) $view['pitch_degrees'] === (float) $pitch && (float) $view['hfov_degrees'] === 100.0
                && [$view['width'], $view['height']] === [1024, 1024], 422, 'Source directional pose changed.');
            $append('source_'.$view['id'], $path, $view['sha256'], 1024, 1024, 'rectilinear',
                ['yaw' => $yaw, 'pitch' => $pitch, 'hFov' => 100, 'width' => 1024, 'height' => 1024], $geometrySha);
        }
        $context = ['schemaVersion' => LabGrassAssessmentPolicy::VERSION, 'binding' => ['workflowItemId' => $item->id, 'captureId' => $native->id,
            'nativeSourceSha256' => $native->source_sha256, 'guidanceSourceSha256' => $preparedSha, 'candidateSha256' => $run->output_sha256,
            'sourcePreparationSha256' => $preparationSha, 'sourceAnalysisSha256' => $analysis->proposal_sha256, 'generationSettingsSha256' => $generationSha],
            'policy' => LabGrassAssessmentPolicy::assessmentPolicy(), 'evidence' => $evidence];
        return ['context' => $context, 'assets' => $assets, 'candidateRunId' => $run->id, 'sourceAnalysisId' => $analysis->id,
            'sourcePreparation' => $preparation, 'contextBuilderSha256' => hash_file('sha256', __FILE__),
            'parentViewAdapterSha256' => $manifest['adapterSha256'], 'geometryCodeSha256' => $manifest['geometrySha256']];
    }
    protected function views(string $request): array
    {
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/analysis_views.py'), '--request', $request], null, $env, null, 90);
        $process->run(); $result = json_decode($process->getOutput(), true);
        abort_unless($process->isSuccessful() && ($result['status'] ?? null) === 'completed', 422, 'Original source context preparation failed.');
        return $result;
    }
    private function child(IguideLabWorkflowItem $item, string $kind, string $id): IguideLabWorkflowStep
    {
        $step = IguideLabWorkflowStep::where('project_id', $item->project_id)->where('item_id', $item->id)->where('kind', $kind)->where('child_id', $id)->sole();
        abort_unless($step->input_sha256 === LabAnalysisRecipes::hash($step->input), 422, 'The machine child reservation changed.');
        return $step;
    }
    public function file(string $projectId, string $relative, string $sha, int $maxBytes): string
    {
        return app(LabStagingAssessmentContext::class)->file($projectId, $relative, $sha, $maxBytes);
    }
    private function putOnce(string $path, string $bytes): void
    {
        $disk = Storage::disk(LabService::DISK);
        if ($disk->exists($path)) { abort_unless(hash_equals(hash('sha256', $bytes), hash_file('sha256', $disk->path($path))), 422, 'Prepared original source context changed.'); return; }
        abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 503, 'Grass context storage is unavailable.');
    }
}
