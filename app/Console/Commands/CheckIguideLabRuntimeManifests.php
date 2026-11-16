<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabRuntimeManifestCheck;
use Illuminate\Console\Command;

class CheckIguideLabRuntimeManifests extends Command
{
    protected $signature = 'iguide-lab:runtime-manifests {--check : Report every runtime manifest and preset readiness; writes nothing} {--write : Not available in this release} {--restore : Not available in this release}';

    protected $description = 'Read-only runtime manifest validity and preset readiness report; exit 0 only when every check passes (run as the worker user)';

    public function handle(LabRuntimeManifestCheck $check): int
    {
        // D15: D1 ships --check only; --write and --restore move to D2.
        if ($this->option('check') !== true || $this->option('write') === true || $this->option('restore') === true) {
            $this->line('Only --check is available in this release.');

            return self::FAILURE;
        }
        $report = $check->run();
        foreach ([...$report['manifests'], $report['summary']] as $line) {
            $this->line(json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return $report['summary']['ok'] === true ? self::SUCCESS : self::FAILURE;
    }
}
