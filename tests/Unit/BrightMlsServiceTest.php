<?php

namespace Tests\Unit;

use App\Services\BrightMls\LegacyBrightMlsStrategy;
use App\Services\BrightMls\NewBrightMlsStrategy;
use App\Services\BrightMlsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrightMlsServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function build_manifest_from_shoot_normalizes_photo_filenames_to_jpeg(): void
    {
        $this->configureBrightMls();

        $manifest = app(BrightMlsService::class)->buildManifestFromShoot([
            'address' => '123 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'mls_id' => 'MLS-123',
        ], [
            'photos' => [
                [
                    'url' => 'https://cdn.example.com/shoots/1/webs/front_web.jpg',
                    'filename' => 'front.NEF',
                    'selected' => true,
                ],
                [
                    'url' => 'https://cdn.example.com/shoots/1/webs/kitchen_web.jpg?token=abc',
                    'filename' => 'kitchen.png',
                    'selected' => true,
                ],
                [
                    'url' => 'https://cdn.example.com/shoots/1/webs/entryway_web.jpg',
                    'filename' => 'Entryway Final',
                    'selected' => true,
                ],
            ],
        ]);

        $photoFileNames = collect($manifest['listItems'])
            ->where('mediaType', 'photo')
            ->pluck('fileName')
            ->values()
            ->all();

        $this->assertSame([
            'front_web.jpg',
            'kitchen_web.jpg',
            'entryway_web.jpg',
        ], $photoFileNames);
    }

    #[Test]
    public function build_manifest_from_shoot_caps_long_photo_filename_to_50_characters(): void
    {
        $this->configureBrightMls();

        $manifest = app(BrightMlsService::class)->buildManifestFromShoot($this->shootData(), [
            'photos' => [
                [
                    'url' => 'https://cdn.example.com/render?id=1',
                    'filename' => str_repeat('front-room-wide-angle-', 4) . 'final.png',
                    'selected' => true,
                ],
            ],
        ]);

        $fileName = $this->firstFileNameForMediaType($manifest, 'photo');

        $this->assertLessThanOrEqual(50, strlen($fileName));
        $this->assertStringEndsWith('-1.jpg', $fileName);
    }

    #[Test]
    public function build_manifest_from_shoot_caps_long_url_derived_jpeg_filename(): void
    {
        $this->configureBrightMls();

        $manifest = app(BrightMlsService::class)->buildManifestFromShoot($this->shootData(), [
            'photos' => [
                [
                    'url' => 'https://cdn.example.com/shoots/1/webs/' . str_repeat('kitchen-gallery-', 4) . 'web.jpg?token=abc',
                    'filename' => 'kitchen.NEF',
                    'selected' => true,
                ],
            ],
        ]);

        $fileName = $this->firstFileNameForMediaType($manifest, 'photo');

        $this->assertLessThanOrEqual(50, strlen($fileName));
        $this->assertStringEndsWith('-1.jpg', $fileName);
    }

    #[Test]
    public function build_manifest_from_shoot_caps_long_floor_plan_pdf_filename(): void
    {
        $this->configureBrightMls();

        $manifest = app(BrightMlsService::class)->buildManifestFromShoot($this->shootData(), [
            'documents' => [
                [
                    'url' => 'https://cdn.example.com/floorplans/long-floor-plan.pdf',
                    'filename' => str_repeat('detailed-floor-plan-', 4) . 'final.pdf',
                    'type' => 'floor_plan',
                    'description' => 'Floor plan',
                ],
            ],
        ]);

        $fileName = $this->firstFileNameForMediaType($manifest, 'floor_plan');

        $this->assertLessThanOrEqual(50, strlen($fileName));
        $this->assertStringEndsWith('-1.pdf', $fileName);
    }

    #[Test]
    public function build_manifest_from_shoot_keeps_short_filenames_and_uses_fallbacks(): void
    {
        $this->configureBrightMls();

        $manifest = app(BrightMlsService::class)->buildManifestFromShoot($this->shootData(), [
            'photos' => [
                [
                    'url' => 'https://cdn.example.com/front.jpg',
                    'filename' => 'front.jpg',
                    'selected' => true,
                ],
                [
                    'url' => 'https://cdn.example.com/render?id=2',
                    'filename' => '.',
                    'selected' => true,
                ],
            ],
        ]);

        $photoFileNames = collect($manifest['listItems'])
            ->where('mediaType', 'photo')
            ->pluck('fileName')
            ->values()
            ->all();

        $this->assertSame(['front.jpg', 'photo-2.jpg'], $photoFileNames);
    }

    #[Test]
    public function bright_mls_strategies_reject_file_names_over_50_characters(): void
    {
        $payload = [
            'vendorId' => 'vendor-test',
            'vendorName' => 'Repro Photos',
            'dateFileCreated' => now()->toIso8601String(),
            'listItems' => [
                [
                    'fileName' => str_repeat('a', 47) . '.jpg',
                    'imageUrls' => ['fullSize' => 'https://cdn.example.com/front.jpg'],
                    'lastModified' => now()->toIso8601String(),
                    'mediaType' => 'photo',
                    'description' => '',
                    'id' => 1,
                ],
            ],
        ];

        $expectedError = 'Item 1: fileName must be 50 characters or fewer.';

        $this->assertContains(
            $expectedError,
            (new LegacyBrightMlsStrategy('https://bright.example.test', 'https://import.example.test'))->validatePayload($payload)
        );
        $this->assertContains(
            $expectedError,
            (new NewBrightMlsStrategy('https://bright.example.test'))->validatePayload($payload)
        );
    }

    private function configureBrightMls(): void
    {
        config([
            'services.bright_mls.enabled' => true,
            'services.bright_mls.api_mode' => 'legacy',
            'services.bright_mls.environment' => 'p1',
            'services.bright_mls.vendor_id' => 'vendor-test',
            'services.bright_mls.api_key' => 'api-key-test',
            'services.bright_mls.vendor_name' => 'Repro Photos',
        ]);
    }

    private function shootData(): array
    {
        return [
            'address' => '123 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'mls_id' => 'MLS-123',
        ];
    }

    private function firstFileNameForMediaType(array $manifest, string $mediaType): string
    {
        return (string) collect($manifest['listItems'])
            ->firstWhere('mediaType', $mediaType)['fileName'];
    }
}
