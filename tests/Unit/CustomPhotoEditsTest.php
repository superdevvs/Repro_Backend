<?php

namespace Tests\Unit;

use App\Services\Studio\CustomPhotoEdits;
use App\Services\Studio\PhotoPresetOptions;
use Tests\TestCase;

class CustomPhotoEditsTest extends TestCase
{
    public function test_logo_renders_at_the_requested_position_and_respects_opacity(): void
    {
        $photo = imagecreatetruecolor(160, 90);
        ob_start();
        imagepng($photo);
        $photoBytes = ob_get_clean();
        $logo = imagecreatetruecolor(100, 50);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 240, 30, 30));
        ob_start();
        imagepng($logo);
        $logoBytes = ob_get_clean();
        $recipe = ['logo' => ['id' => '11111111-1111-4111-8111-111111111111', 'x' => .1, 'y' => .1, 'width' => .2, 'opacity' => 100]];
        $pixels = imagecreatefromstring((new CustomPhotoEdits)->apply($photoBytes, $recipe, $logoBytes));
        $this->assertGreaterThan(220, (imagecolorat($pixels, 25, 15) >> 16) & 255);
        $this->assertLessThan(5, (imagecolorat($pixels, 120, 60) >> 16) & 255);
        $recipe['logo']['opacity'] = 0;
        $hidden = imagecreatefromstring((new CustomPhotoEdits)->apply($photoBytes, $recipe, $logoBytes));
        $this->assertLessThan(5, (imagecolorat($hidden, 25, 15) >> 16) & 255);
    }

    public function test_selective_color_changes_red_without_changing_green(): void
    {
        $image = imagecreatetruecolor(80, 40);
        imagefilledrectangle($image, 0, 0, 39, 39, imagecolorallocate($image, 200, 40, 40));
        imagefilledrectangle($image, 40, 0, 79, 39, imagecolorallocate($image, 40, 200, 40));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        $result = (new CustomPhotoEdits)->apply($bytes, ['color' => ['red' => ['saturation' => -100]]]);
        $pixels = imagecreatefromstring($result);
        $red = imagecolorat($pixels, 20, 20);
        $green = imagecolorat($pixels, 60, 20);
        $this->assertEqualsWithDelta(($red >> 16) & 255, ($red >> 8) & 255, 3);
        $this->assertEqualsWithDelta(200, ($green >> 8) & 255, 3);
        $this->assertEqualsWithDelta(40, ($green >> 16) & 255, 3);
    }

    public function test_color_and_privacy_processing_changes_pixels_and_keeps_dimensions(): void
    {
        $image = imagecreatetruecolor(64, 32);
        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 32; $y++) {
                imagesetpixel($image, $x, $y, (($x % 2 ? 200 : 20) << 16) | (60 << 8) | 30);
            }
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        $result = (new CustomPhotoEdits)->apply($bytes, ['saturation' => -100, 'blur' => [['x' => 0, 'y' => 0, 'width' => .5, 'height' => 1]]]);
        $pixels = imagecreatefromstring($result);
        $this->assertSame([64, 32], array_slice(getimagesizefromstring($result), 0, 2));
        $a = imagecolorat($pixels, 5, 10);
        $b = imagecolorat($pixels, 6, 10);
        $this->assertEqualsWithDelta(($a >> 16) & 255, ($a >> 8) & 255, 3);
        $this->assertEqualsWithDelta(($a >> 16) & 255, $a & 255, 3);
        $this->assertEqualsWithDelta(($a >> 16) & 255, ($b >> 16) & 255, 3);
    }

    public function test_annotations_and_disclosure_render_into_pixels(): void
    {
        $image = imagecreatetruecolor(640, 360);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        $result = (new CustomPhotoEdits)->apply($bytes, ['caption' => 'Virtually staged', 'marks' => [['kind' => 'boundary', 'x' => .1, 'y' => .1, 'width' => .5, 'height' => .5, 'label' => 'Property']]]);
        $pixels = imagecreatefromstring($result);
        $this->assertNotSame(0, imagecolorat($pixels, 64, 36) & 0xFFFFFF);
        $hasText = false;
        for ($x = 8; $x < 150; $x++) {
            for ($y = 337; $y < 359; $y++) {
                if ((imagecolorat($pixels, $x, $y) & 0xFFFFFF) > 0) {
                    $hasText = true;
                }
            }
        }
        $this->assertTrue($hasText);
    }

    public function test_provider_payloads_use_exact_published_fields_and_ignore_other_presets(): void
    {
        $payload = PhotoPresetOptions::autoenhance('listing-ready', ['enhanceType' => 'warm', 'windowPull' => 'ONLY_WINDOWS', 'privacy' => true, 'fireplace' => 'ALIGHT', 'tv' => 'BLACK_OUT', 'roomType' => 'living']);
        $this->assertSame('warm', $payload['enhance_type']);
        $this->assertSame('ONLY_WINDOWS', $payload['window_pull_type']);
        $this->assertTrue($payload['privacy']);
        $this->assertSame(['fire_in_fireplaces' => 'ALIGHT', 'tvs' => 'BLACK_OUT'], $payload['restage']);
        $this->assertArrayNotHasKey('roomType', $payload);
        $this->assertArrayNotHasKey('vertical_correction', $payload);
        $this->assertTrue($payload['perspective_correction']);
        $this->assertSame(['enhance' => false, 'upscale' => true, 'lens_correction' => false, 'perspective_correction' => false, 'sky_replacement' => false], PhotoPresetOptions::autoenhance('upscale', ['skyReplacement' => true, 'fireplace' => 'ALIGHT']));
        $this->assertSame(['cloud_style' => 'clear_fade', 'custom_style_id' => 'account-style'], PhotoPresetOptions::fotello(['cloud_style' => 'clear_fade', 'custom_style_id' => 'account-style', 'contrast' => 40, 'skyReplacement' => true]));
    }
}
