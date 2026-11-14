<?php
namespace App\Services\IguideLab;

/** Server-only opt-in. An enabled flag alone never establishes runtime readiness. */
final class LabLightingD2
{
    public const VERSION = 'source-detail-linear-dimming-mutual-highlights/2';
    public const FILES = ['worker' => 'lighting_d2_worker.py', 'core' => 'lighting_transfer_d2.py',
        'qa' => 'workflow_qa.py', 'baseline' => 'lighting_transfer.py', 'global' => 'global_lighting.py', 'geometry' => 'pano_geometry.py'];

    public static function enabled(): bool { return config('iguide-lab.lighting_d2_enabled', false) === true; }
    public static function snapshot(): array
    {
        $files = [];
        foreach (self::FILES as $key => $file) $files[$key] = hash_file('sha256', resource_path('iguide-lab/'.$file));
        $path = (string) config('iguide-lab.lighting_d2_runtime_manifest');
        return ['recipeVersion' => self::VERSION, 'files' => $files,
            'authoritySha256' => hash_file('sha256', __FILE__),
            'runtimeManifestSha256' => is_file($path) ? hash_file('sha256', $path) : null];
    }
    public static function validateRuntime(array $snapshot): void
    {
        abort_unless(self::enabled() && LabMachineSegmentationPlan::canonicalHash($snapshot) === LabMachineSegmentationPlan::canonicalHash(self::snapshot()), 422, 'Reserved D2 lighting implementation changed.');
        $path = (string) config('iguide-lab.lighting_d2_runtime_manifest');
        $python = (string) config('iguide-lab.pipeline_python_path');
        abort_unless(is_executable($python) && is_file($path) && filesize($path) <= 65536, 503, 'Validated D2 lighting runtime is unavailable.');
        $m = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($m['validationStatus'] ?? null) === 'passed' && ($m['recipeVersion'] ?? null) === self::VERSION
            && ($m['pythonPath'] ?? null) === $python && ($m['pythonVersion'] ?? null) === '3.12.3'
            && ($m['numpyVersion'] ?? null) === '2.5.4' && ($m['pillowVersion'] ?? null) === '13.0.0'
            && ($m['authoritySha256'] ?? null) === $snapshot['authoritySha256']
            && LabMachineSegmentationPlan::canonicalHash($m['files'] ?? []) === LabMachineSegmentationPlan::canonicalHash($snapshot['files']), 503, 'D2 lighting code was not validated with this runtime.');
    }
    public static function verifyReport(array $report, array $snapshot): void
    {
        self::validateRuntime($snapshot);
        abort_unless(($report['recipe'] ?? null) === self::VERSION
            && ($report['runtime']['lightingD2Recipe'] ?? null) === self::VERSION
            && ($report['runtime']['lightingD2CodeSha256'] ?? null) === $snapshot['files']['core']
            && ($report['runtime']['lightingD2WorkerSha256'] ?? null) === $snapshot['files']['worker'], 422, 'D2 lighting execution binding changed.');
    }
}
