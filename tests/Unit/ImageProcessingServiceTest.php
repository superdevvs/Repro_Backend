<?php

namespace Tests\Unit;

use App\Services\ImageProcessingService;
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
}
