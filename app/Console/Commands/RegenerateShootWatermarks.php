<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWatermarkedImageJob;
use App\Models\ShootFile;
use Illuminate\Console\Command;

class RegenerateShootWatermarks extends Command
{
    protected $signature = 'watermarks:regenerate-shoot {shoot_id}';
    protected $description = 'Regenerate watermarks for a specific shoot';

    public function handle()
    {
        $shootId = $this->argument('shoot_id');
        
        $files = ShootFile::where('shoot_id', $shootId)
            ->where('workflow_stage', 'verified')
            ->where(function ($q) {
                $q->where('file_type', 'LIKE', 'image/%')
                  ->orWhere('mime_type', 'LIKE', 'image/%');
            })
            ->get();

        $this->info("Processing " . $files->count() . " files for shoot {$shootId}...");

        $bar = $this->output->createProgressBar($files->count());
        $bar->start();

        $processed = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                $job = new GenerateWatermarkedImageJob($file);
                $job->handle(app(\App\Services\DropboxWorkflowService::class));
                $processed++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("Failed: " . $e->getMessage());
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! Processed: {$processed}, Failed: {$failed}");
        
        return 0;
    }
}
