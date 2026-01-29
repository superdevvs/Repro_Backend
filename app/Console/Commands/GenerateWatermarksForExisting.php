<?php

namespace App\Console\Commands;

use App\Models\ShootFile;
use App\Jobs\GenerateWatermarkedImageJob;
use Illuminate\Console\Command;

class GenerateWatermarksForExisting extends Command
{
    protected $signature = 'watermarks:generate-existing {--limit=100} {--force} {--shoot=}';
    protected $description = 'Generate watermarked sizes for existing files that need them';

    public function handle(): int
    {
        $limit = $this->option('limit');
        $force = $this->option('force');
        $shootId = $this->option('shoot');

        $query = ShootFile::whereIn('workflow_stage', ['completed', 'verified'])
            ->where(function ($q) {
                // Match both simple types and MIME types
                $q->where('file_type', 'image')
                    ->orWhere('file_type', 'jpg')
                    ->orWhere('file_type', 'jpeg')
                    ->orWhere('file_type', 'png')
                    ->orWhereRaw("LOWER(COALESCE(file_type, '')) LIKE 'image/%'")
                    ->orWhereRaw("LOWER(COALESCE(mime_type, '')) LIKE 'image/%'");
            });

        // Filter by shoot if specified
        if ($shootId) {
            $query->where('shoot_id', $shootId);
        }

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('watermarked_thumbnail_path')
                    ->orWhereNull('watermarked_web_path')
                    ->orWhereNull('watermarked_placeholder_path');
            });
        }

        $files = $query->limit($limit)->get();

        if ($files->isEmpty()) {
            $this->info('No files need watermark generation.');
            return 0;
        }

        $this->info("Found {$files->count()} files to process.");

        foreach ($files as $file) {
            $this->line("Queuing watermark generation: {$file->filename} (ID: {$file->id})");
            
            GenerateWatermarkedImageJob::dispatch($file)->onQueue('watermarks');
            $this->info("✓ Queued");
        }

        $this->info('All files queued for watermark generation.');

        return 0;
    }
}
