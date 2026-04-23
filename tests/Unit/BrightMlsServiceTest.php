<?php

namespace Tests\Unit;

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
        config([
            'services.bright_mls.enabled' => true,
            'services.bright_mls.api_mode' => 'legacy',
            'services.bright_mls.environment' => 'p1',
            'services.bright_mls.vendor_id' => 'vendor-test',
            'services.bright_mls.api_key' => 'api-key-test',
            'services.bright_mls.vendor_name' => 'Repro Photos',
        ]);

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
}
