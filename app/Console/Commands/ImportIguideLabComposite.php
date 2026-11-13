<?php

namespace App\Console\Commands;

use App\Models\IguideLabCase;
use App\Models\IguideLabProject;
use App\Services\IguideLab\LabComposites;
use Illuminate\Console\Command;

class ImportIguideLabComposite extends Command
{
    protected $signature = 'iguide-lab:import-composite {project} {case} {manifest} {preview} {--native=}';
    protected $description = 'Privately import a derived panorama and optional native canvas, without generating an image';

    public function handle(LabComposites $service): int
    {
        $project = IguideLabProject::findOrFail($this->argument('project'));
        $case = IguideLabCase::where('project_id', $project->id)->findOrFail($this->argument('case'));
        $path = $this->argument('manifest');
        if (! is_file($path) || filesize($path) > 65536) { $this->error('Use a local manifest file no larger than 64 KB.'); return self::FAILURE; }
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        [$record, $created] = $service->add($project, $case, $this->argument('preview'), $manifest, $this->option('native'));
        $this->line(json_encode(['id' => $record->id, 'requestId' => $record->request_id, 'created' => $created], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
