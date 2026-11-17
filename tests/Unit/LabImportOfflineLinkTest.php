<?php

namespace Tests\Unit;

use App\Services\IguideLab\LabImportNetwork;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LabImportOfflineLinkTest extends TestCase
{
    private const TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0b3VyIn0.a-b_c';

    public function test_offline_package_link_is_a_fixed_package_route_with_its_token_kept_private_from_the_public_url(): void
    {
        $url = 'https://youriguide.com/3024_ebbtide_dr_edgewood_md/doc/offline_en.zip?authtoken='.self::TOKEN;
        $tour = app(LabImportNetwork::class)->tour($url);
        $this->assertSame('3024_ebbtide_dr_edgewood_md', $tour['alias']);
        $this->assertSame('https://youriguide.com/3024_ebbtide_dr_edgewood_md/', $tour['publicUrl']);
        $this->assertSame($url, $tour['archiveUrl']);
        $this->assertSame($url, $tour['pageUrl']);
        $this->assertTrue($tour['offlinePackage']);
        $this->assertSame($tour, app(LabImportNetwork::class)->tour($tour['pageUrl']));
    }

    public function test_tour_links_are_unchanged_and_not_offline_packages(): void
    {
        $tour = app(LabImportNetwork::class)->tour('https://youriguide.com/sample/?pano=4&accessToken=secret');
        $this->assertSame(['alias' => 'sample', 'publicUrl' => 'https://youriguide.com/sample/', 'pageUrl' => 'https://youriguide.com/sample/?accessToken=secret',
            'archiveUrl' => 'https://youriguide.com/sample/doc/spheres.zip?accessToken=secret', 'offlinePackage' => false], $tour);
    }

    #[DataProvider('rejectedLinks')]
    public function test_other_package_shapes_are_rejected(string $url): void
    {
        try { app(LabImportNetwork::class)->tour($url); $this->fail('Accepted '.$url); }
        catch (ValidationException $error) { $this->assertLessThanOrEqual(200, strlen($error->errors()['source'][0])); }
    }

    public static function rejectedLinks(): array
    {
        $t = self::TOKEN; $base = 'https://youriguide.com/sample/doc/';
        return [
            'other doc file' => [$base.'offline_fr.zip?authtoken='.$t],
            'spheres archive with authtoken' => [$base.'spheres.zip?authtoken='.$t],
            'floor plan' => [$base.'floor_imperial_en_1.svg?authtoken='.$t],
            'no token' => [$base.'offline_en.zip'],
            'empty token' => [$base.'offline_en.zip?authtoken='],
            'access token name' => [$base.'offline_en.zip?accessToken='.$t],
            'name case' => [$base.'offline_en.zip?AuthToken='.$t],
            'extra param' => [$base.'offline_en.zip?authtoken='.$t.'&pano=1'],
            'leading param' => [$base.'offline_en.zip?pano=1&authtoken='.$t],
            'repeated token' => [$base.'offline_en.zip?authtoken='.$t.'&authtoken='.$t],
            'encoded token' => [$base.'offline_en.zip?authtoken=a%2Eb'],
            'token charset' => [$base.'offline_en.zip?authtoken=a+b'],
            'token too long' => [$base.'offline_en.zip?authtoken='.str_repeat('a', 4097)],
            'fragment' => [$base.'offline_en.zip?authtoken='.$t.'#x'],
            'port' => ['https://youriguide.com:443/sample/doc/offline_en.zip?authtoken='.$t],
            'http' => ['http://youriguide.com/sample/doc/offline_en.zip?authtoken='.$t],
            'other host' => ['https://www.youriguide.com/sample/doc/offline_en.zip?authtoken='.$t],
            'lookalike host' => ['https://youriguide.com.evil.test/sample/doc/offline_en.zip?authtoken='.$t],
            'credentials' => ['https://u:p@youriguide.com/sample/doc/offline_en.zip?authtoken='.$t],
            'embed path' => ['https://youriguide.com/embed/sample/doc/offline_en.zip?authtoken='.$t],
            'nested path' => ['https://youriguide.com/sample/x/doc/offline_en.zip?authtoken='.$t],
            'traversal' => ['https://youriguide.com/sample/../doc/offline_en.zip?authtoken='.$t],
            'trailing slash' => [$base.'offline_en.zip/?authtoken='.$t],
            'upper case file' => [$base.'OFFLINE_EN.zip?authtoken='.$t],
        ];
    }

    #[DataProvider('downloadRoutes')]
    public function test_download_allow_list_admits_only_fixed_provider_routes(string $url, bool $allowed): void
    {
        // A private address stops every admitted route before any connection is opened.
        $network = new class extends LabImportNetwork { protected function addresses(string $host): array { return ['10.0.0.1']; } };
        try { $network->download($url, sys_get_temp_dir().'/oi-never-'.bin2hex(random_bytes(6)), 1024); $this->fail('Downloaded '.$url); }
        catch (ValidationException $error) {
            $this->assertSame($allowed ? 'The provider address is not a public network address.' : 'Unsupported provider download route.', $error->errors()['source'][0]);
        }
    }

    public static function downloadRoutes(): array
    {
        $t = self::TOKEN;
        return [
            'tour page' => ['https://youriguide.com/sample/?accessToken=x', true],
            'spheres archive' => ['https://youriguide.com/sample/doc/spheres.zip?accessToken=x', true],
            'offline package' => ['https://youriguide.com/sample/doc/offline_en.zip?authtoken='.$t, true],
            'offline package without token' => ['https://youriguide.com/sample/doc/offline_en.zip', false],
            'offline package extra param' => ['https://youriguide.com/sample/doc/offline_en.zip?authtoken='.$t.'&x=1', false],
            'offline package access token' => ['https://youriguide.com/sample/doc/offline_en.zip?accessToken='.$t, false],
            'other doc file' => ['https://youriguide.com/sample/doc/offline_fr.zip?authtoken='.$t, false],
            'asset' => ['https://youriguide.com/sample/assets/p1-s_4096x.jpg', false],
            'other host' => ['https://evil.test/sample/doc/offline_en.zip?authtoken='.$t, false],
            'fragment' => ['https://youriguide.com/sample/doc/offline_en.zip?authtoken='.$t.'#f', false],
        ];
    }
}
