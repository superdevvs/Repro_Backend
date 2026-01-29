<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class RawPreviewService
{
    /**
     * Supported RAW formats
     */
    protected array $supportedFormats = [
        'cr2', 'cr3', 'nef', 'arw', 'dng', 'orf', 'raf', 'rw2', 'pef', 'srw', 'x3f'
    ];

    /**
     * Preview storage path
     */
    protected string $previewPath = 'previews/raw';

    /**
     * Preview quality (1-100)
     */
    protected int $quality = 85;

    /**
     * Max preview dimensions
     */
    protected int $maxWidth = 1200;
    protected int $maxHeight = 1200;

    /**
     * Check if file is a RAW image
     */
    public function isRawFile(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, $this->supportedFormats);
    }

    /**
     * Get supported formats
     */
    public function getSupportedFormats(): array
    {
        return $this->supportedFormats;
    }

    /**
     * Generate preview for a RAW file
     * Returns the preview URL or null on failure
     */
    public function generatePreview(string $rawFilePath, ?string $customOutputName = null): ?array
    {
        if (!file_exists($rawFilePath)) {
            Log::error("RawPreviewService: File not found", ['path' => $rawFilePath]);
            return null;
        }

        if (!$this->isRawFile($rawFilePath)) {
            Log::warning("RawPreviewService: Not a RAW file", ['path' => $rawFilePath]);
            return null;
        }

        $filename = $customOutputName ?? pathinfo($rawFilePath, PATHINFO_FILENAME);
        $previewFilename = $filename . '_preview.jpg';
        $previewFullPath = storage_path("app/public/{$this->previewPath}/{$previewFilename}");

        // Ensure preview directory exists
        $previewDir = dirname($previewFullPath);
        if (!is_dir($previewDir)) {
            mkdir($previewDir, 0755, true);
        }

        // Check if preview already exists and is newer than source
        if (file_exists($previewFullPath) && filemtime($previewFullPath) > filemtime($rawFilePath)) {
            return $this->buildResponse($previewFilename, $previewFullPath);
        }

        // Try ExifTool first (fastest - extracts embedded preview)
        $result = $this->extractWithExifTool($rawFilePath, $previewFullPath);
        
        if (!$result) {
            // Fallback to ImageMagick
            $result = $this->convertWithImageMagick($rawFilePath, $previewFullPath);
        }

        if (!$result) {
            // Last resort: dcraw
            $result = $this->convertWithDcraw($rawFilePath, $previewFullPath);
        }

        if ($result && file_exists($previewFullPath)) {
            return $this->buildResponse($previewFilename, $previewFullPath);
        }

        Log::error("RawPreviewService: All conversion methods failed", ['path' => $rawFilePath]);
        return null;
    }

    /**
     * Extract embedded preview using ExifTool (fastest method)
     */
    protected function extractWithExifTool(string $inputPath, string $outputPath): bool
    {
        // Try PreviewImage first
        $commands = [
            ['exiftool', '-b', '-PreviewImage', $inputPath],
            ['exiftool', '-b', '-JpgFromRaw', $inputPath],
            ['exiftool', '-b', '-ThumbnailImage', $inputPath],
        ];

        foreach ($commands as $command) {
            try {
                $process = new Process($command);
                $process->setTimeout(30);
                $process->run();

                if ($process->isSuccessful()) {
                    $output = $process->getOutput();
                    
                    // Validate it's actual image data (JPEG starts with FF D8)
                    if (strlen($output) > 10000 && substr($output, 0, 2) === "\xFF\xD8") {
                        file_put_contents($outputPath, $output);
                        
                        // Resize if too large
                        $this->resizeIfNeeded($outputPath);
                        
                        Log::info("RawPreviewService: ExifTool extraction successful", [
                            'method' => $command[2],
                            'size' => strlen($output)
                        ]);
                        return true;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("RawPreviewService: ExifTool method failed", [
                    'method' => $command[2] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        return false;
    }

    /**
     * Convert using ImageMagick
     */
    protected function convertWithImageMagick(string $inputPath, string $outputPath): bool
    {
        try {
            // Try 'magick' command first (ImageMagick 7+), then 'convert' (ImageMagick 6)
            $commands = [
                ['magick', $inputPath, '-resize', "{$this->maxWidth}x{$this->maxHeight}>", '-quality', (string)$this->quality, $outputPath],
                ['convert', $inputPath, '-resize', "{$this->maxWidth}x{$this->maxHeight}>", '-quality', (string)$this->quality, $outputPath],
            ];

            foreach ($commands as $command) {
                $process = new Process($command);
                $process->setTimeout(120); // RAW conversion can be slow
                $process->run();

                if ($process->isSuccessful() && file_exists($outputPath)) {
                    Log::info("RawPreviewService: ImageMagick conversion successful");
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::debug("RawPreviewService: ImageMagick failed", ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Convert using dcraw (lightweight alternative)
     */
    protected function convertWithDcraw(string $inputPath, string $outputPath): bool
    {
        try {
            // Extract embedded JPEG with dcraw
            $tempPpm = $inputPath . '.ppm';
            
            $process = new Process(['dcraw', '-e', '-c', $inputPath]);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                if (strlen($output) > 1000) {
                    // dcraw -e outputs JPEG directly
                    file_put_contents($outputPath, $output);
                    $this->resizeIfNeeded($outputPath);
                    Log::info("RawPreviewService: dcraw extraction successful");
                    return true;
                }
            }

            // Try full conversion
            $process = new Process(['dcraw', '-c', '-w', '-h', $inputPath]);
            $process->setTimeout(120);
            $process->run();

            if ($process->isSuccessful()) {
                // dcraw outputs PPM, need to convert to JPEG
                $ppmData = $process->getOutput();
                if (strlen($ppmData) > 1000) {
                    file_put_contents($tempPpm, $ppmData);
                    
                    // Convert PPM to JPEG using ImageMagick
                    $convertProcess = new Process(['magick', $tempPpm, '-quality', (string)$this->quality, $outputPath]);
                    $convertProcess->run();
                    
                    @unlink($tempPpm);
                    
                    if (file_exists($outputPath)) {
                        $this->resizeIfNeeded($outputPath);
                        Log::info("RawPreviewService: dcraw full conversion successful");
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("RawPreviewService: dcraw failed", ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Resize image if larger than max dimensions
     */
    protected function resizeIfNeeded(string $imagePath): void
    {
        try {
            $imageInfo = @getimagesize($imagePath);
            if (!$imageInfo) return;

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            if ($width > $this->maxWidth || $height > $this->maxHeight) {
                $process = new Process([
                    'magick', $imagePath,
                    '-resize', "{$this->maxWidth}x{$this->maxHeight}>",
                    '-quality', (string)$this->quality,
                    $imagePath
                ]);
                $process->setTimeout(30);
                $process->run();
            }
        } catch (\Exception $e) {
            Log::debug("RawPreviewService: Resize failed", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build response array
     */
    protected function buildResponse(string $filename, string $fullPath): array
    {
        $imageInfo = @getimagesize($fullPath);
        
        return [
            'success' => true,
            'previewUrl' => Storage::url("{$this->previewPath}/{$filename}"),
            'previewPath' => "{$this->previewPath}/{$filename}",
            'filename' => $filename,
            'width' => $imageInfo[0] ?? null,
            'height' => $imageInfo[1] ?? null,
            'size' => filesize($fullPath),
        ];
    }

    /**
     * Delete preview for a file
     */
    public function deletePreview(string $filename): bool
    {
        $previewFilename = pathinfo($filename, PATHINFO_FILENAME) . '_preview.jpg';
        $path = "{$this->previewPath}/{$previewFilename}";
        
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->delete($path);
        }
        
        return false;
    }

    /**
     * Check if preview exists
     */
    public function previewExists(string $filename): bool
    {
        $previewFilename = pathinfo($filename, PATHINFO_FILENAME) . '_preview.jpg';
        return Storage::disk('public')->exists("{$this->previewPath}/{$previewFilename}");
    }

    /**
     * Get preview URL if exists
     */
    public function getPreviewUrl(string $filename): ?string
    {
        $previewFilename = pathinfo($filename, PATHINFO_FILENAME) . '_preview.jpg';
        $path = "{$this->previewPath}/{$previewFilename}";
        
        if (Storage::disk('public')->exists($path)) {
            return Storage::url($path);
        }
        
        return null;
    }

    /**
     * Batch generate previews
     */
    public function generateBatchPreviews(array $filePaths): array
    {
        $results = [];
        
        foreach ($filePaths as $path) {
            $results[$path] = $this->generatePreview($path);
        }
        
        return $results;
    }

    /**
     * Clean up old previews (older than X days)
     */
    public function cleanupOldPreviews(int $daysOld = 30): int
    {
        $deleted = 0;
        $cutoff = now()->subDays($daysOld)->timestamp;
        
        $files = Storage::disk('public')->files($this->previewPath);
        
        foreach ($files as $file) {
            $fullPath = storage_path("app/public/{$file}");
            if (file_exists($fullPath) && filemtime($fullPath) < $cutoff) {
                Storage::disk('public')->delete($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }
}
