<?php

namespace App\Console\Commands;

use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Models\IguideLabRun;
use App\Services\IguideLab\LabService;
use Illuminate\Console\Command;

class ExportIguideLab extends Command
{
    protected $signature = 'iguide-lab:export {project} {--output= : Write the experiment manifest to this local file}';
    protected $description = 'Export source provenance, immutable settings and actual lab outcomes without credentials';

    public function handle(LabService $service): int
    {
        $project = IguideLabProject::findOrFail($this->argument('project'));
        $manifest = ['projectId' => $project->id, 'name' => $project->name, 'exportedAt' => now()->toIso8601String(),
            'cases' => IguideLabCase::where('project_id', $project->id)->get()->map(fn ($case) => $service->caseDto($case)),
            'runs' => IguideLabRun::where('project_id', $project->id)->get()->map(fn ($run) => $service->runDto($run))];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($file = $this->option('output')) {
            if (file_put_contents($file, $json) === false) throw new \RuntimeException('The manifest could not be saved.');
            $this->info('Lab manifest exported.');
        } else $this->line($json);

        return self::SUCCESS;
    }
}
