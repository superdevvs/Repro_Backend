<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabAnalysis;
use App\Models\IguideLabCase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class LabAnalysisViews
{
    public function ready(): bool
    {
        return is_executable((string) config('iguide-lab.pipeline_python_path')) && is_file(resource_path('iguide-lab/analysis_views.py'));
    }

    public function prepare(IguideLabAnalysis $analysis, IguideLabCase $case): array
    {
        $disk = Storage::disk(LabService::DISK); $base = 'iguide-lab/'.$analysis->project_id.'/analyses/'.$analysis->id;
        app(LabStorage::class)->prepareAnalysis($analysis->project_id, $analysis->id);
        if (! $disk->exists($case->source_path) || ! hash_equals($analysis->source_sha256, hash_file('sha256', $disk->path($case->source_path)))) throw new RuntimeException('Source fingerprint mismatch.');
        $png = LabImages::prepared($disk->get($case->source_path)); $hash = hash('sha256', $png); $sourcePath = $base.'/source.png';
        if ($disk->exists($sourcePath) && ! hash_equals($hash, hash_file('sha256', $disk->path($sourcePath)))) throw new RuntimeException('Prepared source changed.');
        $disk->put($sourcePath, $png, ['visibility' => 'private']); $size = getimagesizefromstring($png);
        $views = [['id' => 'source', 'path' => $sourcePath, 'sha256' => $hash, 'width' => $size[0], 'height' => $size[1], 'projection' => $case->projection]];
        $geometry = null;
        if ($case->projection === 'equirectangular') {
            if ($size[0] !== 2048 || $size[1] !== 1024) throw new RuntimeException('Analysis requires a panorama at least 2048 pixels wide.');
            $request = ['schemaVersion' => 1, 'source' => ['path' => $disk->path($sourcePath), 'sha256' => $hash], 'outputDirectory' => $disk->path($base.'/diagnostics')];
            $disk->put($base.'/views-request.json', json_encode($request, JSON_THROW_ON_ERROR), ['visibility' => 'private']);
            $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
            // Explicit values must override inherited-variable removal, including PATH.
            $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '2', 'OPENBLAS_NUM_THREADS' => '2']);
            $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/analysis_views.py'), '--request', $disk->path($base.'/views-request.json')], null, $env, null, 90);
            $process->run(); $result = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ($result['status'] ?? '') !== 'completed') throw new RuntimeException('Diagnostic view preparation failed.');
            $geometry = $result['manifest'];
            if (($geometry['source']['sha256'] ?? '') !== $hash || array_column($geometry['views'] ?? [], 'id') !== ['front', 'right', 'back', 'left', 'zenith', 'nadir']) throw new RuntimeException('Diagnostic geometry mismatch.');
            foreach ($geometry['views'] as $view) {
                $relative = $base.'/diagnostics/views/'.$view['id'].'.png';
                if ($view['path'] !== $disk->path($relative) || $view['width'] !== 1024 || $view['height'] !== 1024 || ! hash_equals($view['sha256'], hash_file('sha256', $disk->path($relative)))) throw new RuntimeException('Diagnostic bytes mismatch.');
                // Publish only copies using the existing shared private permissions.
                $path = $base.'/'.$view['id'].'.png'; $disk->put($path, $disk->get($relative), ['visibility' => 'private']);
                $views[] = ['id' => $view['id'], 'path' => $path, 'sha256' => $view['sha256'], 'width' => 1024, 'height' => 1024, 'projection' => 'rectilinear',
                    'yaw' => $view['yaw_degrees'], 'pitch' => $view['pitch_degrees'], 'hFov' => $view['hfov_degrees']];
            }
        }
        return ['preparedSourceSha256' => $hash, 'views' => $views, 'geometrySha256' => $geometry['geometrySha256'] ?? null,
            'coordinateConvention' => $geometry['convention'] ?? null, 'diagnosticOnly' => true];
    }
}
