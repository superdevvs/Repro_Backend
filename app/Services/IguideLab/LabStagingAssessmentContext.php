<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use App\Models\IguideLabRun;
use App\Models\IguideLabSection;
use App\Models\IguideLabWorkflowItem;
use App\Models\IguideLabWorkflowStep;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Loads actual machine child records and original same-capture context; no client paths or attestations. */
class LabStagingAssessmentContext
{
    public function capture(IguideLabWorkflowItem $item, string $sectionId, string $runId): array
    {
        $native = app(LabWorkflowSources::class)->verify($item->project_id, $item->source);
        $section = IguideLabSection::where('project_id', $item->project_id)->where('status', 'completed')->findOrFail($sectionId);
        $run = IguideLabRun::where('project_id', $item->project_id)->where('status', 'completed')->findOrFail($runId);
        $cut = IguideLabCase::where('project_id', $item->project_id)->findOrFail($section->derived_case_id);
        abort_unless($item->preset === 'staging' && $section->case_id === $native->id && $section->source_sha256 === $native->source_sha256
            && $run->case_id === $cut->id && $run->preset === 'staging' && $run->style === $item->style
            && $cut->projection === 'rectilinear' && [$cut->width, $cut->height] === [2048, 2048]
            && ($run->options['sectionId'] ?? null) === $section->id, 422, 'Assessment source and actual candidate are not the same workflow section.');
        $sectionStep = $this->child($item, 'section', $section->id);
        $imageStep = $this->child($item, 'image', $run->id);
        abort_unless(($run->options['workflowInvocation'] ?? null) === app(LabWorkflowSteps::class)->invocation($imageStep), 422, 'The actual image is not a machine workflow child.');
        $analysis = IguideLabAnalysis::where('project_id', $item->project_id)->where('status', 'completed')->findOrFail($run->options['sourceAnalysis']['id'] ?? '');
        $analysisStep = $this->child($item, 'analysis', $analysis->id);
        abort_unless($analysis->case_id === $cut->id && $analysis->source_sha256 === $cut->source_sha256
            && ($analysis->data['workflowInvocation'] ?? null) === app(LabWorkflowSteps::class)->invocation($analysisStep)
            && $analysis->proposal_sha256 === LabAnalysisRecipes::hash($analysis->proposal)
            && ($run->options['sourceAnalysis']['proposalSha256'] ?? null) === $analysis->proposal_sha256, 422, 'The actual section draft is not a verified workflow proposal.');
        $disk = Storage::disk(LabService::DISK);
        $cutPath = $this->file($item->project_id, $cut->source_path, $cut->source_sha256, 32 * 1024 * 1024);
        $cutSize = @getimagesize($cutPath); abort_unless($cutSize && [$cutSize[0], $cutSize[1], $cutSize['mime']] === [2048, 2048, 'image/png'], 422);
        abort_unless($run->output_path === 'iguide-lab/'.$item->project_id.'/runs/'.$run->id.'.png' && is_string($run->output_sha256), 422);
        $candidatePath = $this->file($item->project_id, $run->output_path, $run->output_sha256, 32 * 1024 * 1024);
        $candidateSize = @getimagesize($candidatePath); abort_unless($candidateSize && [$candidateSize[0], $candidateSize[1], $candidateSize['mime']] === [2048, 2048, 'image/png'], 422);
        $geometryPath = 'iguide-lab/'.$item->project_id.'/sections/'.$section->id.'/geometry/cut/manifest.json';
        abort_unless(($section->data['geometryPath'] ?? null) === $geometryPath, 422, 'The extraction manifest is not privately owned.');
        $geometryFile = $this->file($item->project_id, $geometryPath, $section->data['geometrySha256'] ?? '', 65536);
        $geometry = json_decode(file_get_contents($geometryFile), true, flags: JSON_THROW_ON_ERROR);
        $actualView = $geometry['views'][0] ?? [];
        abort_unless(count($geometry['views'] ?? []) === 1 && ($actualView['id'] ?? null) === 'section'
            && ($geometry['source']['sha256'] ?? null) === $native->source_sha256
            && realpath($geometry['source']['path'] ?? '') === realpath($disk->path($native->source_path))
            && [$geometry['source']['width'] ?? null, $geometry['source']['height'] ?? null] === [$native->width, $native->height]
            && ($actualView['sha256'] ?? null) === $cut->source_sha256 && ($actualView['projection'] ?? null) === 'rectilinear'
            && [$actualView['width'] ?? null, $actualView['height'] ?? null] === [2048, 2048]
            && ($section->data['cutSha256'] ?? null) === $cut->source_sha256, 422, 'The native extraction binding changed.');
        foreach (['yaw' => 'yaw_degrees', 'pitch' => 'pitch_degrees', 'hFov' => 'hfov_degrees'] as $key => $field) {
            abort_unless(is_numeric($section->view[$key] ?? null) && (float) ($actualView[$field] ?? INF) === (float) $section->view[$key], 422, 'Actual section pose differs from its extraction.');
        }
        $yaw = fmod((float) $section->view['yaw'] + 180, 360); if ($yaw < 0) $yaw += 360;
        $pose = ['yaw' => $yaw - 180, 'pitch' => (float) $section->view['pitch'], 'hFov' => (float) $section->view['hFov'], 'width' => 2048, 'height' => 2048];
        abort_unless($pose['pitch'] >= -60 && $pose['pitch'] <= 15 && $pose['hFov'] >= 30 && $pose['hFov'] <= 120, 422, 'The automatic staging pose is unsupported.');
        $preparedCut = LabImages::prepared($disk->get($cut->source_path)); $preparedSha = hash('sha256', $preparedCut);
        abort_unless(($run->options['preparedInputSha256'] ?? null) === [$preparedSha]
            && ($analysis->data['preparedSourceSha256'] ?? null) === $preparedSha, 422, 'Actual submitted source preparation changed.');
        $preparer = hash_file('sha256', (new \ReflectionClass(LabImages::class))->getFileName());
        $preparation = ['sourceCutSha256' => $cut->source_sha256, 'submittedSourceSha256' => $preparedSha, 'preparerSha256' => $preparer, 'method' => 'LabImages::prepared/GD'];
        app(LabStorage::class)->prepareWorkflow($item->project_id, $item->id);
        $base = 'iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/staging-context-'.$run->id;
        $normalizedGeometry = ['schemaVersion' => 1, 'projection' => 'rectilinear', 'width' => 2048, 'height' => 2048,
            'pose' => array_intersect_key($pose, array_flip(['yaw', 'pitch', 'hFov'])), 'nativeSourceSha256' => $native->source_sha256, 'sourceCutSha256' => $cut->source_sha256,
            'extractionReceiptSha256' => $section->data['geometrySha256'], 'originalPose' => $section->view];
        $normalizedBytes = json_encode($normalizedGeometry, JSON_THROW_ON_ERROR); $normalizedSha = hash('sha256', $normalizedBytes);
        $this->putOnce($base.'/geometry.json', $normalizedBytes);
        $overview = LabImages::prepared($disk->get($native->source_path)); $overviewSha = hash('sha256', $overview);
        $this->putOnce($base.'/parent.png', $overview);
        $viewRequest = ['schemaVersion' => 1, 'source' => ['path' => $disk->path($base.'/parent.png'), 'sha256' => $overviewSha], 'outputDirectory' => $disk->path($base.'/parent-views')];
        $this->putOnce($base.'/views-request.json', json_encode($viewRequest, JSON_THROW_ON_ERROR));
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/analysis_views.py'), '--request', $disk->path($base.'/views-request.json')], null, $env, null, 90);
        $process->run(); $views = json_decode($process->getOutput(), true);
        abort_unless($process->isSuccessful() && ($views['status'] ?? null) === 'completed', 422, 'Original parent context preparation failed.');
        $manifest = $views['manifest']; $parentGeometrySha = hash_file('sha256', $disk->path($base.'/parent-views/manifest.json'));
        abort_unless(($manifest['adapterSha256'] ?? null) === hash_file('sha256', resource_path('iguide-lab/analysis_views.py'))
            && ($manifest['geometrySha256'] ?? null) === hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'))
            && ($manifest['source']['sha256'] ?? null) === $overviewSha
            && array_column($manifest['views'] ?? [], 'id') === ['front', 'right', 'back', 'left', 'zenith', 'nadir'], 422, 'Parent context derivation changed.');
        $evidence = $assets = [];
        $append = function ($id, $path, $sha, $width, $height, $projection, $viewPose, $derivation) use (&$evidence, &$assets, $native) {
            $evidence[] = ['id' => $id, 'sha256' => $sha, 'captureId' => $native->id, 'nativeSourceSha256' => $native->source_sha256,
                'derivationSha256' => $derivation, 'width' => $width, 'height' => $height, 'projection' => $projection, 'pose' => $viewPose];
            $assets[] = ['id' => $id, 'path' => $path, 'sha256' => $sha];
        };
        $append('source_cut', $cut->source_path, $cut->source_sha256, 2048, 2048, 'rectilinear', $pose, $normalizedSha);
        $generation = ['runId' => $run->id, 'requestId' => $run->request_id, 'modelId' => $run->model_id, 'provider' => $run->provider, 'prompt' => $run->prompt, 'options' => $run->options, 'outputSha256' => $run->output_sha256];
        $generationSha = LabAnalysisRecipes::hash($generation);
        $append('candidate_cut', $run->output_path, $run->output_sha256, 2048, 2048, 'rectilinear', $pose, $generationSha);
        $append('parent_overview', $base.'/parent.png', $overviewSha, 2048, 1024, 'equirectangular', null, LabAnalysisRecipes::hash(['nativeSourceSha256' => $native->source_sha256, 'preparedSha256' => $overviewSha, 'preparerSha256' => $preparer]));
        foreach (array_slice($manifest['views'], 0, 4) as $index => $view) {
            $path = $base.'/parent-views/views/'.$view['id'].'.png'; $file = $this->file($item->project_id, $path, $view['sha256'], 8 * 1024 * 1024);
            $expected = ['yaw' => $index * 90, 'pitch' => 0, 'hFov' => 100, 'width' => 1024, 'height' => 1024];
            abort_unless(realpath($view['path']) === $file && (float) $view['yaw_degrees'] === (float) $expected['yaw']
                && (float) $view['pitch_degrees'] === 0.0 && (float) $view['hfov_degrees'] === 100.0
                && [$view['width'], $view['height']] === [1024, 1024], 422, 'Parent cardinal pose changed.');
            $append('parent_'.$view['id'], $path, $view['sha256'], 1024, 1024, 'rectilinear', $expected, $parentGeometrySha);
        }
        $context = ['schemaVersion' => LabStagingAssessmentPolicy::VERSION, 'binding' => ['workflowItemId' => $item->id, 'captureId' => $native->id,
            'nativeSourceSha256' => $native->source_sha256, 'sourceCutSha256' => $cut->source_sha256, 'submittedSourceSha256' => $preparedSha,
            'candidateCutSha256' => $run->output_sha256, 'parentContextSha256' => $overviewSha, 'sourceGeometrySha256' => $normalizedSha,
            'sourcePreparationSha256' => LabAnalysisRecipes::hash($preparation), 'sourceAnalysisSha256' => $analysis->proposal_sha256, 'generationSettingsSha256' => $generationSha],
            'pose' => $pose, 'evidence' => $evidence];
        return ['context' => $context, 'assets' => $assets, 'sectionId' => $section->id, 'candidateRunId' => $run->id,
            'sourceAnalysisId' => $analysis->id, 'geometry' => ['path' => $base.'/geometry.json', 'sha256' => $normalizedSha],
            'sourcePreparation' => $preparation, 'contextBuilderSha256' => hash_file('sha256', __FILE__),
            'parentViewAdapterSha256' => $manifest['adapterSha256'], 'geometryCodeSha256' => $manifest['geometrySha256']];
    }

