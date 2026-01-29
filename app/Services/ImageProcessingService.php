<?php

namespace App\Services;

use App\Models\ShootFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Exception;

class ImageProcessingService
{
    protected ImageManager $manager;
    
    // Supported RAW formats
    protected const RAW_FORMATS = [
        'cr2', 'nef', 'arw', 'dng', 'orf', 'rw2', 'pef', 'srw', 
        'mos', 'mrw', 'erf', '3fr', 'fff', 'iiq', 'kdc', 'mef', 
        'nrw', 'ptx', 'pxn', 'r3d', 'raf', 'raw', 'rwl', 'sr2', 
        'srf', 'x3f'
    ];
    
    // Image sizes configuration
    protected const SIZES = [
        'thumbnail' => ['width' => 300, 'height' => 300, 'quality' => 80],
        'web' => ['width' => 1500, 'height' => 1500, 'quality' => 85],
        'placeholder' => ['width' => 20, 'height' => 20, 'quality' => 30]
    ];
    
    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
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
            
            // Update shoot file with generated paths
            $shootFile->update([
                'thumbnail_path' => $generatedPaths['thumbnail'] ?? null,
                'web_path' => $generatedPaths['web'] ?? null,
                'placeholder_path' => $generatedPaths['placeholder'] ?? null,
                'processed_at' => now(),
            ]);
            
            // Clean up
            if (is_resource($image)) {
                imagedestroy($image);
            }
            
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
            
            // Fallback: Create a placeholder for RAW files that don't have extractable previews
            Log::warning("Could not extract preview from RAW file, using placeholder");
            return $this->createRawPlaceholder();
            
        } else {
            // Handle regular image files
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            
            switch ($extension) {
                case 'jpg':
                case 'jpeg':
                    return imagecreatefromjpeg($filePath);
                case 'png':
                    return imagecreatefrompng($filePath);
                case 'gif':
                    return imagecreatefromgif($filePath);
                case 'webp':
                    return imagecreatefromwebp($filePath);
                default:
                    Log::error("Unsupported image format: {$extension}");
                    return false;
            }
        }
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
            
            // Create new image
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG
            if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'png') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            // Resize image
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            // Generate filename
            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
            $newFileName = "{$baseName}_{$sizeName}.jpg";
            
            // Determine storage path
            $storagePath = "shoots/{$shootId}/{$sizeName}s/{$newFileName}";
            
            // Save to appropriate disk
            $disk = in_array($sizeName, ['thumbnail', 'web', 'placeholder']) ? 'public' : 'local';
            
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
