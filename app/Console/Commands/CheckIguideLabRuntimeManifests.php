<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabRuntimeManifestCheck;
use App\Services\IguideLab\LabRuntimeManifests;
use Illuminate\Console\Command;

class CheckIguideLabRuntimeManifests extends Command
{
    private const USAGE = 'Choose one of --check, --write [--dry-run] [--only=a,b] or --restore=<set> [--only=a,b].';

    protected $signature = 'iguide-lab:runtime-manifests
        {--check : Report every runtime manifest and preset readiness; writes nothing}
        {--write : Rebuild the code pins of stale manifests, check them all together, back up, then swap each into place}
        {--restore= : Put backup set <set> (printed by --write) back}
        {--only= : Comma-separated manifest names that limit --write or --restore, e.g. grass,declutter_removal}
        {--dry-run : With --write: build and check the new manifests, then write nothing}';

    protected $description = 'Runtime manifest report (--check), refresh (--write) and rollback (--restore); run as the worker user that owns the manifests';

    public function handle(LabRuntimeManifestCheck $check, LabRuntimeManifests $manifests): int
    {
        $checking = $this->option('check') === true;
        $writing = $this->option('write') === true;
        $restore = $this->option('restore');
        $only = $this->option('only');
        $dryRun = $this->option('dry-run') === true;
        if ((int) $checking + (int) $writing + (int) ($restore !== null) !== 1 || ($restore !== null && ! is_string($restore))
            || ($dryRun && ! $writing) || ($checking && $only !== null)) {
            $this->line(self::USAGE);

            return self::FAILURE;
        }
        if ($checking) {
            $report = $check->run();
            foreach ([...$report['manifests'], $report['summary']] as $line) {
                $this->line(json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }

            return $report['summary']['ok'] === true ? self::SUCCESS : self::FAILURE;
        }
        $keys = array_keys(LabRuntimeManifestCheck::OWNERS);
        if ($only !== null) {
            $named = array_values(array_filter(array_map('trim', explode(',', (string) $only)), fn (string $name) => $name !== ''));
            if ($named === [] || array_diff($named, $keys) !== []) {
                $this->line('Refused: --only needs manifest names from: '.implode(', ', $keys).'.');

                return self::FAILURE;
            }
            $keys = array_values(array_intersect($keys, $named));
        }
        [$ok, $lines] = $writing ? $manifests->write($keys, $dryRun) : $manifests->restore($restore, $keys);
        foreach ($lines as $line) $this->line($line);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
