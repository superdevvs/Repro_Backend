<?php

namespace App\Console\Commands;

use App\Models\ShootFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BackfillFileMetadata extends Command
{
    protected $signature = 'files:backfill-metadata {--shoot= : Specific shoot ID to process}';
    protected $description = 'Backfill width, height, and EXIF metadata for existing files';

    public function handle()
    {
        $shootId = $this->option('shoot');
        
        $query = ShootFile::query()
            ->whereNull('metadata')
            ->orWhere('metadata', '[]')
            ->orWhere('metadata', '{}');
        
        if ($shootId) {
            $query->where('shoot_id', $shootId);
        }
        
        $files = $query->get();
        $this->info("Processing {$files->count()} files...");
        
        $processed = 0;
        $failed = 0;
        
        foreach ($files as $file) {
            try {
                $metadata = $this->extractMetadata($file);
                
                if (!empty($metadata)) {
                    $file->metadata = $metadata;
                    $file->save();
                    $processed++;
                    $this->line("✓ {$file->filename}: {$metadata['width']}x{$metadata['height']}");
                } else {
                    $failed++;
                    $this->warn("✗ {$file->filename}: Could not extract metadata");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ {$file->filename}: {$e->getMessage()}");
            }
        }
        
        $this->info("Done. Processed: {$processed}, Failed: {$failed}");
    }
    
    private function extractMetadata(ShootFile $file): array
    {
        $metadata = [];
        $filePath = null;
        
        // Try local storage path first
        if ($file->path && !str_starts_with($file->path, '/Photo Editing')) {
            $localPath = storage_path('app/public/' . $file->path);
            if (file_exists($localPath)) {
                $filePath = $localPath;
            }
        }
        
        // Try storage_path
        if (!$filePath && $file->storage_path) {
            $localPath = storage_path('app/public/' . $file->storage_path);
            if (file_exists($localPath)) {
                $filePath = $localPath;
            }
        }
        
        if (!$filePath) {
            return $metadata;
        }
        
        // Get image dimensions
        try {
            $imageInfo = @getimagesize($filePath);
            if ($imageInfo !== false) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['mime'] = $imageInfo['mime'] ?? null;
            }
        } catch (\Exception $e) {
            Log::debug('Could not get image size', ['file_id' => $file->id, 'error' => $e->getMessage()]);
        }
        
        // Try to get EXIF data
        try {
            $ext = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
            if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg', 'tiff', 'tif'])) {
                $exif = @exif_read_data($filePath);
                if ($exif !== false) {
                    $dateFields = ['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'];
                    foreach ($dateFields as $field) {
                        if (!empty($exif[$field])) {
                            $metadata['captured_at'] = $exif[$field];
                            break;
                        }
                    }
                    if (!empty($exif['Make'])) {
                        $metadata['camera_make'] = $exif['Make'];
                    }
                    if (!empty($exif['Model'])) {
                        $metadata['camera_model'] = $exif['Model'];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not read EXIF data', ['file_id' => $file->id, 'error' => $e->getMessage()]);
        }
        
        return $metadata;
    }
}
