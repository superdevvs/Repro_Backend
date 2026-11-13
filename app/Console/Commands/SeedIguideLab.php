<?php

namespace App\Console\Commands;

use App\Models\IguideLabProject;
use App\Services\IguideLab\LabService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

class SeedIguideLab extends Command
{
    protected $signature = 'iguide-lab:seed {project} {manifest : JSON array of cases with local file paths}';
    protected $description = 'Import local image fixtures into one existing private lab';

    public function handle(LabService $service): int
    {
        $project = IguideLabProject::findOrFail($this->argument('project'));
        if (! $project->active()) { $this->error('This project is inactive.'); return self::FAILURE; }
        $manifest = json_decode(file_get_contents($this->argument('manifest')), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! array_is_list($manifest) || count($manifest) > 80) throw new \RuntimeException('Use an array containing at most 80 cases.');
        foreach ($manifest as $input) {
            if (! is_file($input['file'] ?? '') || ! isset($input['label'], $input['projection'])) throw new \RuntimeException('Each case needs a file, label, and projection.');
            $image = new UploadedFile($input['file'], basename($input['file']), test: true);
            $case = $service->addCase($project, $image, $input);
            $this->line($case->id.' '.$case->label);
        }

        return self::SUCCESS;
    }
}
