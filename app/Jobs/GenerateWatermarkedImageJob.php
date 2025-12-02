<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class GenerateWatermarkedImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    public function __construct(public ShootFile $shootFile)
    {
    }

    public function handle(DropboxWorkflowService $dropboxService): void
    {
        try {
            // Only process image files
            if (!in_array($this->shootFile->file_type, ['image', 'jpg', 'jpeg', 'png'])) {
                Log::info('GenerateWatermarkedImageJob: Skipping non-image file', [
                    'file_id' => $this->shootFile->id,
                    'file_type' => $this->shootFile->file_type,
                ]);
                return;
            }

            Log::info('GenerateWatermarkedImageJob: Starting watermark generation', [
                'file_id' => $this->shootFile->id,
                'filename' => $this->shootFile->filename,
            ]);

            // Download original from Dropbox
            $originalPath = $dropboxService->downloadFile($this->shootFile->storage_path ?? $this->shootFile->dropbox_path);
            
            if (!$originalPath || !file_exists($originalPath)) {
                throw new \Exception('Failed to download original file from Dropbox');
            }

            // Load image
            $image = Image::make($originalPath);

            // Apply watermark
            $this->applyWatermark($image);

            // Save watermarked version
            $watermarkedPath = $this->saveWatermarkedImage($image, $this->shootFile->filename);

            // Upload watermarked version to Dropbox
            $watermarkedDropboxPath = $dropboxService->uploadFile(
                $watermarkedPath,
                dirname($this->shootFile->dropbox_path) . '/watermarked/' . basename($this->shootFile->dropbox_path),
                $this->shootFile->shoot
            );

            // Update shoot file record
            $this->shootFile->update([
                'watermarked_storage_path' => $watermarkedDropboxPath,
                'is_watermarked' => true,
            ]);

            // Update album if exists
            if ($this->shootFile->album) {
                $this->shootFile->album->update(['is_watermarked' => true]);
            }

            // Clean up temporary files
            @unlink($originalPath);
            @unlink($watermarkedPath);

            Log::info('GenerateWatermarkedImageJob: Watermark generation completed', [
                'file_id' => $this->shootFile->id,
                'watermarked_path' => $watermarkedDropboxPath,
            ]);
        } catch (\Exception $e) {
            Log::error('GenerateWatermarkedImageJob: Watermark generation failed', [
                'file_id' => $this->shootFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    protected function applyWatermark($image): void
    {
        // Watermark text
        $text = config('app.name', 'REPRO') . ' - NOT FOR SALE';
        
        // Calculate watermark size (10% of image width)
        $fontSize = max(24, (int)($image->width() * 0.1));
        
        // Add watermark text
        $image->text($text, $image->width() / 2, $image->height() / 2, function ($font) use ($fontSize) {
            $font->file(public_path('fonts/arial.ttf')); // You may need to adjust this path
            $font->size($fontSize);
            $font->color('#FFFFFF');
            $font->align('center');
            $font->valign('middle');
            $font->angle(45); // Diagonal watermark
        });

        // Add semi-transparent overlay
        $overlay = Image::canvas($image->width(), $image->height(), 'rgba(0, 0, 0, 0.3)');
        $image->insert($overlay);
    }

    protected function saveWatermarkedImage($image, string $originalFilename): string
    {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $filename = 'watermarked_' . time() . '_' . uniqid() . '.' . $extension;
        $path = storage_path('app/temp/watermarked/' . $filename);
        
        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $image->save($path, 90); // 90% quality

        return 'temp/watermarked/' . $filename;
    }
}

