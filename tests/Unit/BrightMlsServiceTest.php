<?php

namespace Tests\Unit;

use App\Services\BrightMls\LegacyBrightMlsStrategy;
use App\Services\BrightMls\NewBrightMlsStrategy;
use App\Services\BrightMlsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrightMlsServiceTest extends TestCase
{
    use RefreshDatabase;

    public static function apiModes(): array
    {
        return [['legacy'], ['new']];
    }

    #[Test]
    #[DataProvider('apiModes')]
    public function publishing_updates_vendor_credit_even_with_saved_legacy_branding(string $mode): void
    {
        $this->configureBrightMls();
        config(['services.bright_mls.api_mode' => $mode]);
        DB::table('settings')->insert([
            'key' => 'integrations.bright_mls',
            'type' => 'json',
            'value' => json_encode(['vendorName' => 'Repro Photos']),
        ]);
        Http::preventStrayRequests();
        Http::fake(['*/manifest' => Http::response(['manifestId' => 'manifest-test'], 200)]);

        $service = app(BrightMlsService::class);
        $manifest = $service->buildManifestFromShoot($this->shootData(), [
            'photos' => [['url' => 'https://cdn.example.com/front.jpg', 'filename' => 'front.jpg']],
        ]);
        $this->assertSame('R/E Pro Photos', $manifest['vendorName']);

        // Older callers or stored manifests must not restore the previous spelling.
        $manifest['vendorName'] = 'Repro Photos';
        $result = $service->publishManifest($manifest);

        $this->assertTrue($result['success']);
        $this->assertSame('R/E Pro Photos', $result['payload_snapshot']['vendorName']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $payload['vendorName'] === 'R/E Pro Photos'
                && $payload['vendorId'] === 'vendor-test'
                && $request->hasHeader('x-api-user', 'vendor-test')
                && $payload['mlsId'] === 'MLS-123'
                && $payload['listItems'][0]['imageUrls']['fullSize'] === 'https://cdn.example.com/front.jpg';
        });
    }

    #[Test]
    #[DataProvider('apiModes')]
    public function strategies_default_to_current_vendor_credit(string $mode): void
    {
        $strategy = $mode === 'legacy'
            ? new LegacyBrightMlsStrategy('https://bright.example.test', 'https://import.example.test')
            : new NewBrightMlsStrategy('https://bright.example.test');

        $payload = $strategy->buildManifest([
            'vendorId' => 'vendor-test',
            'listItems' => [],
        ]);

        $this->assertSame('R/E Pro Photos', $payload['vendorName']);
        $this->assertSame('vendor-test', $payload['vendorId']);
    }

    #[Test]
    #[DataProvider('apiModes')]
    public function vendor_credit_normalization_preserves_custom_saved_names(string $mode): void
    {
        $this->configureBrightMls();
        config(['services.bright_mls.api_mode' => $mode]);
        Http::preventStrayRequests();
        foreach (['   ' => 'R/E Pro Photos', ' rEpRo PhOtOs ' => 'R/E Pro Photos', 'Custom Photo Studio' => 'Custom Photo Studio'] as $saved => $expected) {
            DB::table('settings')->updateOrInsert(['key' => 'integrations.bright_mls'],
                ['type' => 'json', 'value' => json_encode(['vendorName' => $saved])]);
            $manifest = (new BrightMlsService)->buildManifestFromShoot($this->shootData(), [
                'photos' => [['url' => 'https://cdn.example.com/front.jpg', 'filename' => 'front.jpg']],
            ]);
            $this->assertSame($expected, $manifest['vendorName']);
        }
    }

    public static function vendorSettings(): array
    {
        $cases = [
            'missing settings and config' => [null, [], 'R/E Pro Photos'],
            'null config' => [null, ['vendor_name' => null], 'R/E Pro Photos'],
            'blank config' => [null, ['vendor_name' => '  '], 'R/E Pro Photos'],
            'legacy config' => [null, ['vendor_name' => ' RePrO PhOtOs '], 'R/E Pro Photos'],
            'custom config' => [null, ['vendor_name' => 'Acme Photography'], 'Acme Photography'],
            'missing saved name' => [[], ['vendor_name' => 'Acme Photography'], 'Acme Photography'],
            'null saved name' => [['vendorName' => null], ['vendor_name' => 'Acme Photography'], 'Acme Photography'],
            'blank saved name' => [['vendorName' => ''], ['vendor_name' => 'Acme Photography'], 'R/E Pro Photos'],
            'whitespace saved name' => [['vendorName' => '  '], [], 'R/E Pro Photos'],
            'legacy saved name' => [['vendorName' => 'Repro Photos'], [], 'R/E Pro Photos'],
            'mixed case saved name' => [['vendorName' => ' rEpRo pHoToS '], ['vendor_name' => 'Acme Photography'], 'R/E Pro Photos'],
            'current saved name' => [['vendorName' => 'R/E Pro Photos'], [], 'R/E Pro Photos'],
            'custom saved name' => [['vendorName' => 'Acme Photography'], ['vendor_name' => 'Repro Photos'], 'Acme Photography'],
            'custom legacy prefix' => [['vendorName' => 'Repro Photos Studio'], [], 'Repro Photos Studio'],
            'custom legacy suffix' => [['vendorName' => 'My Repro Photos'], [], 'My Repro Photos'],
        ];

        $result = [];
        foreach (['legacy', 'new'] as $mode) {
            foreach ($cases as $name => $case) {
                $result[$mode . ': ' . $name] = [$mode, ...$case];
            }
        }

        return $result;
    }

    #[Test]
    #[DataProvider('vendorSettings')]
    public function publishing_normalizes_default_credit_and_preserves_custom_settings(
        string $mode,
        ?array $settings,
        array $vendorConfig,
        string $expectedVendorName
    ): void
    {
        $this->configureBrightMls();
        config(['services.bright_mls' => array_replace(
            array_diff_key(config('services.bright_mls'), ['vendor_name' => true]),
            $vendorConfig,
            ['api_mode' => $mode]
        )]);
        if ($settings !== null) {
            DB::table('settings')->insert([
                'key' => 'integrations.bright_mls',
                'type' => 'json',
                'value' => json_encode($settings),
            ]);
        }
        Http::preventStrayRequests();
        Http::fake(['*/manifest' => Http::response(['manifestId' => 'manifest-test'], 200)]);

        $service = app(BrightMlsService::class);
        $manifest = $service->buildManifestFromShoot($this->shootData(), [
            'photos' => [['url' => 'https://cdn.example.com/front.jpg', 'filename' => 'front.jpg']],
        ]);
        $this->assertSame($expectedVendorName, $manifest['vendorName']);

        // Older callers or stored manifests must not restore the previous spelling.
        $manifest['vendorName'] = 'Repro Photos';
        $result = $service->publishManifest($manifest);

        $this->assertTrue($result['success']);
        $this->assertSame($expectedVendorName, $result['payload_snapshot']['vendorName']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($expectedVendorName) {
            $payload = $request->data();

            return $payload['vendorName'] === $expectedVendorName
                && $payload['vendorId'] === 'vendor-test'
                && $request->hasHeader('x-api-user', 'vendor-test')
                && $payload['mlsId'] === 'MLS-123'
                && $payload['listItems'][0]['imageUrls']['fullSize'] === 'https://cdn.example.com/front.jpg';
        });
    }

    public static function manifestVendorNames(): array
    {
        $cases = [
            'missing' => [[], 'R/E Pro Photos'],
            'null' => [['vendorName' => null], 'R/E Pro Photos'],
            'blank' => [['vendorName' => ''], 'R/E Pro Photos'],
            'whitespace' => [['vendorName' => '  '], 'R/E Pro Photos'],
            'legacy' => [['vendorName' => 'Repro Photos'], 'R/E Pro Photos'],
            'mixed case legacy' => [['vendorName' => ' rEpRo pHoToS '], 'R/E Pro Photos'],
            'current' => [['vendorName' => 'R/E Pro Photos'], 'R/E Pro Photos'],
            'custom' => [['vendorName' => 'Acme Photography'], 'Acme Photography'],
            'custom legacy prefix' => [['vendorName' => 'Repro Photos Studio'], 'Repro Photos Studio'],
        ];

        $result = [];
        foreach (['legacy', 'new'] as $mode) {
            foreach ($cases as $name => $case) {
                $result[$mode . ': ' . $name] = [$mode, ...$case];
            }
        }

        return $result;
    }

    #[Test]
    #[DataProvider('manifestVendorNames')]
    public function strategies_normalize_default_credit_and_preserve_custom_names(
        string $mode,
        array $vendorData,
        string $expectedVendorName
    ): void
    {
        $strategy = $mode === 'legacy'
            ? new LegacyBrightMlsStrategy('https://bright.example.test', 'https://import.example.test')
            : new NewBrightMlsStrategy('https://bright.example.test');

        $payload = $strategy->buildManifest($vendorData + [
            'vendorId' => 'vendor-test',
            'listItems' => [],
        ]);

        $this->assertSame($expectedVendorName, $payload['vendorName']);
        $this->assertSame('vendor-test', $payload['vendorId']);
    }

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
