<?php

namespace App\Services;

use App\Models\ShootFile;
use App\Services\Media\ImageResampler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Exception;

class ImageProcessingService
{
    protected ImageManager $manager;
    protected RawThumbnailService $rawThumbnailService;
    protected ImageResampler $resampler;
    
    // Supported RAW formats
    protected const RAW_FORMATS = [
        'cr2', 'cr3', 'nef', 'arw', 'dng', 'orf', 'rw2', 'pef', 'srw', 
        'mos', 'mrw', 'erf', '3fr', 'fff', 'iiq', 'kdc', 'mef', 
        'nrw', 'ptx', 'pxn', 'r3d', 'raf', 'raw', 'rwl', 'sr2', 
        'srf', 'x3f'
    ];
    
    // Image sizes configuration
    //
    // `grid` is the rendition every card and tile in the product displays: the
    // media grid, the shoot-history grid and list, and the dashboard
    // completed/delivered slideshows. It is the "600px tuned" preset — a 600px
    // long edge, which is exactly 600x400 for the 3:2 frame a listing camera
    // produces, at quality 85, resampled with Lanczos and finished with an
    // unsharp mask.
    //
    // Two problems drove the tuning. It used to be 1000px, so a retina desktop
    // tile pulled ~400KB of image into a ~320px slot. And the surfaces that were
    // *not* on it (history cards, dashboard slideshows) were showing the 300px
    // thumbnail stretched over a 256px-tall card, which is the blur people
    // reported. 600px covers a 2x tile without waste, and one rendition now
    // serves every grid.
    //
    // `width`/`height` bound a box rather than force a crop: `calculateDimensions`
    // fits inside it and never upscales, so a portrait frame comes out 400x600
    // instead of being cut down to 400px on its long edge.
    //
    // `sharpen` feeds ImageResampler's unsharp mask (0 disables it). Only the two
    // small renditions get the filtered treatment; `web` stays on GD's native
    // resample because a filtered 1500px pass costs seconds per image and it is
    // large enough not to look soft.
    protected const SIZES = [
        'thumbnail' => ['width' => 300, 'height' => 300, 'quality' => 80, 'resample' => 'lanczos', 'sharpen' => 0.12],
        'grid' => ['width' => 600, 'height' => 600, 'quality' => 85, 'resample' => 'lanczos', 'sharpen' => 0.10],
        'web' => ['width' => 1500, 'height' => 1500, 'quality' => 85],
        'placeholder' => ['width' => 20, 'height' => 20, 'quality' => 30]
    ];
    
    public function __construct(
        ?RawThumbnailService $rawThumbnailService = null,
        ?ImageResampler $resampler = null
    ) {
        $this->manager = new ImageManager(new Driver());
        $this->rawThumbnailService = $rawThumbnailService ?: new RawThumbnailService();
        $this->resampler = $resampler ?: new ImageResampler();
    }
    
