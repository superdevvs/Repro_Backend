<?php
namespace App\Services\IguideLab;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Fixed local protocol executable; production remains off without its own validated manifest. */
class LabStagingAssessmentBridge
{
    public function snapshot(): array
    {
        $manifest = (string) config('iguide-lab.staging_assessment_runtime_manifest');
        return ['version' => 'staging-assessment-filesystem/1', 'adapterSha256' => hash_file('sha256', __FILE__),
            'protocolSha256' => hash_file('sha256', resource_path('iguide-lab/staging_assessment_protocol.py')),
            'bridgeSha256' => hash_file('sha256', resource_path('iguide-lab/staging_assessment_bridge.py')),
            'contextSha256' => hash_file('sha256', (new \ReflectionClass(LabStagingAssessmentContext::class))->getFileName()),
            'preparerSha256' => hash_file('sha256', (new \ReflectionClass(LabImages::class))->getFileName()),
            'viewAdapterSha256' => hash_file('sha256', resource_path('iguide-lab/analysis_views.py')),
            'geometrySha256' => hash_file('sha256', resource_path('iguide-lab/pano_geometry.py')),
            'runtimeManifestSha256' => is_file($manifest) ? hash_file('sha256', $manifest) : null];
    }
    public function validate(array $reserved): void
    {
        $current = $this->snapshot(); ksort($current); ksort($reserved);
        abort_unless($current === $reserved, 422, 'The reserved assessment implementation changed.');
    }
    public function ready(): bool
    {
        try {
            $path = (string) config('iguide-lab.staging_assessment_runtime_manifest');
            abort_unless(is_file($path) && ! is_link($path) && filesize($path) <= 65536, 503);
            $runtime = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR); $snapshot = $this->snapshot();
            abort_unless(($runtime['validationStatus'] ?? null) === 'passed' && ($runtime['pythonVersion'] ?? null) === '3.12.3'
                && ($runtime['pillowVersion'] ?? null) === '13.0.0' && ($runtime['pythonPath'] ?? null) === config('iguide-lab.pipeline_python_path')
                && is_executable($runtime['pythonPath']), 503);
            foreach (['adapterSha256', 'protocolSha256', 'bridgeSha256', 'contextSha256', 'preparerSha256', 'viewAdapterSha256', 'geometrySha256'] as $key) {
                abort_unless(($runtime['validated'.ucfirst($key)] ?? null) === $snapshot[$key], 503);
            }
            return true;
        } catch (\Throwable) { return false; }
    }
    public function run(string $projectId, string $relativeRequest, array $request, array $snapshot): array
    {
        $this->validate($snapshot); abort_unless($this->ready(), 503, 'Validated staging assessment runtime is unavailable.');
        abort_unless(str_starts_with($relativeRequest, 'iguide-lab/'.$projectId.'/staging-assessments/'), 422);
        $disk = Storage::disk(LabService::DISK); $this->json($relativeRequest, $request);
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/staging_assessment_bridge.py'),
            '--request', $disk->path($relativeRequest), '--allowed-root', $disk->path('iguide-lab/'.$projectId)], null, $env, null, 90);
        $process->run(); $report = json_decode($process->getOutput(), true);
        abort_unless($process->isSuccessful() && is_array($report) && ($report['automaticAcceptance'] ?? true) === false
            && ($report['providerCalls'] ?? -1) === 0 && ($report['reviewStatus'] ?? null) === 'unreviewed'
            && ($report['operation'] ?? null) === $request['operation'], 422, 'The local assessment protocol rejected its inputs.');
        return $report;
    }
    public function json(string $relative, array $value): array
    {
        return $this->bytes($relative, json_encode($value, JSON_THROW_ON_ERROR));
    }
    public function bytes(string $relative, string $bytes): array
    {
        $disk = Storage::disk(LabService::DISK); $sha = hash('sha256', $bytes);
        if ($disk->exists($relative)) abort_unless(! is_link($disk->path($relative)) && hash_equals($sha, hash_file('sha256', $disk->path($relative))), 422, 'An immutable assessment file changed.');
        else abort_unless($disk->put($relative, $bytes, ['visibility' => 'private']), 503, 'Private assessment storage is unavailable.');
        return ['path' => $disk->path($relative), 'sha256' => $sha];
    }
    public function output(string $projectId, array $report, string $key, int $limit = 1024 * 1024): array
    {
        $descriptor = $report['outputs'][$key] ?? []; $disk = Storage::disk(LabService::DISK);
        $root = realpath($disk->path('iguide-lab/'.$projectId)); $path = $descriptor['path'] ?? ''; $real = realpath($path);
        abort_unless($root && $real && ! is_link($path) && str_starts_with($real, $root.DIRECTORY_SEPARATOR)
            && is_file($real) && filesize($real) <= $limit && filesize($real) === ($descriptor['bytes'] ?? null)
            && hash_equals($descriptor['sha256'] ?? '', hash_file('sha256', $real)), 422, 'The protocol output changed.');
        return ['path' => $real, 'sha256' => $descriptor['sha256']];
    }
}
