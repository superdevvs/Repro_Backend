<?php
namespace App\Services\IguideLab;

use App\Models\IguideLabGrassSourceAdmission;
use App\Models\IguideLabWorkflowItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Actual owned native source only. Optional details never enlarge edit scope. */
class LabGrassSourceAdmissionContext
{
    public function capture(IguideLabWorkflowItem $item, ?string $priorAdmissionId): array
    {
        $native = app(LabWorkflowSources::class)->verify($item->project_id, $item->source);
        abort_unless($item->preset === 'grass' && $native->projection === 'equirectangular'
            && $native->width >= 2048 && $native->width === $native->height * 2
            && $native->width * $native->height <= 64000000, 422, 'Original source is outside bounded admission support.');
        $disk = Storage::disk(LabService::DISK);
        $nativePath = $this->file($item->project_id, $native->source_path, $native->source_sha256, 128 * 1024 * 1024);
        $prepared = LabImages::prepared($disk->get($native->source_path)); $preparedSha = hash('sha256', $prepared);
        $preparation = ['nativeSourceSha256' => $native->source_sha256, 'sourceSha256' => $preparedSha,
            'preparerSha256' => hash_file('sha256', (new \ReflectionClass(LabImages::class))->getFileName()), 'method' => 'LabImages::prepared/GD'];
        $preparationSha = LabMachineSegmentationPlan::canonicalHash($preparation);
        $authoritySha = LabMachineSegmentationPlan::canonicalHash($item->source);
        app(LabStorage::class)->prepareWorkflow($item->project_id, $item->id);
        $base = 'iguide-lab/'.$item->project_id.'/workflow-items/'.$item->id.'/grass-source-admission';
        $this->putOnce($base.'/source.png', $prepared);
        $viewRequest = ['schemaVersion' => 1, 'source' => ['path' => $disk->path($base.'/source.png'), 'sha256' => $preparedSha], 'outputDirectory' => $disk->path($base.'/overview-views')];
        $this->putOnce($base.'/overview-request.json', json_encode($viewRequest, JSON_THROW_ON_ERROR));
        $views = $this->execute('analysis_views.py', $disk->path($base.'/overview-request.json'), $item->project_id);
        $manifest = $views['manifest'] ?? []; $manifestPath = $disk->path($base.'/overview-views/manifest.json');
        abort_unless(is_file($manifestPath) && ($manifest['adapterSha256'] ?? null) === hash_file('sha256', resource_path('iguide-lab/analysis_views.py'))
            && ($manifest['geometrySha256'] ?? null) === hash_file('sha256', resource_path('iguide-lab/pano_geometry.py'))
            && ($manifest['source']['sha256'] ?? null) === $preparedSha
            && array_column($manifest['views'] ?? [], 'id') === ['front', 'right', 'back', 'left', 'zenith', 'nadir'], 422);
        $geometrySha = hash_file('sha256', $manifestPath); $evidence = $assets = [];
        $append = function ($id, $path, $sha, $width, $height, $projection, $pose, $derivation) use (&$evidence, &$assets, $native) {
            $evidence[] = ['id' => $id, 'sha256' => $sha, 'captureId' => $native->id, 'nativeSourceSha256' => $native->source_sha256,
                'derivationSha256' => $derivation, 'width' => $width, 'height' => $height, 'projection' => $projection, 'pose' => $pose];
            $assets[] = ['id' => $id, 'path' => $path, 'sha256' => $sha];
        };
        $append('source_panorama', $base.'/source.png', $preparedSha, 2048, 1024, 'equirectangular', null, $preparationSha);
        foreach ($manifest['views'] as $i => $view) {
            [$yaw, $pitch] = [[0, 0], [90, 0], [180, 0], [270, 0], [0, 90], [0, -90]][$i];
            $path = $base.'/overview-views/views/'.$view['id'].'.png'; $file = $this->file($item->project_id, $path, $view['sha256'], 8 * 1024 * 1024);
            abort_unless(realpath($view['path']) === $file && (float) $view['yaw_degrees'] === (float) $yaw
                && (float) $view['pitch_degrees'] === (float) $pitch && (float) $view['hfov_degrees'] === 100.0 && [$view['width'], $view['height']] === [1024, 1024], 422);
            $append('source_'.$view['id'], $path, $view['sha256'], 1024, 1024, 'rectilinear', ['yaw' => $yaw, 'pitch' => $pitch, 'hFov' => 100, 'width' => 1024, 'height' => 1024], $geometrySha);
        }
        $prior = null;
        if ($priorAdmissionId !== null) {
            $record = IguideLabGrassSourceAdmission::where('project_id', $item->project_id)->findOrFail($priorAdmissionId);
            abort_unless(($record->data['contextSnapshot']['context']['round'] ?? null) === 0, 422, 'Only one source detail follow-up is allowed.');
            $result = app(LabGrassSourceAdmissions::class)->verifiedReport($record, $item);
            abort_unless(($result['status'] ?? null) === 'needs_detail' && count($result['detailPlan'] ?? []) >= 1 && count($result['detailPlan']) <= 2, 422, 'The prior source assessment did not request bounded details.');
            $prior = ['assessmentId' => $record->id, 'resultSha256' => $record->result_sha256, 'proposal' => $result['proposal']];
            $detailBase = $base.'/details-'.$record->id;
            $request = ['schemaVersion' => 1, 'source' => ['path' => $nativePath, 'sha256' => $native->source_sha256],
                'requests' => $result['detailPlan'], 'outputDirectory' => $disk->path($detailBase)];
            $this->putOnce($base.'/details-'.$record->id.'-request.json', json_encode($request, JSON_THROW_ON_ERROR));
            $details = $this->execute('grass_source_detail_views.py', $disk->path($base.'/details-'.$record->id.'-request.json'), $item->project_id);
            abort_unless(($details['status'] ?? null) === 'completed' && ($details['sameCaptureOnly'] ?? false) === true
                && ($details['editScopeExpanded'] ?? true) === false && ($details['providerCalls'] ?? -1) === 0
                && ($details['source'] ?? null) === $request['source'] && count($details['views'] ?? []) === count($result['detailPlan']), 422);
            $detailManifest = $this->file($item->project_id, $detailBase.'/manifest.json', $details['manifest']['sha256'] ?? '', 65536);
            abort_unless(realpath($details['manifest']['path'] ?? '') === $detailManifest, 422);
            foreach ($details['views'] as $i => $view) {
                $id = 'source_detail_'.($i + 1); $path = $detailBase.'/views/'.$id.'.png';
                $file = $this->file($item->project_id, $path, $view['sha256'] ?? '', 8 * 1024 * 1024);
                $pose = $result['detailPlan'][$i]['pose'] + ['width' => 1024, 'height' => 1024];
                abort_unless(($view['id'] ?? null) === $id && realpath($view['path'] ?? '') === $file
                    && LabMachineSegmentationPlan::canonicalHash($view['pose'] ?? []) === LabMachineSegmentationPlan::canonicalHash($pose), 422);
                $append($id, $path, $view['sha256'], 1024, 1024, 'rectilinear', $pose, $details['manifest']['sha256']);
            }
        }
        $context = ['schemaVersion' => LabGrassSourceAdmissionPolicy::VERSION, 'binding' => ['workflowItemId' => $item->id, 'captureId' => $native->id,
            'nativeSourceSha256' => $native->source_sha256, 'sourceSha256' => $preparedSha, 'sourcePreparationSha256' => $preparationSha, 'sourceAuthoritySha256' => $authoritySha],
            'policy' => LabGrassSourceAdmissionPolicy::contextPolicy(), 'round' => $prior === null ? 0 : 1, 'prior' => $prior, 'evidence' => $evidence];
        return ['context' => $context, 'assets' => $assets, 'sourcePreparation' => $preparation,
            'contextBuilderSha256' => hash_file('sha256', __FILE__), 'sourceAuthoritySha256' => $authoritySha];
    }
    protected function execute(string $worker, string $request, string $projectId): array
    {
        abort_unless(in_array($worker, ['analysis_views.py', 'grass_source_detail_views.py'], true), 422);
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
        $args = [(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/'.$worker), '--request', $request];
        if ($worker === 'grass_source_detail_views.py') array_push($args, '--allowed-root', Storage::disk(LabService::DISK)->path('iguide-lab/'.$projectId));
        // A follow-up capture has at most three 30-second local processes,
        // leaving the existing 600-second job budget for compile/receipt work.
        $process = new Process($args, null, $env, null, 30); $process->run(); $result = json_decode($process->getOutput(), true);
        abort_unless($process->isSuccessful() && ($result['status'] ?? null) === 'completed', 422, 'Original source diagnostic preparation failed.');
        return $result;
    }
    public function file(string $projectId, string $path, string $sha, int $maxBytes): string
    {
        return app(LabStagingAssessmentContext::class)->file($projectId, $path, $sha, $maxBytes);
    }
    private function putOnce(string $path, string $bytes): void
    {
        $disk = Storage::disk(LabService::DISK);
        if ($disk->exists($path)) { abort_unless(! is_link($disk->path($path)) && hash_equals(hash('sha256', $bytes), hash_file('sha256', $disk->path($path))), 422, 'Original source context changed.'); return; }
        abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 503);
    }
}
