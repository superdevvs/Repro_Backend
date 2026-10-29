<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShowcasePremiumVideoIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_enables_both_lanes_without_changing_any_other_package_settings(): void
    {
        $service = Service::factory()->create([
            'id' => 85, 'name' => 'Zillow SHOWCASE Premium',
            'description' => 'Photos , 1 Minute Social Media Video & Zillow 3D with Floor plans',
            'upload_intake_type' => 'photo', 'uses_hdr_brackets' => true,
            'price' => 425, 'photo_count' => 50,
        ]);
        $before = $service->getAttributes();
        $migration = require database_path('migrations/2026_10_29_030000_enable_showcase_premium_video_intake.php');
        $migration->up(); $migration->up();
        $expected = array_replace($before, ['upload_intake_type' => 'photo_video']);
        $this->assertSame($expected, $service->fresh()->getAttributes());
        $this->assertTrue($service->fresh()->supportsIntakeLane('photo'));
        $this->assertTrue($service->fresh()->supportsIntakeLane('video'));
    }

    #[DataProvider('customPackages')]
    public function test_correction_preserves_other_or_customized_packages(array $overrides): void
    {
        $service = Service::factory()->create(array_replace([
            'id' => 85, 'name' => 'Zillow SHOWCASE Premium',
            'description' => 'Photos and 1 Minute Social Media Video',
            'upload_intake_type' => 'photo',
        ], $overrides));
        $before = $service->getAttributes();
        $migration = require database_path('migrations/2026_10_29_030000_enable_showcase_premium_video_intake.php');
        $migration->up();
        $this->assertSame($before, $service->fresh()->getAttributes());
    }

    public static function customPackages(): array
    {
        return [
            'other catalog id' => [['id' => 86]],
            'renamed package' => [['name' => 'Custom Showcase']],
            'no promised video' => [['description' => 'Photos and floor plans']],
            'disabled intake' => [['upload_intake_type' => 'none']],
            'already corrected' => [['upload_intake_type' => 'photo_video']],
        ];
    }
}
