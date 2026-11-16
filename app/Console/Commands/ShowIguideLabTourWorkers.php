<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabTourConfig;
use Illuminate\Console\Command;

class ShowIguideLabTourWorkers extends Command
{
    /** The connection and queue that [program:repro-lab-tour] works (scripts/install-studio-runtime.sh). */
    public const DEDICATED = 'iguide-lab-tour';

    protected $signature = 'iguide-lab:tour-workers {--count : Print only the configured worker count, 1-8 (installer input)}';

    protected $description = 'Show the tour queue target and the configured dedicated tour worker count; reads config only';

    public function handle(): int
    {
        $workers = LabTourConfig::workers();
        if ($this->option('count') === true) {
            // scripts/install-studio-runtime.sh reads exactly this line as numprocs.
            $this->line((string) $workers);

            return self::SUCCESS;
        }
        $connection = LabTourConfig::queueConnection();
        $queue = LabTourConfig::queue();
        $this->line(json_encode(['connection' => $connection, 'queue' => $queue, 'workers' => $workers,
            'dedicated' => $connection === self::DEDICATED && $queue === self::DEDICATED], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
