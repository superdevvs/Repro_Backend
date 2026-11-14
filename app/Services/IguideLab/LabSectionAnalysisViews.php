<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Versioned six-input preparation. The existing single-cut/v1 preparer remains unchanged. */
class LabSectionAnalysisViews
{
    public function prepare(IguideLabAnalysis $record, IguideLabCase $case, array $snapshot): array
    {
        app(LabAnalysisSourceContext::class)->verify($case, $snapshot);
        $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$record->project_id.'/analyses/'.$record->id;
        app(LabStorage::class)->prepareAnalysis($record->project_id, $record->id);
        $png = LabImages::prepared($disk->get($case->source_path)); $size = getimagesizefromstring($png);
        abort_unless([$size[0], $size[1]] === [2048, 2048], 422, 'The authoritative section must remain 2048 square.');
        $this->put($base.'/source.png', $png);
        $views = [['id' => 'source', 'path' => $base.'/source.png', 'sha256' => hash('sha256', $png), 'width' => 2048, 'height' => 2048,
            'projection' => 'rectilinear', 'role' => 'authoritative_edit_cut', 'yaw' => $snapshot['binding']['view']['yaw'],
            'pitch' => $snapshot['binding']['view']['pitch'], 'hFov' => $snapshot['binding']['view']['hFov']]];
        unset($png);
        $native = IguideLabCase::where('project_id', $record->project_id)->findOrFail($snapshot['binding']['nativeCaseId']);
        $parent = LabImages::prepared($disk->get($native->source_path)); $size = getimagesizefromstring($parent);
        abort_unless([$size[0], $size[1]] === [2048, 1024], 422, 'The parent context must remain a 2:1 panorama.');
        $parentHash = hash('sha256', $parent); $this->put($base.'/parent_source.png', $parent); unset($parent);
        $views[] = ['id' => 'parent_source', 'path' => $base.'/parent_source.png', 'sha256' => $parentHash, 'width' => 2048, 'height' => 1024,
            'projection' => 'equirectangular', 'role' => 'same_capture_context_only'];
        $request = ['schemaVersion' => 1, 'source' => ['path' => $disk->path($base.'/parent_source.png'), 'sha256' => $parentHash],
            'outputDirectory' => $disk->path($base.'/parent-diagnostics')];
        $this->put($base.'/parent-views-request.json', json_encode($request, JSON_THROW_ON_ERROR));
        $geometry = $this->diagnostics($disk->path($base.'/parent-views-request.json'));
        abort_unless(($geometry['source']['sha256'] ?? null) === $parentHash
            && ($geometry['adapterSha256'] ?? null) === $snapshot['binding']['preparationHashes']['diagnosticAdapter']
            && ($geometry['geometrySha256'] ?? null) === $snapshot['binding']['preparationHashes']['geometry']
            && array_column($geometry['views'] ?? [], 'id') === ['front', 'right', 'back', 'left', 'zenith', 'nadir'], 422, 'Parent diagnostic geometry changed.');
        $angles = ['front' => [0, 0], 'right' => [90, 0], 'back' => [180, 0], 'left' => [270, 0], 'zenith' => [0, 90], 'nadir' => [0, -90]];
        foreach ($geometry['views'] as $view) {
            $relative = $base.'/parent-diagnostics/views/'.$view['id'].'.png';
            abort_unless(($view['path'] ?? null) === $disk->path($relative) && $view['width'] === 1024 && $view['height'] === 1024
                && (float) $view['hfov_degrees'] === 100.0
                && [(float) $view['yaw_degrees'], (float) $view['pitch_degrees']] === array_map('floatval', $angles[$view['id']])
                && hash_equals($view['sha256'], hash_file('sha256', $disk->path($relative))), 422, 'Parent diagnostic pose or bytes changed.');
            $bytes = $disk->get($relative); $size = @getimagesizefromstring($bytes);
            abort_unless($size && [$size[0], $size[1], $size['mime']] === [1024, 1024, 'image/png'], 422, 'Parent diagnostic dimensions changed.');
            if (in_array($view['id'], ['zenith', 'nadir'], true)) continue; // No hidden claim of detailed pole coverage.
            $id = 'parent_'.$view['id']; $this->put($base.'/'.$id.'.png', $bytes);
            $views[] = ['id' => $id, 'path' => $base.'/'.$id.'.png', 'sha256' => $view['sha256'], 'width' => 1024, 'height' => 1024,
                'projection' => 'rectilinear', 'role' => 'same_capture_context_only', 'yaw' => $view['yaw_degrees'], 'pitch' => $view['pitch_degrees'], 'hFov' => 100];
        }
        $manifest = ['version' => LabAnalysisSourceContext::VERSION, 'binding' => $snapshot['binding'], 'bindingSha256' => $snapshot['bindingSha256'],
            'views' => array_map(function ($view) { unset($view['path']); return $view; }, $views)];
        $hash = LabAnalysisRecipes::hash($manifest);
        $this->put($base.'/source-context-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        app(LabAnalysisSourceContext::class)->verify($case->fresh(), $snapshot);
        return ['preparedSourceSha256' => $views[0]['sha256'], 'views' => $views, 'sourceContextManifest' => $manifest,
            'sourceContextManifestSha256' => $hash, 'geometrySha256' => $snapshot['binding']['geometrySha256'],
            'coordinateConvention' => $geometry['convention'] ?? null, 'diagnosticOnly' => true];
    }

    protected function diagnostics(string $requestPath): array
    {
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '2', 'OPENBLAS_NUM_THREADS' => '2']);
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/analysis_views.py'), '--request', $requestPath], null, $env, null, 90);
        $process->run(); $result = json_decode($process->getOutput(), true);
        abort_unless($process->isSuccessful() && ($result['status'] ?? null) === 'completed', 422, 'Parent diagnostics could not be prepared.');
        return $result['manifest'];
    }

    public function verifyStored(IguideLabAnalysis $record): void
    {
        $data = $record->data; $manifest = $data['sourceContextManifest'] ?? []; $snapshot = $data['sourceContext'] ?? [];
        abort_unless(hash_equals($data['sourceContextManifestSha256'] ?? '', LabAnalysisRecipes::hash($manifest))
            && ($manifest['bindingSha256'] ?? null) === ($snapshot['bindingSha256'] ?? null)
            && LabAnalysisRecipes::hash($manifest['binding'] ?? []) === LabAnalysisRecipes::hash($snapshot['binding'] ?? [])
            && array_column($data['views'] ?? [], 'id') === LabAnalysisSourceContext::IDS, 422, 'The stored context manifest changed.');
        $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$record->project_id.'/analyses/'.$record->id.'/';
        foreach ($data['views'] as $i => $view) {
            $safe = $view; unset($safe['path']);
            abort_unless($safe === ($manifest['views'][$i] ?? null) && $view['path'] === $base.$view['id'].'.png'
                && $disk->exists($view['path']) && hash_equals($view['sha256'], hash_file('sha256', $disk->path($view['path']))), 422, 'A stored context input changed.');
        }
    }

    private function put(string $path, string $bytes): void
    {
        $disk = Storage::disk(LabService::DISK);
        if ($disk->exists($path)) abort_unless(hash_equals(hash('sha256', $bytes), hash_file('sha256', $disk->path($path))), 422, 'Cached context preparation changed.');
        else abort_unless($disk->put($path, $bytes, ['visibility' => 'private']), 500, 'Private context storage failed.');
    }
}
