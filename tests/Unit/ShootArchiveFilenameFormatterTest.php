<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Services\Shoots\ShootArchiveFilenameFormatter;
use PHPUnit\Framework\TestCase;

class ShootArchiveFilenameFormatterTest extends TestCase
{
    public function test_zip_names_include_the_full_property_address_and_selected_resolution(): void
    {
        $shoot = new Shoot([
            'address' => '11 Wall Street', 'city' => 'Bernhards Bay', 'state' => 'NY', 'zip' => '13028',
        ]);
        $formatter = new ShootArchiveFilenameFormatter();

        $this->assertSame('11-wall-street-bernhards-bay-ny-13028-raw-files.zip', $formatter->rawFiles($shoot));
        $this->assertSame('11-wall-street-bernhards-bay-ny-13028-selected-mls.zip', $formatter->selection($shoot, 'small'));
        $this->assertSame('11-wall-street-bernhards-bay-ny-13028-selected-print.zip', $formatter->selection($shoot, 'original'));
    }

    public function test_missing_or_unusable_address_has_a_stable_shoot_fallback(): void
    {
        $shoot = new Shoot(['address' => " /\\\r\n ", 'city' => '', 'state' => '', 'zip' => '']);
        $shoot->id = 63;

        $this->assertSame('shoot-63-raw-files.zip', (new ShootArchiveFilenameFormatter())->rawFiles($shoot));
    }

    public function test_response_filename_is_safe_and_bounded_without_changing_the_cached_slug(): void
    {
        $shoot = new Shoot([
            'address' => '../11 Rue de l’Été "Unit 2"'."\r\n".str_repeat(' long address', 40),
            'city' => 'Montréal', 'state' => 'QC', 'zip' => '12345',
        ]);
        $formatter = new ShootArchiveFilenameFormatter();
        $filename = $formatter->selection($shoot, 'original');

        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*-selected-print\.zip$/D', $filename);
        $this->assertLessThanOrEqual(200, strlen($filename));
        $this->assertStringStartsWith('11-rue-de-lete-unit-2-', $filename);
        $this->assertGreaterThan(180, strlen($formatter->propertySlug($shoot)));
        $this->assertStringEndsWith('-montreal-qc-12345', $formatter->propertySlug($shoot));
    }
}
