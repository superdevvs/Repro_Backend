<?php

namespace App\Console\Commands;

use App\Models\ShootFile;
use App\Jobs\ProcessImageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessExistingImages extends Command
{
    protected $signature = 'images:process-existing {--limit=100} {--force}';
    protected $description = 'Process existing images that haven\'t been processed yet';

    public function handle(): int
    {
        $limit = $this->option('limit');
        $force = $this->option('force');

        $query = ShootFile::where(function ($query) {
            $query->where('media_type', 'image')
                  ->orWhere('media_type', 'raw');
        })
        ->where(function ($query) use ($force) {
            $query->whereNull('processed_at')
                ->orWhereNull('thumbnail_path')
                ->orWhereNull('web_path');
        });

        if (!$force) {
            $query->whereNull('processing_failed_at');
        }

        $files = $query->limit($limit)->get();

        if ($files->isEmpty()) {
            $this->info('No images to process.');
            return 0;
        }

        $this->info("Found {$files->count()} images to process.");

        foreach ($files as $file) {
            $this->line("Processing: {$file->filename} (ID: {$file->id})");
            
            // Reset processing status if forcing
            if ($force) {
                $file->update([
                    'processed_at' => null,
                    'processing_failed_at' => null,
                    'processing_error' => null,
                ]);
            }
            
            ProcessImageJob::dispatch($file);
            $this->info("✓ Queued for processing");
        }

        $this->info('Processing complete.');

        return 0;
    }
}