    private function child(IguideLabWorkflowItem $item, string $kind, string $id): IguideLabWorkflowStep
    {
        $step = IguideLabWorkflowStep::where('project_id', $item->project_id)->where('item_id', $item->id)->where('kind', $kind)->where('child_id', $id)->sole();
        abort_unless($step->input_sha256 === LabAnalysisRecipes::hash($step->input), 422, 'The machine child reservation changed.');
        return $step;
    }
    public function file(string $projectId, string $relative, string $sha, int $maxBytes): string
    {
        $disk = Storage::disk(LabService::DISK); $root = realpath($disk->path('iguide-lab/'.$projectId));
        $path = $disk->path($relative); $real = realpath($path);
        abort_unless($root && $real && str_starts_with($relative, 'iguide-lab/'.$projectId.'/')
            && $real === $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('iguide-lab/'.$projectId.'/')))
            && ! is_link($path) && is_file($real) && filesize($real) <= $maxBytes
            && preg_match('/\A[a-f0-9]{64}\z/D', $sha) && hash_equals($sha, hash_file('sha256', $real)), 422, 'Private assessment input changed.');
        return $real;
    }
    private function putOnce(string $path, string $bytes): void
    {
        $disk = Storage::disk(LabService::DISK);
        if ($disk->exists($path)) { abort_unless(hash_equals(hash('sha256', $bytes), hash_file('sha256', $disk->path($path))), 422, 'Prepared assessment context changed.'); return; }
        abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 503, 'Assessment storage is unavailable.');
    }
}
