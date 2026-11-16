<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabTourConfig;
use Illuminate\Console\Command;

class ShowIguideLabTourWorkers extends Command
{
    /** The connection and queue that [program:repro-lab-tour] works (scripts/install-studio-runtime.sh). */
    public const DEDICATED = 'iguide-lab-tour';

    protected $signature = 'iguide-lab:tour-workers {--count : Print only the dedicated worker count: 1-8 when the target is dedicated, otherwise 0 (installer input)}';

    protected $description = 'Show the tour queue target and the configured dedicated tour worker count; reads config only';

    public function handle(): int
    {
        $workers = LabTourConfig::workers();
        $connection = LabTourConfig::queueConnection();
        $queue = LabTourConfig::queue();
        $dedicated = $connection === self::DEDICATED && $queue === self::DEDICATED;
        if ($this->option('count') === true) {
            // scripts/install-studio-runtime.sh reads exactly this line as numprocs; 0 installs no tour program, so a
            // Studio reinstall never adds an unapproved worker. workers() keeps its 1..8 clamp (it also sets survey slots).
            $this->line((string) ($dedicated ? $workers : 0));

            return self::SUCCESS;
        }
        $this->line(json_encode(['connection' => $connection, 'queue' => $queue, 'workers' => $workers,
            'dedicated' => $dedicated], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
