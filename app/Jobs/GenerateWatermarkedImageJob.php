<?php

namespace App\Jobs;

use App\Models\ShootFile;
use App\Models\WatermarkSettings;
use App\Services\DropboxWorkflowService;
use App\Http\Controllers\API\WatermarkSettingsController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class GenerateWatermarkedImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    protected const WATERMARK_SIZES = [
        'thumbnail' => ['width' => 300, 'height' => 300, 'quality' => 80],
        'web' => ['width' => 1500, 'height' => 1500, 'quality' => 85],
        'placeholder' => ['width' => 20, 'height' => 20, 'quality' => 30],
    ];

    public function __construct(
        public ShootFile $shootFile,
        public ?string $regenerationId = null
    ) {
    }

    public function handle(DropboxWorkflowService $dropboxService): void
    {
        try {
            // Only process image files - check both file_type and mime_type
            $fileType = strtolower((string)$this->shootFile->file_type);
            $mimeType = strtolower((string)($this->shootFile->mime_type ?? ''));
            $isImage = in_array($fileType, ['image', 'jpg', 'jpeg', 'png', 'gif', 'webp']) 
                    || str_starts_with($fileType, 'image/')
                    || str_starts_with($mimeType, 'image/');
            
            if (!$isImage) {
                Log::info('GenerateWatermarkedImageJob: Skipping non-image file', [
                    'file_id' => $this->shootFile->id,
                    'file_type' => $this->shootFile->file_type,
                    'mime_type' => $this->shootFile->mime_type,
                ]);
                return;
            }

            Log::info('GenerateWatermarkedImageJob: Starting watermark generation', [
                'file_id' => $this->shootFile->id,
                'filename' => $this->shootFile->filename,
            ]);

            $imageManager = new ImageManager(new Driver());

            [$originalPath, $deleteOriginalAfter] = $this->getOriginalFilePath($dropboxService);

            if (!$originalPath || !file_exists($originalPath)) {
                throw new \Exception('Failed to locate original file for watermarking.');
            }

            // Load image via Intervention v3 manager
            $image = $imageManager->read($originalPath);

            // Apply watermark
            $this->applyWatermark($image, $imageManager);

            // Save watermarked version to temporary storage/app path
            $watermarkedRelativePath = $this->saveWatermarkedImage($image, $this->shootFile->filename);
            $watermarkedAbsolutePath = storage_path('app/' . $watermarkedRelativePath);

            if (!file_exists($watermarkedAbsolutePath)) {
                throw new \Exception('Failed to save watermarked file.');
            }

            $watermarkedStoragePath = $this->storeWatermarkedOutput(
                $watermarkedAbsolutePath,
                $dropboxService
            );

            $watermarkedSizePaths = $this->generateWatermarkedSizes(
                $watermarkedAbsolutePath,
                $imageManager,
                $dropboxService
            );

            // Update shoot file record
            $this->shootFile->update([
                'watermarked_storage_path' => $watermarkedStoragePath,
                'watermarked_thumbnail_path' => $watermarkedSizePaths['thumbnail'] ?? null,
                'watermarked_web_path' => $watermarkedSizePaths['web'] ?? null,
                'watermarked_placeholder_path' => $watermarkedSizePaths['placeholder'] ?? null,
                'is_watermarked' => true,
            ]);

            // Update album if exists
            if ($this->shootFile->album) {
                $this->shootFile->album->update(['is_watermarked' => true]);
            }

            // Clean up temporary files
            if ($deleteOriginalAfter && file_exists($originalPath)) {
                @unlink($originalPath);
            }

            if (file_exists($watermarkedAbsolutePath)) {
                @unlink($watermarkedAbsolutePath);
            }

            Log::info('GenerateWatermarkedImageJob: Watermark generation completed', [
                'file_id' => $this->shootFile->id,
                'watermarked_path' => $watermarkedStoragePath,
            ]);
            
            // Update progress if this is part of a batch regeneration
            if ($this->regenerationId) {
                WatermarkSettingsController::updateRegenerationProgress($this->regenerationId, true);
            }
        } catch (\Exception $e) {
            Log::error('GenerateWatermarkedImageJob: Watermark generation failed', [
                'file_id' => $this->shootFile->id,
                'error' => $e->getMessage(),
            ]);
            
            // Update progress even on failure
            if ($this->regenerationId) {
                WatermarkSettingsController::updateRegenerationProgress($this->regenerationId, false);
            }

            // Don't rethrow in sync queue mode - allows request to continue
            // The file will still show original image
            if (config('queue.default') !== 'sync') {
                throw $e;
            }
        }
    }

    protected function applyWatermark(ImageInterface $image, ImageManager $imageManager): void
    {
        $settings = WatermarkSettings::getDefault();
        $shoot = $this->shootFile->shoot;
        $logoEnabled = $settings->logo_enabled ?? true;
        $textEnabled = $settings->text_enabled ?? false;
        $overlayEnabled = $settings->overlay_enabled ?? false;
        $watermarkApplied = false;

        // Apply overlay if enabled (applied first as background)
        if ($overlayEnabled) {
            $this->applyOverlay($image, $imageManager, $settings);
            $watermarkApplied = true;
        }

        // Apply text watermark if enabled
        $textApplied = false;
        if ($textEnabled) {
            $this->applyTextWatermarkWithSettings($image, $imageManager, $settings);
            $textApplied = true;
            $watermarkApplied = true;
        }

        // Apply logo watermark if enabled (applied on top of text)
        if ($logoEnabled) {
            $defaultLogoUrl = WatermarkSettings::defaultLogoUrl();
            $logoUrl = $settings->custom_logo_url ?: $shoot?->getCompanyLogoForWatermark();
            if (!$logoUrl) {
                $logoUrl = $defaultLogoUrl;
            }

            if ($logoUrl) {
                try {
                    $this->applyLogoWatermarkWithSettings($image, $logoUrl, $imageManager, $settings);
                    $watermarkApplied = true;
                } catch (\Exception $e) {
                    Log::warning('GenerateWatermarkedImageJob: Failed to apply logo watermark', [
                        'file_id' => $this->shootFile->id,
                        'logo_url' => $logoUrl,
                        'error' => $e->getMessage(),
                    ]);

                    if ($logoUrl !== $defaultLogoUrl) {
                        try {
                            $this->applyLogoWatermarkWithSettings($image, $defaultLogoUrl, $imageManager, $settings);
                            $watermarkApplied = true;
                        } catch (\Exception $fallbackException) {
                            Log::warning('GenerateWatermarkedImageJob: Failed to apply default logo watermark', [
                                'file_id' => $this->shootFile->id,
                                'logo_url' => $defaultLogoUrl,
                                'error' => $fallbackException->getMessage(),
                            ]);
                        }
                    }

                    // Fallback to text watermark if logo failed and text not already applied
                    if (!$textApplied && !$watermarkApplied) {
                        $this->applyTextWatermark($image, $imageManager);
                        $watermarkApplied = true;
                    }
                }
            } elseif (!$textApplied) {
                // No logo URL and no text - apply default text watermark
                $this->applyTextWatermark($image, $imageManager);
                $watermarkApplied = true;
            }
        }

        if (!$watermarkApplied) {
            Log::warning('GenerateWatermarkedImageJob: No watermark settings enabled, applying fallback logo/text', [
                'file_id' => $this->shootFile->id,
                'logo_enabled' => $logoEnabled,
                'text_enabled' => $textEnabled,
                'overlay_enabled' => $overlayEnabled,
            ]);

            $fallbackLogoUrl = WatermarkSettings::defaultLogoUrl();

            try {
                $this->applyLogoWatermarkWithSettings($image, $fallbackLogoUrl, $imageManager, $settings);
            } catch (\Exception $e) {
                $this->applyTextWatermark($image, $imageManager);
            }
        }
    }

    protected function applyLogoWatermark(ImageInterface $image, string $logoUrl, ImageManager $imageManager): void
    {
        // Download or get local logo path
        $logoPath = $this->downloadLogo($logoUrl);
        
        if (!$logoPath || !file_exists($logoPath)) {
            throw new \Exception('Failed to get logo from: ' . $logoUrl);
        }

        try {
            // Load logo image
            $logo = $imageManager->read($logoPath);
            
            // Calculate watermark size (15-20% of image width, maintain aspect ratio)
            $maxWidth = (int)($image->width() * 0.2);
            $maxHeight = (int)($image->height() * 0.2);
            
            // Resize logo maintaining aspect ratio without upscaling
            $logo->scaleDown($maxWidth, $maxHeight);

            // Position logo in bottom-right corner with padding (moved up from edge)
            $paddingX = (int)($image->width() * 0.03); // 3% padding from right edge
            $paddingY = (int)($image->height() * 0.08); // 8% padding from bottom (moved up)
            $x = $image->width() - $logo->width() - $paddingX;
            $y = $image->height() - $logo->height() - $paddingY;

            // Insert logo watermark at 60% opacity in bottom-right
            $image->place($logo, 'top-left', $x, $y, 60);

            // Clean up downloaded logo (but not persistent local files)
            if (!$this->isPersistentLogoPath($logoPath)) {
                @unlink($logoPath);
            }
        } catch (\Exception $e) {
            // Clean up on error (but not persistent local files)
            if (isset($logoPath) && file_exists($logoPath) && !$this->isPersistentLogoPath($logoPath)) {
                @unlink($logoPath);
            }
            throw $e;
        }
    }

    protected function applyTextWatermark(ImageInterface $image, ImageManager $imageManager): void
    {
        // Watermark text (fallback)
        $text = config('app.name', 'REPRO') . ' - NOT FOR SALE';
        
        // Calculate watermark size (10% of image width)
        $fontSize = max(24, (int)($image->width() * 0.1));
        $fontPath = $this->getWatermarkFontPath();
        
        // Add watermark text
        $image->text($text, (int)($image->width() / 2), (int)($image->height() / 2), function ($font) use ($fontSize, $fontPath) {
            if ($fontPath) {
                $font->file($fontPath);
            }
            $font->size($fontSize);
            $font->color('#FFFFFF');
            $font->align('center');
            $font->valign('middle');
            $font->angle(45); // Diagonal watermark
        });

        // Add semi-transparent overlay
        $overlay = $imageManager->create($image->width(), $image->height());
        $overlay->fill('rgba(0, 0, 0, 0.3)');
        $image->place($overlay, 'top-left');
    }

    protected function applyLogoWatermarkWithSettings(ImageInterface $image, string $logoUrl, ImageManager $imageManager, WatermarkSettings $settings): void
    {
        $logoPath = $this->downloadLogo($logoUrl);
        
        if (!$logoPath || !file_exists($logoPath)) {
            throw new \Exception('Failed to get logo from: ' . $logoUrl);
        }

        try {
            $logo = $imageManager->read($logoPath);
            
            // Calculate logo size based on settings (clamp for safety)
            $logoSize = max(5, (float) $settings->logo_size);
            $maxWidth = (int)($image->width() * ($logoSize / 100));
            $maxHeight = (int)($image->height() * ($logoSize / 100));
            $logo->scaleDown($maxWidth, $maxHeight);

            // Calculate position based on settings
            $position = $settings->calculateLogoPosition(
                $image->width(),
                $image->height(),
                $logo->width(),
                $logo->height()
            );

            // Insert logo with configured opacity (clamp for safety)
            $opacity = min(100, max(5, (int) $settings->logo_opacity));
            $image->place($logo, 'top-left', $position['x'], $position['y'], $opacity);

            if (!$this->isPersistentLogoPath($logoPath)) {
                @unlink($logoPath);
            }
        } catch (\Exception $e) {
            if (isset($logoPath) && file_exists($logoPath) && !$this->isPersistentLogoPath($logoPath)) {
                @unlink($logoPath);
            }
            throw $e;
        }
    }

    protected function isPersistentLogoPath(string $logoPath): bool
    {
        $normalizedPath = $this->normalizePath($logoPath);
        $publicRoot = $this->normalizePath(public_path());
        $storagePublicRoot = $this->normalizePath(storage_path('app/public'));

        return Str::startsWith($normalizedPath, [$publicRoot, $storagePublicRoot]);
    }

    protected function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, "\\/"));
    }

    protected function getWatermarkFontPath(): ?string
    {
        static $cachedPath = null;
        static $checked = false;

        if ($checked) {
            return $cachedPath ?: null;
        }

        $checked = true;

        $candidates = [
            public_path('fonts/arial.ttf'),
            public_path('fonts/Arial.ttf'),
            public_path('fonts/ARIAL.TTF'),
            storage_path('app/public/fonts/arial.ttf'),
            resource_path('fonts/arial.ttf'),
            resource_path('fonts/Arial.ttf'),
            base_path('fonts/arial.ttf'),
            base_path('fonts/Arial.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\Arial.ttf',
            'C:\\Windows\\Fonts\\ARIAL.TTF',
            'C:\\Windows\\Fonts\\segoeui.ttf',
            'C:\\Windows\\Fonts\\SegoeUI.ttf',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate && file_exists($candidate)) {
                $cachedPath = $candidate;
                return $candidate;
            }
        }

        Log::warning('GenerateWatermarkedImageJob: No watermark font file found, text may be tiny.', [
            'candidates' => $candidates,
        ]);

        $cachedPath = null;
        return null;
    }

    protected function applyTextWatermarkWithSettings(ImageInterface $image, ImageManager $imageManager, WatermarkSettings $settings): void
    {
        $text = $settings->text_content;
        if (!$text) {
            $text = config('app.name', 'REPRO') . ' - NOT FOR SALE';
        }
        $fontSize = max(10, (int)($image->width() * ($settings->text_size / 600)));
        $color = $settings->text_color;
        $opacity = $settings->text_opacity;

        // Convert hex color to rgba with opacity
        $rgbaColor = $this->hexToRgba($color, $opacity / 100);

        switch ($settings->text_style) {
            case 'repeated':
                $angle = (int) ($settings->text_angle ?? -30);
                $angle = max(-90, min(90, $angle));
                $this->applyRepeatedTextWatermark($image, $imageManager, $text, $fontSize, $rgbaColor, $settings->text_spacing, $angle);
                break;
            case 'banner':
                $this->applyBannerTextWatermark($image, $imageManager, $text, $fontSize, $rgbaColor);
                break;
            case 'corner':
                $this->applyCornerTextWatermark($image, $text, $fontSize, $rgbaColor);
                break;
            case 'diagonal':
            default:
                $this->applyDiagonalTextWatermark($image, $text, $fontSize, $rgbaColor);
                break;
        }
    }

    protected function applyRepeatedTextWatermark(ImageInterface $image, ImageManager $imageManager, string $text, int $fontSize, string $color, int $spacing, int $angle): void
    {
        $width = $image->width();
        $height = $image->height();
        $fontPath = $this->getWatermarkFontPath();
        
        // Calculate actual spacing based on image size and setting (50-500 maps to tighter/looser spacing)
        // Estimate text width roughly as fontSize * text length * 0.6
        $estimatedTextWidth = $fontSize * strlen($text) * 0.6;
        $estimatedTextHeight = $fontSize * 1.5;
        
        // Use spacing setting as a percentage modifier (50 = tight, 500 = loose)
        // Base spacing is the text dimensions, multiplied by spacing factor
        $spacingFactor = $spacing / 100; // 50 -> 0.5, 100 -> 1.0, 500 -> 5.0
        $xSpacing = max((int)($estimatedTextWidth * $spacingFactor), $fontSize * 2);
        $ySpacing = max((int)($estimatedTextHeight * $spacingFactor * 1.5), $fontSize * 2);
        
        // Offset alternating rows for a more natural pattern
        $rowIndex = 0;
        
        // Start before the visible area to ensure full coverage with rotated text
        $startX = -$width;
        $startY = -$height / 2;
        $endX = $width * 2;
        $endY = $height * 1.5;
        
        for ($y = $startY; $y < $endY; $y += $ySpacing) {
            $offsetX = ($rowIndex % 2 === 0) ? 0 : $xSpacing / 2;
            
            for ($x = $startX + $offsetX; $x < $endX; $x += $xSpacing) {
                $image->text($text, (int)$x, (int)$y, function ($font) use ($fontSize, $color, $fontPath, $angle) {
                    if ($fontPath) {
                        $font->file($fontPath);
                    }
                    $font->size($fontSize);
                    $font->color($color);
                    $font->angle($angle);
                });
            }
            $rowIndex++;
        }
    }

    protected function applyBannerTextWatermark(ImageInterface $image, ImageManager $imageManager, string $text, int $fontSize, string $color): void
    {
        $width = $image->width();
        $height = $image->height();
        $fontPath = $this->getWatermarkFontPath();
        
        // Add text banner in the middle
        $image->text($text, (int)($width / 2), (int)($height / 2), function ($font) use ($fontSize, $color, $fontPath) {
            if ($fontPath) {
                $font->file($fontPath);
            }
            $font->size($fontSize * 1.5);
            $font->color($color);
            $font->align('center');
            $font->valign('middle');
        });
    }

    protected function applyCornerTextWatermark(ImageInterface $image, string $text, int $fontSize, string $color): void
    {
        $padding = (int)($image->width() * 0.02);
        $fontPath = $this->getWatermarkFontPath();
        
        $image->text($text, $padding, $image->height() - $padding, function ($font) use ($fontSize, $color, $fontPath) {
            if ($fontPath) {
                $font->file($fontPath);
            }
            $font->size($fontSize);
            $font->color($color);
            $font->align('left');
            $font->valign('bottom');
        });
    }

    protected function applyDiagonalTextWatermark(ImageInterface $image, string $text, int $fontSize, string $color): void
    {
        $fontPath = $this->getWatermarkFontPath();

        $image->text($text, (int)($image->width() / 2), (int)($image->height() / 2), function ($font) use ($fontSize, $color, $fontPath) {
            if ($fontPath) {
                $font->file($fontPath);
            }
            $font->size($fontSize);
            $font->color($color);
            $font->align('center');
            $font->valign('middle');
            $font->angle(45);
        });
    }

    protected function applyOverlay(ImageInterface $image, ImageManager $imageManager, WatermarkSettings $settings): void
    {
        $color = trim((string) ($settings->overlay_color ?? ''));
        if ($color === '') {
            $color = 'rgba(0,0,0,0.1)';
        }
        if (str_starts_with($color, '#')) {
            $color = $this->hexToRgba($color, 0.1);
        }

        $overlay = $imageManager->create($image->width(), $image->height());
        $overlay->fill($color);
        $image->place($overlay, 'top-left');
    }

    protected function hexToRgba(string $hex, float $alpha = 1.0): string
    {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        return "rgba({$r}, {$g}, {$b}, {$alpha})";
    }

    protected function downloadLogo(string $logoUrl): ?string
    {
        try {
            // Clear PHP's stat cache to ensure fresh file checks
            clearstatcache(true);
            
            // Handle local file paths (prefixed with 'local:')
            if (Str::startsWith($logoUrl, 'local:')) {
                $localPath = Str::after($logoUrl, 'local:');
                if (file_exists($localPath)) {
                    return $localPath;
                }
                throw new \Exception('Local logo file not found: ' . $localPath);
            }

            // Handle public images URLs (e.g. /images/... or http://127.0.0.1:8000/images/...)
            if (Str::contains($logoUrl, '/images/') || Str::startsWith($logoUrl, 'images/')) {
                $relativeImagePath = preg_replace('#.*?/images/#', '', $logoUrl);
                $relativeImagePath = ltrim($relativeImagePath, '/');
                $publicImagePath = public_path('images/' . $relativeImagePath);

                Log::info('GenerateWatermarkedImageJob: Using public images logo path', [
                    'original_url' => $logoUrl,
                    'local_path' => $publicImagePath,
                    'exists' => file_exists($publicImagePath),
                ]);

                if (file_exists($publicImagePath)) {
                    return $publicImagePath;
                }
            }

            // Handle relative storage paths like /storage/... or storage/...
            $relativeStoragePath = ltrim($logoUrl, '/');
            if (Str::startsWith($relativeStoragePath, 'storage/')) {
                $storagePath = Str::after($relativeStoragePath, 'storage/');
                $localPath = storage_path('app/public/' . $storagePath);
                $publicPath = public_path('storage/' . $storagePath);

                Log::info('GenerateWatermarkedImageJob: Using relative storage logo path', [
                    'original_url' => $logoUrl,
                    'local_path' => $localPath,
                    'exists' => file_exists($localPath),
                ]);

                if (file_exists($localPath)) {
                    return $localPath;
                }

                if (file_exists($publicPath)) {
                    return $publicPath;
                }

                throw new \Exception('Local logo file not found at: ' . $localPath . ' or ' . $publicPath);
            }

            // Check if this is a local storage URL - convert to local file path
            // Matches URLs like http://127.0.0.1:8000/storage/... or http://localhost:8000/storage/...
            $appUrl = rtrim(config('app.url'), '/');
            if (Str::startsWith($logoUrl, $appUrl . '/storage/') || 
                Str::contains($logoUrl, '127.0.0.1') && Str::contains($logoUrl, '/storage/') ||
                Str::contains($logoUrl, 'localhost') && Str::contains($logoUrl, '/storage/')) {
                
                // Extract the path after /storage/
                $storagePath = preg_replace('#.*?/storage/#', '', $logoUrl);
                
                // Try with forward slashes first (works on both Windows and Unix)
                $localPath = storage_path('app/public/' . $storagePath);
                
                Log::info('GenerateWatermarkedImageJob: Using local logo path', [
                    'original_url' => $logoUrl,
                    'local_path' => $localPath,
                    'exists' => file_exists($localPath),
                ]);
                
                if (file_exists($localPath)) {
                    return $localPath;
                }
                
                // Try public storage path as fallback
                $publicPath = public_path('storage/' . $storagePath);
                if (file_exists($publicPath)) {
                    return $publicPath;
                }
                
                // Try with realpath
                $realPath = realpath(storage_path('app/public')) . '/' . $storagePath;
                if (file_exists($realPath)) {
                    return $realPath;
                }
                
                throw new \Exception('Local logo file not found at: ' . $localPath . ' or ' . $publicPath);
            }

            // Create temp directory if it doesn't exist
            $tempDir = storage_path('app/temp/logos');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Generate unique filename
            $extension = pathinfo(parse_url($logoUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
            $filename = 'logo_' . time() . '_' . uniqid() . '.' . $extension;
            $filePath = $tempDir . '/' . $filename;

            // Download logo from external URL
            $response = Http::timeout(30)->get($logoUrl);
            
            if (!$response->successful()) {
                throw new \Exception('Failed to download logo: HTTP ' . $response->status());
            }

            // Save to temp file
            file_put_contents($filePath, $response->body());

            return $filePath;
        } catch (\Exception $e) {
            Log::error('GenerateWatermarkedImageJob: Error downloading logo', [
                'logo_url' => $logoUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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

    protected function generateWatermarkedSizes(
        string $watermarkedAbsolutePath,
        ImageManager $imageManager,
        DropboxWorkflowService $dropboxService
    ): array {
        $paths = [];

        foreach (self::WATERMARK_SIZES as $sizeName => $config) {
            try {
                $sizeImage = $imageManager->read($watermarkedAbsolutePath);
                $sizeImage->scaleDown($config['width'], $config['height']);

                $tempPath = $this->saveWatermarkedVariant($sizeImage, $sizeName, $config['quality']);
                $paths[$sizeName] = $this->storeWatermarkedOutput($tempPath, $dropboxService, $sizeName);

                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            } catch (\Exception $e) {
                Log::warning('GenerateWatermarkedImageJob: Failed to generate watermarked size', [
                    'file_id' => $this->shootFile->id,
                    'size' => $sizeName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $paths;
    }

    protected function saveWatermarkedVariant(ImageInterface $image, string $sizeName, int $quality): string
    {
        $filename = 'watermarked_' . $sizeName . '_' . time() . '_' . uniqid() . '.jpg';
        $path = storage_path('app/temp/watermarked/' . $filename);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $image->save($path, $quality);

        return $path;
    }

    /**
     * Determine the path to the original file, downloading from Dropbox if necessary.
     *
     * @return array{0:string,1:bool} [absolutePath, shouldDeleteAfter]
     */
    protected function getOriginalFilePath(DropboxWorkflowService $dropboxService): array
    {
        $candidates = array_filter([
            $this->shootFile->storage_path,
            $this->shootFile->path,
        ]);

        foreach ($candidates as $candidate) {
            $localPath = $this->resolveLocalFilePath($candidate);
            if ($localPath) {
                return [$localPath, false];
            }
        }

        if ($this->shootFile->dropbox_path) {
            $downloaded = $dropboxService->downloadToTemp($this->shootFile->dropbox_path);
            if ($downloaded && file_exists($downloaded)) {
                return [$downloaded, true];
            }
        }

        throw new \Exception('Original file could not be located for watermarking.');
    }

    protected function resolveLocalFilePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (file_exists($path)) {
            return $path;
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        return null;
    }

    protected function storeWatermarkedOutput(
        string $localTempPath,
        DropboxWorkflowService $dropboxService,
        ?string $sizeName = null
    ): string {
        if ($sizeName) {
            if ($this->shootFile->dropbox_path) {
                $targetDropboxPath = $this->buildDropboxWatermarkSizePath($sizeName);
                $uploadedPath = $dropboxService->uploadFromPath($localTempPath, $targetDropboxPath);

                if (!$uploadedPath) {
                    throw new \Exception('Failed to upload watermarked size to Dropbox.');
                }

                return $uploadedPath;
            }

            $destinationPath = $this->buildLocalWatermarkSizePath($sizeName);
            $publicDisk = Storage::disk('public');
            $publicDisk->makeDirectory(Str::beforeLast($destinationPath, '/'));
            $publicDisk->put($destinationPath, file_get_contents($localTempPath));

            return $destinationPath;
        }

        if ($this->shootFile->dropbox_path) {
            $targetDropboxPath = $this->buildDropboxWatermarkPath();
            $uploadedPath = $dropboxService->uploadFromPath($localTempPath, $targetDropboxPath);

            if (!$uploadedPath) {
                throw new \Exception('Failed to upload watermarked file to Dropbox.');
            }

            return $uploadedPath;
        }

        $destinationPath = $this->buildLocalWatermarkPath();
        $publicDisk = Storage::disk('public');
        $publicDisk->makeDirectory(Str::beforeLast($destinationPath, '/'));
        $publicDisk->put($destinationPath, file_get_contents($localTempPath));

        return $destinationPath;
    }

    protected function buildDropboxWatermarkPath(): string
    {
        $originalPath = $this->shootFile->dropbox_path ?? '';
        $directory = rtrim(dirname($originalPath), '/');

        if (!$directory || $directory === '.') {
            $directory = '/watermarked';
        } else {
            $directory .= '/watermarked';
        }

        $filename = basename($originalPath) ?: ($this->shootFile->stored_filename ?? $this->shootFile->filename ?? 'watermarked.jpg');

        return rtrim($directory, '/') . '/' . $filename;
    }

    protected function buildDropboxWatermarkSizePath(string $sizeName): string
    {
        $originalPath = $this->shootFile->dropbox_path ?? '';
        $directory = rtrim(dirname($originalPath), '/');

        if (!$directory || $directory === '.') {
            $directory = '/watermarked/' . $sizeName;
        } else {
            $directory .= '/watermarked/' . $sizeName;
        }

        $originalName = $this->shootFile->stored_filename ?? $this->shootFile->filename ?? 'watermarked.jpg';
        $basename = pathinfo($originalName, PATHINFO_FILENAME);
        $filename = $basename . '_watermarked_' . $sizeName . '.jpg';

        return rtrim($directory, '/') . '/' . $filename;
    }

    protected function buildLocalWatermarkPath(): string
    {
        $shootId = $this->shootFile->shoot_id;
        $baseDirectory = "shoots/{$shootId}/watermarked";

        $originalName = $this->shootFile->stored_filename ?? $this->shootFile->filename ?? ('file_' . $this->shootFile->id);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg';
        $basename = pathinfo($originalName, PATHINFO_FILENAME);

        $filename = $basename . '_watermarked.' . $extension;

        return $baseDirectory . '/' . $filename;
    }

    protected function buildLocalWatermarkSizePath(string $sizeName): string
    {
        $shootId = $this->shootFile->shoot_id;
        $baseDirectory = "shoots/{$shootId}/watermarked/{$sizeName}s";

        $originalName = $this->shootFile->stored_filename ?? $this->shootFile->filename ?? ('file_' . $this->shootFile->id);
        $basename = pathinfo($originalName, PATHINFO_FILENAME);

        $filename = $basename . '_watermarked_' . $sizeName . '.jpg';

        return $baseDirectory . '/' . $filename;
    }
}

