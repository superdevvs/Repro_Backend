<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabImportMetadata;
use App\Services\IguideLab\LabImportNetwork;
use App\Services\IguideLab\LabImportImage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LabImportMetadataTest extends TestCase
{
    public function test_generic_manifest_retains_unknown_and_explicit_metadata_without_guessing(): void
    {
        $parsed = app(LabImportMetadata::class)->parse(json_encode(['version' => 1, 'panoramas' => [['file' => 'Kitchen/scan.png'], ['file' => 'other.png', 'panoId' => 'a', 'roomId' => 'r', 'roomName' => 'Bath']]]));
        $this->assertNull($parsed['byFile']['Kitchen/scan.png']['roomName']); $this->assertSame('r', $parsed['byFile']['other.png']['roomId']);
    }
    public function test_executable_view_object_is_not_evaluated(): void
    {
        $this->expectException(ValidationException::class);
        app(LabImportMetadata::class)->parse('<script>viewObject: {"dataV2": alert(1)}</script>');
    }
    public function test_only_observed_https_tour_routes_are_accepted(): void
    {
        $network = app(LabImportNetwork::class);
        $this->assertSame('https://youriguide.com/demo/doc/spheres.zip', $network->tour('https://youriguide.com/embed/demo/')['archiveUrl']);
        foreach (['http://youriguide.com/demo/', 'https://youriguide.com.evil.test/demo/', 'https://user@youriguide.com/demo/', 'https://youriguide.com:443/demo/',
            'https://youriguide.com/demo/../secret', 'https://youriguide.com/demo/?url=https://evil.test', 'https://youriguide.com/demo/#secret', 'https://127.0.0.1/demo/'] as $url) {
            try { $network->tour($url); $this->fail('Unsafe route accepted'); } catch (ValidationException) { $this->addToAssertionCount(1); }
        }
    }
    public function test_non_public_and_transition_addresses_are_rejected(): void
    {
        $network = app(LabImportNetwork::class);
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', '2002:7f00:1::', '2001:0::1'] as $ip) $this->assertFalse($network->publicAddress($ip), $ip);
        $this->assertTrue($network->publicAddress('8.8.8.8')); $this->assertTrue($network->publicAddress('2606:4700:4700::1111'));
    }
    public function test_truncated_image_with_valid_dimensions_is_rejected_by_decoder(): void
    {
        $image = imagecreatetruecolor(2048, 1024); ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $truncated = substr($bytes, 0, 50); $size = getimagesizefromstring($truncated); $this->assertSame(2048, $size[0]);
        $this->expectException(ValidationException::class); app(LabImportImage::class)->validate($truncated, $size);
    }
    public function test_exif_rotation_is_rejected_without_reencoding_source(): void
    {
        $image = imagecreatetruecolor(2048, 1024); ob_start(); imagejpeg($image); $bytes = ob_get_clean(); imagedestroy($image);
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 274, 3, 1, 6)."\0\0".pack('V', 0);
        $rotated = substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
        $this->expectException(ValidationException::class); app(LabImportImage::class)->validate($rotated, getimagesizefromstring($rotated));
    }
    public function test_private_dns_answer_blocks_download_before_any_socket_or_file(): void
    {
        $network = new class extends LabImportNetwork { protected function addresses(string $host): array { return ['8.8.8.8', '127.0.0.1']; } };
        $this->expectException(ValidationException::class); $network->download('https://youriguide.com/demo/doc/spheres.zip', '/must-not-be-created', 100);
    }
    public function test_embedded_icc_is_rejected_before_downstream_work(): void
    {
        $image = imagecreatetruecolor(2048, 1024); ob_start(); imagejpeg($image); $bytes = ob_get_clean(); imagedestroy($image);
        $profile = "ICC_PROFILE\0\1\1profile"; $bytes = substr($bytes, 0, 2)."\xff\xe2".pack('n', strlen($profile) + 2).$profile.substr($bytes, 2);
        $this->expectException(ValidationException::class); app(LabImportImage::class)->validate($bytes, getimagesizefromstring($bytes));
    }
    public function test_alpha_png_requires_explicit_normalization(): void
    {
        $image = imagecreatetruecolor(2048, 1024); imagealphablending($image, false); imagesavealpha($image, true); imagefill($image, 0, 0, imagecolorallocatealpha($image, 20, 30, 40, 80));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $this->expectException(ValidationException::class); app(LabImportImage::class)->validate($bytes, getimagesizefromstring($bytes));
    }
}
