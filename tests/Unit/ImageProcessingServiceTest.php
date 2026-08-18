<?php

namespace Tests\Unit;

use App\Services\ImageProcessingService;
use App\Services\RawThumbnailService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImageProcessingServiceTest extends TestCase
{
    #[Test]
    public function it_processes_uploaded_images_from_temp_paths_without_relying_on_the_tmp_extension(): void
    {
        Storage::fake('public');

        $uploadedImage = UploadedFile::fake()->image('preview-source.jpg', 1600, 900);

        $service = app(ImageProcessingService::class);
        $generated = $service->processImageFromPath(
            123,
            $uploadedImage->getClientOriginalName(),
            $uploadedImage->getRealPath()
        );

        $this->assertArrayHasKey('thumbnail', $generated);
        $this->assertArrayHasKey('web', $generated);
        $this->assertArrayHasKey('placeholder', $generated);

        Storage::disk('public')->assertExists($generated['thumbnail']);
        Storage::disk('public')->assertExists($generated['web']);
        Storage::disk('public')->assertExists($generated['placeholder']);
    }

    #[Test]
    public function it_uses_the_raw_thumbnail_service_pipeline_for_cr3_files(): void
    {
        Storage::fake('public');

        $previewSource = UploadedFile::fake()->image('embedded-preview.jpg', 1600, 900);
        $tempRawPath = tempnam(sys_get_temp_dir(), 'raw-preview-test-');
        $rawPath = $tempRawPath . '.cr3';
        rename($tempRawPath, $rawPath);
        file_put_contents($rawPath, 'not-a-real-cr3');

        $rawThumbnailService = new class($previewSource) extends RawThumbnailService {
            public function __construct(private UploadedFile $previewSource)
            {
            }

            public function generateThumbnail(string $sourcePath, string $thumbnailDir, ?string $thumbnailName = null): ?string
            {
                $relativePath = $thumbnailDir . '/' . ($thumbnailName ?? 'raw-thumb.jpg');
                Storage::disk('public')->put($relativePath, file_get_contents($this->previewSource->getRealPath()));

                return $relativePath;
            }
        };

        $service = new ImageProcessingService($rawThumbnailService);
        $generated = $service->processImageFromPath(456, 'sample.cr3', $rawPath);

        $this->assertArrayHasKey('thumbnail', $generated);
        $this->assertArrayHasKey('web', $generated);
        $this->assertArrayHasKey('placeholder', $generated);

        Storage::disk('public')->assertExists($generated['thumbnail']);
        Storage::disk('public')->assertExists($generated['web']);
        Storage::disk('public')->assertExists($generated['placeholder']);

        $webImageInfo = getimagesize(Storage::disk('public')->path($generated['web']));
        $this->assertNotFalse($webImageInfo);
        $this->assertGreaterThan(360, $webImageInfo[0]);

        @unlink($rawPath);
    }

    /**
     * The `grid` rendition (1000px) exists so desktop media tiles stop upscaling
     * the 300px thumbnail. It is only useful if the browser can actually fetch
     * it, which means it must land on the PUBLIC disk alongside the other
     * web-facing renditions.
     *
     * It previously fell through to the `local` disk, whose root is
     * storage/app/private — outside the web-accessible tree. Generation
     * reported success and grid_path was recorded, but the file could never be
     * served, so every tile silently fell back to the 300px thumbnail and
     * looked blurred.
     */
    #[Test]
    public function it_stores_the_grid_rendition_on_the_public_disk(): void
    {
        Storage::fake('public');

        $uploadedImage = UploadedFile::fake()->image('grid-source.jpg', 2400, 1600);

        $service = app(ImageProcessingService::class);
        $generated = $service->processImageFromPath(
            789,
            $uploadedImage->getClientOriginalName(),
            $uploadedImage->getRealPath()
        );

        $this->assertArrayHasKey('grid', $generated, 'the grid rendition must be generated');
        Storage::disk('public')->assertExists($generated['grid']);
    }

    /**
     * A grid tile is only sharper than the thumbnail if it is actually bigger.
     */
    #[Test]
    public function the_grid_rendition_is_larger_than_the_thumbnail(): void
    {
        Storage::fake('public');

        $uploadedImage = UploadedFile::fake()->image('grid-size.jpg', 2400, 1600);

        $service = app(ImageProcessingService::class);
        $generated = $service->processImageFromPath(
            790,
            $uploadedImage->getClientOriginalName(),
            $uploadedImage->getRealPath()
        );

        $grid = getimagesize(Storage::disk('public')->path($generated['grid']));
        $thumb = getimagesize(Storage::disk('public')->path($generated['thumbnail']));

        $this->assertNotFalse($grid);
        $this->assertGreaterThan(600, $grid[0], 'grid must be big enough for a 2x desktop tile');
        $this->assertGreaterThan($thumb[0], $grid[0]);
    }
}
