<?php

namespace App\Services\IguideLab;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Optional local boundary correction. No network, credentials, mask expansion or acceptance. */
class LabHarmonization
{
    public const MODE = 'exact-domain-harmonic-v1';
    public const VERSION = 'exact-domain-harmonic/1';
    public const LIMITS = ['maxEditablePixels' => 131072, 'maxClippedPixelFraction' => 0.05, 'maxOvershoot' => 64];
    private const RUNTIME = ['pythonVersion' => '3.12.3', 'numpyVersion' => '2.5.4', 'pillowVersion' => '13.0.0', 'scipyVersion' => '1.18.1'];

    public function ready(): bool
    {
        try { $this->runtime(); return is_file(resource_path('iguide-lab/section_harmonization.py')) && is_file(resource_path('iguide-lab/harmonize_section.py')); }
        catch (\Throwable) { return false; }
    }

    public function snapshot(string $sourceCutSha256): array
    {
        if (! $this->ready() || ! preg_match('/^[a-f0-9]{64}$/', $sourceCutSha256)) throw new RuntimeException('Local harmonization is unavailable.');
        return ['mode' => self::MODE, 'recipeVersion' => self::VERSION,
            'solverSha256' => hash_file('sha256', resource_path('iguide-lab/section_harmonization.py')),
            'adapterSha256' => hash_file('sha256', resource_path('iguide-lab/harmonize_section.py')),
            'runtimeManifestSha256' => $this->runtime()['sha256'], 'sourceCutSha256' => $sourceCutSha256, 'limits' => self::LIMITS];
    }

    public function validate(array $snapshot): void
    {
        if ($snapshot !== $this->snapshot($snapshot['sourceCutSha256'] ?? '')) throw new RuntimeException('Local harmonization implementation or runtime changed.');
    }

    public function run(string $requestPath, array $snapshot): array
    {
        $this->validate($snapshot);
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
        $process = new Process([(string) config('iguide-lab.harmonization_python_path'), resource_path('iguide-lab/harmonize_section.py'), '--request', $requestPath,
            '--runtime-manifest', (string) config('iguide-lab.harmonization_runtime_manifest')], null, $env, null, 65);
        $process->run(); $report = json_decode($process->getOutput(), true);
        if (! is_array($report) || ($report['automaticAcceptance'] ?? true) !== false || ($report['reviewStatus'] ?? null) !== 'unreviewed'
            || ($report['recipe'] ?? null) !== $snapshot || ! in_array($report['status'] ?? null, ['completed', 'needs_attention'], true)
            || ! is_array($report['diagnostics'] ?? null) || ! $process->isSuccessful()
            || ($report['status'] === 'completed' && (! is_array($report['candidate'] ?? null) || ! is_array($report['clippingMask'] ?? null) || isset($report['errorCode'])))
            || ($report['status'] === 'needs_attention' && (isset($report['candidate']) || ! is_string($report['errorCode'] ?? null) || $report['errorCode'] === ''))) throw new RuntimeException('Local harmonization failed its bounded execution checks.');
        return $report;
    }

    public function dto(?array $snapshot, ?array $report, string $compositionId): ?array
    {
        if ($snapshot === null) return null;
        return $snapshot + ['status' => $report['status'] ?? 'pending', 'harmonizedCandidateSha256' => $report['candidate']['sha256'] ?? null,
            'clippingMaskSha256' => $report['clippingMask']['sha256'] ?? null,
            'clippingMaskAssetPath' => isset($report['clippingMask']) ? '/api/iguide-lab/compositions/'.$compositionId.'/harmonization-clipping' : null,
            'diagnostics' => $report['diagnostics'] ?? null, 'errorCode' => $report['errorCode'] ?? null, 'automaticAcceptance' => false];
    }

    private function runtime(): array
    {
        $python = (string) config('iguide-lab.harmonization_python_path'); $path = (string) config('iguide-lab.harmonization_runtime_manifest');
        if (! is_executable($python) || ! is_file($path) || filesize($path) > 65536) throw new RuntimeException('Pinned local harmonization runtime is unavailable.');
        $bytes = file_get_contents($path); $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['schemaVersion'] ?? null) !== 1 || ($manifest['validationStatus'] ?? null) !== 'passed' || ($manifest['pythonPath'] ?? null) !== $python) throw new RuntimeException('Pinned local harmonization runtime was not validated.');
        foreach (self::RUNTIME as $key => $version) if (($manifest[$key] ?? null) !== $version) throw new RuntimeException('Pinned local harmonization runtime version changed.');
        foreach (['validatedSolverSha256' => 'section_harmonization.py', 'validatedAdapterSha256' => 'harmonize_section.py'] as $key => $filename) {
            $source = resource_path('iguide-lab/'.$filename);
            if (! is_file($source) || ($manifest[$key] ?? null) !== hash_file('sha256', $source)) throw new RuntimeException('Local harmonization code has not been validated with this runtime.');
        }
        return ['sha256' => hash('sha256', $bytes), 'manifest' => $manifest];
    }
}
