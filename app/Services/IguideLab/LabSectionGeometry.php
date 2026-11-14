<?php

namespace App\Services\IguideLab;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Fixed private geometry executable; client data is never a command or file path. */
class LabSectionGeometry
{
    public function ready(): bool
    {
        return is_executable((string) config('iguide-lab.pipeline_python_path')) && is_file(resource_path('iguide-lab/section_geometry.py'));
    }

    public function run(string $requestPath): array
    {
        if (! $this->ready()) throw new RuntimeException('Section geometry is unavailable.');
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '2', 'OPENBLAS_NUM_THREADS' => '2']);
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/section_geometry.py'), '--request', $requestPath], null, $env, null, 240);
        $process->run(); $report = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($report) || ($report['status'] ?? null) !== 'completed'
            || ($report['automaticAcceptance'] ?? true) !== false || ($report['reviewStatus'] ?? '') !== 'unreviewed') throw new RuntimeException('Section geometry failed its checks.');
        if ($report['operation'] !== 'extract' && ($report['statistics']['outsideAlphaChangedPixels'] ?? -1) !== 0) throw new RuntimeException('Protected source pixels changed.');
        return $report;
    }
}
