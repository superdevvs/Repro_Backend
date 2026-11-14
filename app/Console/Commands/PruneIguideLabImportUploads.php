<?php

namespace App\Console\Commands;

use App\Services\IguideLab\LabImportUploads;
use Illuminate\Console\Command;

class PruneIguideLabImportUploads extends Command
{
    protected $signature = 'iguide-lab:prune-import-uploads';
    protected $description = 'Remove expired private scan-upload staging; never remove imported source cases.';
    public function handle(LabImportUploads $uploads): int { $this->info('Expired uploads removed: '.$uploads->prune()); return self::SUCCESS; }
}
