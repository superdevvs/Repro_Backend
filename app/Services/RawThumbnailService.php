<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service for generating thumbnails from RAW image files.
 * 
 * Extracts embedded JPEG previews from RAW files (NEF, CR2, ARW, DNG, etc.)
 * This happens at UPLOAD TIME, not lazily from the UI.
 * 
 * IMPORTANT: Only saves thumbnail_path if extraction SUCCEEDS.
 * Never generates placeholder images.
 */
class RawThumbnailService
{
    /**
     * Minimum file size for a valid thumbnail (10KB)
     * Anything smaller is likely junk or empty
     */
    protected const MIN_THUMBNAIL_SIZE = 10000;

    /**
     * RAW file extensions we support
     */
    protected array $rawExtensions = [
        'nef', 'cr2', 'cr3', 'arw', 'dng', 'orf', 'raf', 'rw2', 'pef', 'srw', 'x3f', '3fr', 'fff', 'iiq', 'rwl'
    ];

    /**
     * Check if a filename is a RAW image
     */
    public function isRawFile(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $this->rawExtensions);
    }

    /**
     * Generate thumbnail for a RAW file.
     * Returns the relative path to the thumbnail, or null if failed.
     * 
     * IMPORTANT: Returns NULL if extraction fails. Does NOT generate placeholders.
     * 
     * @param string $sourcePath Absolute path to the RAW file
     * @param string $thumbnailDir Directory to store thumbnail (relative to storage/app/public)
     * @param string|null $thumbnailName Optional custom name for thumbnail
     * @return string|null Relative path to thumbnail (for storage in DB), or null if failed
     */
    public function generateThumbnail(string $sourcePath, string $thumbnailDir, ?string $thumbnailName = null): ?string
    {
        if (!file_exists($sourcePath)) {
            Log::warning('RawThumbnailService: Source file not found', ['path' => $sourcePath]);
            return null;
        }

        $filename = pathinfo($sourcePath, PATHINFO_FILENAME);
        $thumbnailName = $thumbnailName ?? $filename . '_thumb.jpg';
        $thumbnailRelativePath = $thumbnailDir . '/' . $thumbnailName;
        $thumbnailAbsPath = storage_path('app/public/' . $thumbnailRelativePath);

        // Ensure directory exists
        $dir = dirname($thumbnailAbsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Clean up any previous failed attempt
        if (file_exists($thumbnailAbsPath)) {
            unlink($thumbnailAbsPath);
        }

        // Try extraction methods in order of preference
        // 1. ExifTool (fastest, extracts embedded JPEG)
        // 2. dcraw demosaic (slower, but works when no embedded preview exists)
        // 3. ImageMagick (slowest, full conversion)
        
        $success = $this->extractWithExiftool($sourcePath, $thumbnailAbsPath)
            || $this->extractWithDcrawDemosaic($sourcePath, $thumbnailAbsPath)
            || $this->extractWithImageMagick($sourcePath, $thumbnailAbsPath);

        // Validate the result - must be a real image, not junk
        if ($success && $this->isValidThumbnail($thumbnailAbsPath)) {
            Log::info('RawThumbnailService: Thumbnail generated successfully', [
                'source' => basename($sourcePath),
                'thumbnail' => $thumbnailRelativePath,
                'size' => filesize($thumbnailAbsPath),
            ]);
            return $thumbnailRelativePath;
        }

        // Clean up failed attempt
        if (file_exists($thumbnailAbsPath)) {
            unlink($thumbnailAbsPath);
        }

        Log::warning('RawThumbnailService: All extraction methods failed', [
            'source' => $sourcePath,
            'exiftool_available' => $this->commandExists('exiftool'),
            'dcraw_available' => $this->commandExists('dcraw'),
            'magick_available' => $this->commandExists('magick') || $this->commandExists('convert'),
        ]);
        
        // Return NULL - do NOT generate a placeholder
        return null;
    }

    /**
     * Validate that a thumbnail file is a real image
     */
    protected function isValidThumbnail(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $size = filesize($path);
        if ($size < self::MIN_THUMBNAIL_SIZE) {
            Log::debug('RawThumbnailService: Thumbnail too small, likely junk', [
                'path' => $path,
                'size' => $size,
                'min_required' => self::MIN_THUMBNAIL_SIZE,
            ]);
            return false;
        }

        // Verify it's actually a JPEG
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        if (!in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/png'])) {
            Log::debug('RawThumbnailService: Invalid MIME type', [
                'path' => $path,
                'mime' => $mimeType,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Extract embedded JPEG using exiftool
     * 
     * Tries in order: JpgFromRaw (best), PreviewImage, ThumbnailImage
     */
    protected function extractWithExiftool(string $source, string $output): bool
    {
        if (!$this->commandExists('exiftool')) {
            Log::debug('RawThumbnailService: exiftool not available');
            return false;
        }

        // CORRECT ORDER: JpgFromRaw first (full-size embedded JPEG)
        $tags = ['JpgFromRaw', 'PreviewImage', 'ThumbnailImage'];

        foreach ($tags as $tag) {
            // Windows-compatible command (no 2>/dev/null)
            $cmd = sprintf(
                'exiftool -b -%s %s',
                $tag,
                escapeshellarg($source)
            );
            
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            
            $process = proc_open($cmd, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                $jpegData = stream_get_contents($pipes[1]);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                
                // Check if we got valid data
                if ($jpegData && strlen($jpegData) >= self::MIN_THUMBNAIL_SIZE) {
                    file_put_contents($output, $jpegData);
                    
                    if ($this->isValidThumbnail($output)) {
                        Log::info("RawThumbnailService: Extracted using exiftool -{$tag}", [
                            'source' => basename($source),
                            'size' => strlen($jpegData),
                        ]);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Use dcraw to demosaic the RAW file (slower, but works when no embedded preview)
     * Creates a half-size PPM, then converts to JPEG
     */
    protected function extractWithDcrawDemosaic(string $source, string $output): bool
    {
        if (!$this->commandExists('dcraw')) {
            Log::debug('RawThumbnailService: dcraw not available');
            return false;
        }

        $sourceDir = dirname($source);
        $sourceBase = pathinfo($source, PATHINFO_FILENAME);
        $ppmPath = $sourceDir . '/' . $sourceBase . '.ppm';
        
        // First try: Extract embedded thumbnail (fast)
        $thumbCmd = sprintf(
            'dcraw -e %s',
            escapeshellarg($source)
        );
        exec($thumbCmd, $out, $code);
        
        // dcraw creates file with .thumb.jpg extension
        $thumbPath = $sourceDir . '/' . $sourceBase . '.thumb.jpg';
        
        if (file_exists($thumbPath) && filesize($thumbPath) >= self::MIN_THUMBNAIL_SIZE) {
            rename($thumbPath, $output);
            if ($this->isValidThumbnail($output)) {
                Log::info('RawThumbnailService: Extracted using dcraw -e', [
                    'source' => basename($source),
                ]);
                return true;
            }
        }
        
        // Clean up if that didn't work
        if (file_exists($thumbPath)) {
            unlink($thumbPath);
        }

        // Second try: Full demosaic (slower, but guaranteed to work)
        // -w = use camera white balance
        // -h = half-size (faster, good enough for thumbnails)
        // -q 0 = fast interpolation
        $demosaicCmd = sprintf(
            'dcraw -w -h -q 0 %s',
            escapeshellarg($source)
        );
        exec($demosaicCmd, $out, $code);

        if ($code !== 0 || !file_exists($ppmPath)) {
            return false;
        }

        // Convert PPM to JPEG using ImageMagick or PHP GD
        $converted = $this->convertPpmToJpeg($ppmPath, $output);
        
        // Clean up PPM
        if (file_exists($ppmPath)) {
            unlink($ppmPath);
        }

        return $converted && $this->isValidThumbnail($output);
    }

    /**
     * Convert PPM to JPEG
     */
    protected function convertPpmToJpeg(string $ppmPath, string $jpegPath): bool
    {
        // Try ImageMagick first
        if ($this->commandExists('magick') || $this->commandExists('convert')) {
            $convertCmd = $this->commandExists('magick') ? 'magick' : 'convert';
            $cmd = sprintf(
                '%s %s -resize 800x800 -quality 85 %s',
                $convertCmd,
                escapeshellarg($ppmPath),
                escapeshellarg($jpegPath)
            );
            exec($cmd, $out, $code);
            
            if ($code === 0 && file_exists($jpegPath)) {
                return true;
            }
        }

        // Fallback to PHP GD
        if (function_exists('imagecreatefrompnm') || function_exists('imagecreatefromstring')) {
            $imageData = file_get_contents($ppmPath);
            $image = @imagecreatefromstring($imageData);
            
            if ($image) {
                // Resize to max 800px
                $width = imagesx($image);
                $height = imagesy($image);
                $maxDim = 800;
                
                if ($width > $maxDim || $height > $maxDim) {
                    $ratio = min($maxDim / $width, $maxDim / $height);
                    $newWidth = (int)($width * $ratio);
                    $newHeight = (int)($height * $ratio);
                    
                    $resized = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    imagedestroy($image);
                    $image = $resized;
                }
                
                imagejpeg($image, $jpegPath, 85);
                imagedestroy($image);
                
                return file_exists($jpegPath);
            }
        }

        return false;
    }

    /**
     * Convert using ImageMagick directly (slowest, but most compatible)
     */
    protected function extractWithImageMagick(string $source, string $output): bool
    {
        if (!$this->commandExists('magick') && !$this->commandExists('convert')) {
            Log::debug('RawThumbnailService: ImageMagick not available');
            return false;
        }

        $convertCmd = $this->commandExists('magick') ? 'magick' : 'convert';

        // Try to extract preview layer first (fast)
        $cmd = sprintf(
            '%s %s -thumbnail 800x800 -quality 85 %s',
            $convertCmd,
            escapeshellarg($source . '[0]'), // [0] gets first layer/preview
            escapeshellarg($output)
        );
        exec($cmd, $out, $code);

        if ($code === 0 && $this->isValidThumbnail($output)) {
            Log::info('RawThumbnailService: Converted using ImageMagick', [
                'source' => basename($source),
            ]);
            return true;
        }

        return false;
    }

    /**
     * Check if a command exists on the system
     */
    protected function commandExists(string $command): bool
    {
        $check = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        exec("$check $command 2>&1", $output, $code);
        return $code === 0;
    }

    /**
     * Get list of supported RAW extensions
     */
    public function getSupportedExtensions(): array
    {
        return $this->rawExtensions;
    }

    /**
     * Debug: Check what preview tags exist in a RAW file
     */
    public function getAvailablePreviews(string $sourcePath): array
    {
        if (!$this->commandExists('exiftool') || !file_exists($sourcePath)) {
            return [];
        }

        $cmd = sprintf(
            'exiftool -a -G1 -s %s',
            escapeshellarg($sourcePath)
        );
        exec($cmd, $output, $code);

        $previews = [];
        foreach ($output as $line) {
            if (stripos($line, 'jpg') !== false || stripos($line, 'preview') !== false || stripos($line, 'thumbnail') !== false) {
                $previews[] = trim($line);
            }
        }

        return $previews;
    }
}
