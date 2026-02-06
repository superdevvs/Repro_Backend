<?php

namespace App\Console\Commands;

use App\Models\ShootFile;
use App\Jobs\ProcessImageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReprocessCr3Files extends Command
{
    protected $signature = 'images:reprocess-cr3 {--shoot= : Only reprocess CR3 files for a specific shoot ID} {--dry-run : Show what would be reprocessed without actually doing it}';
    protected $description = 'Reprocess all CR3 files that have missing or placeholder thumbnails';

    public function handle(): int
    {
        $shootId = $this->option('shoot');
        $dryRun = $this->option('dry-run');

        $query = ShootFile::where(function ($q) {
            $q->where('filename', 'like', '%.cr3')
              ->orWhere('filename', 'like', '%.CR3');
        });

        if ($shootId) {
            $query->where('shoot_id', $shootId);
        }

        $files = $query->get();

        if ($files->isEmpty()) {
            $this->info('No CR3 files found.');
            return 0;
        }

        $this->info("Found {$files->count()} CR3 files.");

        $queued = 0;
        $skipped = 0;

        foreach ($files as $file) {
            // Check if file source is accessible
            $hasSource = false;
            if ($file->path && Storage::disk('public')->exists($file->path)) {
                $hasSource = true;
            } elseif ($file->path && Storage::disk('local')->exists($file->path)) {
                $hasSource = true;
            } elseif ($file->dropbox_path) {
                $hasSource = true;
            }

            if (!$hasSource) {
                $this->warn("  Skipped (no source): {$file->filename} (ID: {$file->id})");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY RUN] Would reprocess: {$file->filename} (ID: {$file->id}, shoot: {$file->shoot_id})");
                $queued++;
                continue;
            }

            // Clear old processing data so the job runs fresh
            if ($file->thumbnail_path) {
                Storage::disk('public')->delete($file->thumbnail_path);
            }
            if ($file->web_path) {
                Storage::disk('public')->delete($file->web_path);
            }
            if ($file->placeholder_path) {
                Storage::disk('public')->delete($file->placeholder_path);
            }

            $file->update([
                'thumbnail_path' => null,
                'web_path' => null,
                'placeholder_path' => null,
                'processed_at' => null,
                'processing_failed_at' => null,
                'processing_error' => null,
            ]);

            ProcessImageJob::dispatch($file);
            $this->info("  Queued: {$file->filename} (ID: {$file->id}, shoot: {$file->shoot_id})");
            $queued++;
        }

        $this->newLine();
        $this->info("Done. Queued: {$queued}, Skipped: {$skipped}");

        if ($dryRun) {
            $this->warn('This was a dry run. No files were actually reprocessed.');
        }

        return 0;
    }
}
