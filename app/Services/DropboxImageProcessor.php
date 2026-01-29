<?php

namespace App\Services;

use App\Models\ShootFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class DropboxImageProcessor
{
    protected ImageProcessingService $imageService;
    
    public function __construct(ImageProcessingService $imageService)
    {
        $this->imageService = $imageService;
    }
    
    /**
     * Process images stored in Dropbox
     */
    public function processDropboxImages(ShootFile $shootFile): bool
    {
        try {
            // Only process image/raw files
            if (!in_array($shootFile->media_type, ['image', 'raw'])) {
                return false;
            }
            
            // Skip if already processed
            if ($shootFile->processed_at && $shootFile->thumbnail_path) {
                Log::info("File already processed", ['file_id' => $shootFile->id]);
                return true;
            }
            
            // For now, create a placeholder for Dropbox files
            // TODO: Implement actual Dropbox download later
            $this->createPlaceholderForDropboxFile($shootFile);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Error processing Dropbox image: " . $e->getMessage(), [
                'file_id' => $shootFile->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Create placeholder for Dropbox-stored files
     */
    protected function createPlaceholderForDropboxFile(ShootFile $shootFile): void
    {
        // Create a simple placeholder image
        $image = imagecreatetruecolor(300, 300);
        
        // Set colors
        $bgColor = imagecolorallocate($image, 45, 45, 45);
        $textColor = imagecolorallocate($image, 255, 255, 255);
        
        // Fill background
        imagefill($image, 0, 0, $bgColor);
        
        // Add text
        $text = strtoupper($shootFile->media_type);
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($text);
        $textHeight = imagefontheight($font);
        
        $x = (300 - $textWidth) / 2;
        $y = (300 - $textHeight) / 2;
        
        imagestring($image, $font, $x, $y, $text, $textColor);
        
        // Save placeholder
        $shootId = $shootFile->shoot_id;
        $fileName = $shootFile->filename;
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        
        // Generate paths
        $thumbnailPath = "shoots/{$shootId}/thumbnails/{$baseName}_thumbnail.jpg";
        $webPath = "shoots/{$shootId}/web/{$baseName}_web.jpg";
        $placeholderPath = "shoots/{$shootId}/placeholders/{$baseName}_placeholder.jpg";
        
        // Ensure directories exist
        $thumbnailDir = dirname(Storage::disk('public')->path($thumbnailPath));
        $webDir = dirname(Storage::disk('public')->path($webPath));
        $placeholderDir = dirname(Storage::disk('public')->path($placeholderPath));
        
        if (!is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0755, true);
        }
        if (!is_dir($webDir)) {
            mkdir($webDir, 0755, true);
        }
        if (!is_dir($placeholderDir)) {
            mkdir($placeholderDir, 0755, true);
        }
        
        // Save different sizes
        $thumb = imagecreatetruecolor(20, 20);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, 20, 20, 300, 300);
        imagejpeg($thumb, Storage::disk('public')->path($placeholderPath), 30);
        
        imagejpeg($image, Storage::disk('public')->path($thumbnailPath), 80);
        imagejpeg($image, Storage::disk('public')->path($webPath), 85);
        
        // Clean up
        imagedestroy($image);
        imagedestroy($thumb);
        
        // Update database
        $shootFile->update([
            'thumbnail_path' => $thumbnailPath,
            'web_path' => $webPath,
            'placeholder_path' => $placeholderPath,
            'processed_at' => now(),
        ]);
        
        Log::info("Created placeholder for Dropbox file", [
            'file_id' => $shootFile->id,
            'filename' => $shootFile->filename,
        ]);
    }
}
