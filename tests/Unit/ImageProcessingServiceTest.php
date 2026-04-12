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
}
