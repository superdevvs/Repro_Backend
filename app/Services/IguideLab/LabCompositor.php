<?php

namespace App\Services\IguideLab;

use RuntimeException;
use Symfony\Component\Process\Process;

class LabCompositor
{
    public function ready(): bool
    {
        return is_executable((string) config('iguide-lab.pipeline_python_path')) && is_file(resource_path('iguide-lab/composite_grass.py'));
    }

    public function run(string $requestPath): array
    {
        if (! $this->ready()) throw new RuntimeException('The compositor runtime is unavailable.');
        // The pixel worker receives no provider credentials, HTTP environment or user Python paths.
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv() ?: [])), false);
        $env = array_merge($env, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1', 'OMP_NUM_THREADS' => '2', 'OPENBLAS_NUM_THREADS' => '2']);
        $process = new Process([(string) config('iguide-lab.pipeline_python_path'), resource_path('iguide-lab/composite_grass.py'), '--request', $requestPath], null, $env, null, 120);
        $process->run();
        $report = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($report) || ($report['status'] ?? null) !== 'completed'
            || ($report['automaticAcceptance'] ?? true) !== false || ($report['statistics']['outsideAlphaMaxChannelDifference'] ?? -1) !== 0) throw new RuntimeException('The source-preserving composition failed its checks.');
        return $report;
    }
}