    /**
     * Process an image directly from a file path and return generated paths
     * Used during upload when the temp file is still available
     */
    public function processImageFromPath(int $shootId, string $fileName, string $sourcePath): array
    {
        try {
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            if (!file_exists($sourcePath)) {
                Log::error("processImageFromPath: File not found: {$sourcePath}");
                return [];
            }

            // Determine if it's a RAW file
            $isRaw = in_array($extension, self::RAW_FORMATS);
            
            // Extract preview from RAW or read regular image
            $image = $this->extractImagePreview($sourcePath, $isRaw);
            
            if (!$image) {
                Log::error("processImageFromPath: Failed to process image: {$fileName}");
                return [];
            }
            
            // Generate different sizes
            $generatedPaths = [];
            foreach (self::SIZES as $sizeName => $config) {
                $generatedPath = $this->generateSize($image, $shootId, $fileName, $sizeName, $config);
                if ($generatedPath) {
                    $generatedPaths[$sizeName] = $generatedPath;
                }
            }
            
            // Clean up
            if (is_resource($image)) {
                imagedestroy($image);
            }
            
            Log::info("processImageFromPath: Successfully processed image: {$fileName}", [
                'paths' => $generatedPaths
            ]);
            
            return $generatedPaths;
            
        } catch (Exception $e) {
            Log::error("processImageFromPath: Error processing image: " . $e->getMessage(), [
                'fileName' => $fileName,
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    
    /**
     * Process an uploaded image file
     */
    public function processImage(ShootFile $shootFile, ?string $sourcePath = null): bool
    {
        try {
            $filePath = $sourcePath ?? $shootFile->path;
            $shootId = $shootFile->shoot_id;
            $fileName = $shootFile->filename;
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Resolve full path (supports local disk or absolute path)
            $fullPath = null;
            if ($filePath && Storage::disk('local')->exists($filePath)) {
                $fullPath = Storage::disk('local')->path($filePath);
            } elseif ($filePath && file_exists($filePath)) {
                $fullPath = $filePath;
            }

            if (!$fullPath || !file_exists($fullPath)) {
                Log::error("File not found: {$filePath}");
                return false;
            }

            // Determine if it's a RAW file
            $isRaw = in_array($extension, self::RAW_FORMATS);
            
            // Extract preview from RAW or read regular image
            $image = $this->extractImagePreview($fullPath, $isRaw);
            
            if (!$image) {
                Log::error("Failed to process image: {$fileName}");
                return false;
            }
            
            // Generate different sizes
            $generatedPaths = [];
            foreach (self::SIZES as $sizeName => $config) {
                $generatedPath = $this->generateSize($image, $shootId, $fileName, $sizeName, $config);
                if ($generatedPath) {
                    $generatedPaths[$sizeName] = $generatedPath;
                }
            }
            
            // Clean up
            if (is_resource($image)) {
                imagedestroy($image);
            }

            // A run that produced nothing is a failure, not a success. These
            // columns used to be written unconditionally as
            // `$generatedPaths[$size] ?? null`, so a run in which every
            // generateSize() call failed blanked four working renditions whose
            // image files were still on disk — and returned true while doing it.
            // Record the failure and leave the existing paths untouched.
            if (empty($generatedPaths)) {
                Log::error("Image processing produced no renditions", [
                    'file_id' => $shootFile->id,
                    'filename' => $fileName,
                ]);

                $shootFile->update([
                    'processing_failed_at' => now(),
                    'processing_error' => 'Image processing produced no renditions.',
                ]);

                return false;
            }

            // Write only the sizes actually generated: a partial run must
            // upgrade what it could and preserve everything else.
            $updates = [
                'processed_at' => now(),
                'processing_failed_at' => null,
                'processing_error' => null,
            ];

            foreach (array_keys(self::SIZES) as $sizeName) {
                if (!empty($generatedPaths[$sizeName])) {
                    $updates["{$sizeName}_path"] = $generatedPaths[$sizeName];
                }
            }

            $shootFile->update($updates);
            
            Log::info("Successfully processed image: {$fileName}");
            return true;
            
        } catch (Exception $e) {
            Log::error("Error processing image: " . $e->getMessage(), [
                'file_id' => $shootFile->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Extract image preview from RAW file or read regular image
     * Uses pure PHP/GD - no external tools like ImageMagick required
     */
    protected function extractImagePreview(string $filePath, bool $isRaw)
    {
        if ($isRaw) {
            // Extract embedded JPEG preview from RAW file using pure PHP
            // Most RAW files (CR2, NEF, ARW, DNG, etc.) contain embedded JPEG previews
            $image = null;
            
            try {
                $image = $this->extractWithPel($filePath);
                if ($image) {
                    Log::info("Successfully extracted RAW preview using pure PHP");
                    return $image;
                }
            } catch (Exception $e) {
                Log::warning("RAW preview extraction failed: " . $e->getMessage());
            }
            
            // CR3/BMFF and similar formats are more reliable via the dedicated RAW thumbnail pipeline.
            try {
                $image = $this->extractWithRawThumbnailService($filePath);
                if ($image) {
                    Log::info("Successfully extracted RAW preview using RawThumbnailService");
                    return $image;
                }
            } catch (Exception $e) {
                Log::warning("RawThumbnailService extraction failed: " . $e->getMessage());
            }

            // Last resort: Create a placeholder for RAW files that don't have extractable previews
            Log::warning("Could not extract preview from RAW file, using placeholder");
            return $this->createRawPlaceholder();
            
        } else {
            // Uploaded files are often stored as extensionless temp files, so rely on
            // the actual bytes instead of the temporary filename suffix.
            $imageData = @file_get_contents($filePath);
            if ($imageData === false) {
                Log::error("Failed to read image file: {$filePath}");
                return false;
            }

            $image = @imagecreatefromstring($imageData);
            if ($image !== false) {
                return $image;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = @mime_content_type($filePath) ?: 'unknown';
            Log::error("Unsupported image format: {$extension}", [
                'path' => $filePath,
                'mime_type' => $mimeType,
            ]);
            return false;
        }
    }
    
    /**
     * Extract embedded JPEG from RAW file using exiftool (handles CR3/BMFF and all modern RAW formats)
     * Returns a GD image resource or null
     */
    protected function extractWithExiftool(string $filePath)
    {
        // Check if exiftool is available
        $check = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        exec("$check exiftool 2>&1", $output, $code);
        if ($code !== 0) {
            Log::debug('ImageProcessingService: exiftool not available');
            return null;
        }

        // Try tags in order: JpgFromRaw (full-size), PreviewImage (medium), ThumbnailImage (small)
        $tags = ['JpgFromRaw', 'PreviewImage', 'ThumbnailImage'];

        foreach ($tags as $tag) {
            $cmd = sprintf(
                'exiftool -b -%s %s',
                $tag,
                escapeshellarg($filePath)
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

                if ($jpegData && strlen($jpegData) >= 10000) {
                    $image = @imagecreatefromstring($jpegData);
                    if ($image !== false) {
                        $w = imagesx($image);
                        $h = imagesy($image);
                        Log::info("Extracted RAW preview via exiftool -{$tag}: {$w}x{$h}, " . strlen($jpegData) . ' bytes');
                        return $image;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract a RAW preview through the shared thumbnail service so CR3 files can
     * fall back to ExifTool, dcraw, or ImageMagick before we give up.
     */
    protected function extractWithRawThumbnailService(string $filePath)
    {
        $baseName = pathinfo($filePath, PATHINFO_FILENAME);
        $thumbnailDir = '_temp/raw-preview-staging';
        $thumbnailName = $baseName . '_' . uniqid('', true) . '_preview.jpg';
        $relativePath = $this->rawThumbnailService->generateThumbnail($filePath, $thumbnailDir, $thumbnailName);

        if (!$relativePath || !Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($relativePath);
        $imageData = @file_get_contents($absolutePath);
        $image = $imageData !== false ? @imagecreatefromstring($imageData) : false;

        Storage::disk('public')->delete($relativePath);

        return $image !== false ? $image : null;
    }

    /**
     * Extract embedded JPEG preview from RAW file using pure PHP
     * Most RAW files contain embedded JPEG previews that can be extracted
     */
    protected function extractWithPel(string $filePath)
    {
        try {
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                return null;
            }
            
            // Find ALL embedded JPEGs in the RAW file and pick the largest one
            // RAW files typically contain multiple JPEGs: thumbnail, preview, and sometimes full-size
            $jpegImages = $this->findAllEmbeddedJpegs($fileContent);
            
            if (empty($jpegImages)) {
                Log::warning("No embedded JPEGs found in RAW file");
                return null;
            }
            
            // Sort by size (largest first) and try to use the largest valid one
            usort($jpegImages, function($a, $b) {
                return strlen($b) - strlen($a);
            });
            
            foreach ($jpegImages as $jpegData) {
                // Skip very small thumbnails (less than 10KB is likely just a tiny thumbnail)
                if (strlen($jpegData) < 10000) {
                    continue;
                }
                
                // Try to create image from this JPEG data
                $image = @imagecreatefromstring($jpegData);
                if ($image !== false) {
                    $width = imagesx($image);
                    $height = imagesy($image);
                    
                    // Accept images that are at least 500px in either dimension
                    if ($width >= 500 || $height >= 500) {
                        Log::info("Found embedded JPEG preview: {$width}x{$height}, size: " . strlen($jpegData) . " bytes");
                        return $image;
                    }
                    imagedestroy($image);
                }
            }
            
            // If no large preview found, try the largest one we have
            foreach ($jpegImages as $jpegData) {
                $image = @imagecreatefromstring($jpegData);
                if ($image !== false) {
                    Log::info("Using smaller embedded JPEG: " . imagesx($image) . "x" . imagesy($image));
                    return $image;
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            Log::error("Embedded JPEG extraction error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find all embedded JPEG images in a RAW file
     */
    protected function findAllEmbeddedJpegs(string $fileContent): array
    {
        $jpegImages = [];
        $offset = 0;
        $fileLength = strlen($fileContent);
        
        // JPEG markers
        $jpegStart = "\xFF\xD8\xFF";
        $jpegEnd = "\xFF\xD9";
        
        while ($offset < $fileLength) {
            // Find next JPEG start marker
            $startPos = strpos($fileContent, $jpegStart, $offset);
            if ($startPos === false) {
                break;
            }
            
            // Find the corresponding end marker
            // Note: We need to find the CORRECT end marker, not just any FFD9
            $endPos = $this->findJpegEndMarker($fileContent, $startPos);
            
            if ($endPos !== false && $endPos > $startPos) {
                $jpegData = substr($fileContent, $startPos, $endPos - $startPos + 2);
                
                // Basic validation - check if it looks like valid JPEG
                if (strlen($jpegData) > 100) {
                    $jpegImages[] = $jpegData;
                }
            }
            
            // Move past this JPEG to find more
            $offset = ($endPos !== false) ? $endPos + 2 : $startPos + 3;
        }
        
        return $jpegImages;
    }
    
    /**
     * Find the correct JPEG end marker (FFD9) for a JPEG starting at given position
     */
    protected function findJpegEndMarker(string $data, int $startPos): int|false
    {
        $pos = $startPos + 2;
        $dataLength = strlen($data);
        
        while ($pos < $dataLength - 1) {
            // Look for any marker (FF followed by non-zero byte)
            if (ord($data[$pos]) === 0xFF) {
                $marker = ord($data[$pos + 1]);
                
                // FFD9 is end of image
                if ($marker === 0xD9) {
                    return $pos + 1;
                }
                
                // Skip restart markers (FFD0-FFD7) and FF00 (escaped FF)
                if ($marker === 0x00 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                    $pos += 2;
                    continue;
                }
                
                // For other markers, skip their data segment
                if ($marker >= 0xC0 && $marker !== 0xFF) {
                    if ($pos + 3 < $dataLength) {
                        $segmentLength = (ord($data[$pos + 2]) << 8) + ord($data[$pos + 3]);
                        $pos += 2 + $segmentLength;
                        continue;
                    }
                }
            }
            $pos++;
        }
        
        // Fallback: simple search for FFD9 if structured parsing fails
        $simpleEnd = strpos($data, "\xFF\xD9", $startPos + 100);
        return $simpleEnd !== false ? $simpleEnd + 1 : false;
    }
    
    /**
     * Create a RAW file placeholder image
     */
    protected function createRawPlaceholder()
    {
        // Create a 300x300 placeholder with "RAW" text
        $image = imagecreatetruecolor(300, 300);
        
        // Set colors
        $bgColor = imagecolorallocate($image, 45, 45, 45);
        $textColor = imagecolorallocate($image, 255, 255, 255);
        
        // Fill background
        imagefill($image, 0, 0, $bgColor);
        
        // Add text
        $text = "RAW";
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($text);
        $textHeight = imagefontheight($font);
        
        $x = (300 - $textWidth) / 2;
        $y = (300 - $textHeight) / 2;
        
        imagestring($image, $font, $x, $y, $text, $textColor);
        
        return $image;
    }
    
    /**
     * Generate a specific size of the image
     */
    protected function generateSize($image, int $shootId, string $fileName, string $sizeName, array $config): ?string
    {
        try {
            // Get original dimensions
            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);
            
            // Calculate new dimensions maintaining aspect ratio
            [$newWidth, $newHeight] = $this->calculateDimensions(
                $originalWidth,
                $originalHeight,
                $config['width'],
                $config['height']
            );

            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $isTransparencyCapable = in_array($extension, ['png', 'gif', 'webp'], true);

            if (($config['resample'] ?? null) === 'lanczos' && $image instanceof \GdImage) {
                // Lanczos + unsharp for the renditions the grids display. Output
                // is JPEG, which has no alpha, so a transparent source is
                // composited onto white first — sampling the raw RGB under the
                // alpha channel would drag black into the edges instead.
                $sourceImage = $isTransparencyCapable
                    ? $this->resampler->flattenOntoWhite($image)
                    : $image;

                $newImage = $this->resampler->resize(
                    $sourceImage,
                    $newWidth,
                    $newHeight,
                    (float) ($config['sharpen'] ?? 0.0)
                );

                if ($sourceImage !== $image) {
                    imagedestroy($sourceImage);
                }
            } else {
                // Create new image
                $newImage = imagecreatetruecolor($newWidth, $newHeight);

                // Preserve transparency for PNG
                if ($extension === 'png') {
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);
                    $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                    imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
                }

                // Resize image
                imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
            }
            
            // Generate filename
            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
            $newFileName = "{$baseName}_{$sizeName}.jpg";
            
            // Determine storage path
            $storagePath = "shoots/{$shootId}/{$sizeName}s/{$newFileName}";
            
            // Save to appropriate disk. Every browser-facing rendition must land
            // on `public`; the `local` disk root is storage/app/private, which is
            // not web-accessible. `grid` was missing from this list, so it was
            // written somewhere the browser could never fetch it — generation
            // reported success and grid_path was stored, but tiles silently fell
            // back to the 300px thumbnail and looked blurred.
            $disk = in_array($sizeName, ['thumbnail', 'grid', 'web', 'placeholder']) ? 'public' : 'local';
            
            // Create temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'img_process_') . '.jpg';
            
            // Save image
            imagejpeg($newImage, $tempFile, $config['quality']);
            
            // Store file
            $success = Storage::disk($disk)->put($storagePath, file_get_contents($tempFile));
            
            // Clean up
            imagedestroy($newImage);
            unlink($tempFile);
            
            if (!$success) {
                Log::error("Failed to save {$sizeName} image: {$storagePath}");
                return null;
            }
            
            return $storagePath;
            
        } catch (Exception $e) {
            Log::error("Error generating {$sizeName}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Calculate dimensions maintaining aspect ratio
     */
    protected function calculateDimensions(int $originalWidth, int $originalHeight, int $maxWidth, int $maxHeight): array
    {
        $ratio = $originalWidth / $originalHeight;
        
        if ($originalWidth > $originalHeight) {
            // Landscape
            if ($originalWidth > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = intval($maxWidth / $ratio);
            } else {
                $newWidth = $originalWidth;
                $newHeight = $originalHeight;
            }
        } else {
            // Portrait or square
            if ($originalHeight > $maxHeight) {
                $newHeight = $maxHeight;
                $newWidth = intval($maxHeight * $ratio);
            } else {
                $newWidth = $originalWidth;
                $newHeight = $originalHeight;
            }
        }
        
        // Ensure minimum dimensions
        $newWidth = max($newWidth, 1);
        $newHeight = max($newHeight, 1);
        
        return [$newWidth, $newHeight];
    }

    public function needsPreviewRegeneration(ShootFile $shootFile): bool
    {
        if (!self::isRawFile($shootFile->filename ?? '')) {
            return false;
        }

        if (empty($shootFile->thumbnail_path) || empty($shootFile->web_path)) {
            return true;
        }

        $previewDimensions = array_filter([
            $this->getStoredImageDimensions($shootFile->thumbnail_path),
            $this->getStoredImageDimensions($shootFile->web_path),
        ]);

        if ($previewDimensions === []) {
            return true;
        }

        foreach ($previewDimensions as [$width, $height]) {
            if ($width > 360 || $height > 360) {
                return false;
            }
        }

        return true;
    }

    protected function getStoredImageDimensions(?string $path): ?array
    {
        if (!$path || !Storage::disk('public')->exists($path)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($path);
        $imageInfo = @getimagesize($absolutePath);
        if ($imageInfo === false) {
            return null;
        }

        return [(int) $imageInfo[0], (int) $imageInfo[1]];
    }
    
    /**
     * Check if a file is a RAW image
     */
    public static function isRawFile(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($extension, self::RAW_FORMATS);
    }
    
    /**
     * Get supported RAW formats
     */
    public static function getSupportedRawFormats(): array
    {
        return self::RAW_FORMATS;
    }
}
