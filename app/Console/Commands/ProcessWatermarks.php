<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\WatermarkSettingsController;
use App\Jobs\GenerateWatermarkedImageJob;
use App\Models\ShootFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessWatermarks extends Command
{
    protected $signature = 'watermarks:process {regeneration_id?}';
    protected $description = 'Process watermark regeneration for queued files';

    public function handle()
    {
        $regenerationId = $this->argument('regeneration_id');
        
        if (!$regenerationId) {
            // Find any pending regeneration
            $this->error('Please provide a regeneration ID');
            $this->info('Usage: php artisan watermarks:process <regeneration_id>');
            return 1;
        }

        $cacheKey = 'watermark_regeneration_progress_' . $regenerationId;
        $fileIdsCacheKey = $cacheKey . '_file_ids';
        
        $fileIds = Cache::get($fileIdsCacheKey);
        
        if (!$fileIds || empty($fileIds)) {
            $this->error('No files found for regeneration ID: ' . $regenerationId);
            return 1;
        }

        $this->info("Processing " . count($fileIds) . " files...");
        
        $progress = Cache::get($cacheKey) ?? [
            'status' => 'processing',
            'total' => count($fileIds),
            'processed' => 0,
            'failed' => 0,
            'percentage' => 0,
        ];
        
        $progress['status'] = 'processing';
        Cache::put($cacheKey, $progress, 3600);

        $bar = $this->output->createProgressBar(count($fileIds));
        $bar->start();

        $processed = 0;
        $failed = 0;

        foreach ($fileIds as $fileId) {
            try {
                $file = ShootFile::find($fileId);
                if ($file) {
                    // Run the job directly (not queued)
                    $job = new GenerateWatermarkedImageJob($file, $regenerationId);
                    $job->handle(app(\App\Services\DropboxWorkflowService::class));
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("Failed to process file {$fileId}: " . $e->getMessage());
                $failed++;
            }

            // Update progress
            $progress['processed'] = $processed;
            $progress['failed'] = $failed;
            $progress['percentage'] = round((($processed + $failed) / count($fileIds)) * 100);
            Cache::put($cacheKey, $progress, 3600);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Mark as complete
        $progress['status'] = 'completed';
        $progress['completed_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $progress, 3600);

        // Clean up file IDs cache
        Cache::forget($fileIdsCacheKey);

        $this->info("Completed! Processed: {$processed}, Failed: {$failed}");
        
        return 0;
    }
}
