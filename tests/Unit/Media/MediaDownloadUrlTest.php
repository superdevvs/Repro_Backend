<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaDownloadUrl;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MediaDownloadUrlTest extends TestCase
{
    public function test_download_signature_is_bound_to_origin_path_filename_and_expiry(): void
    {
        config(['media.download_origin' => 'https://downloads.reprodashboard.com']);
        $url = app(MediaDownloadUrl::class)->signed('shoots/1/todo/a.raf', 600, 'My file.raf');
        $this->assertStringStartsWith('https://downloads.reprodashboard.com/', $url);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        foreach (['downloads.reprodashboard.com' => 'reprodashboard.com', 'a.raf' => 'b.raf', 'My%20file.raf' => 'Other.raf'] as $from => $to) {
            $this->assertFalse(URL::hasValidSignature(Request::create(str_replace($from, $to, $url))));
        }
        $this->travel(601)->seconds();
        $this->assertFalse(URL::hasValidSignature(Request::create($url)));
    }

    public function test_previews_and_global_url_generator_keep_the_application_origin(): void
    {
        $before = URL::to('/api/test');
        config(['media.download_origin' => 'https://downloads.reprodashboard.com']);
        $media = app(MediaStorage::class);
        $this->assertStringStartsWith('https://downloads.reprodashboard.com/', $media->signedAppUrl('shoots/1/archives/all.zip'));
        $this->assertSame(parse_url($before, PHP_URL_HOST), parse_url($media->signedAppUrl('shoots/1/web/a.jpg'), PHP_URL_HOST));
        $this->assertSame($before, URL::to('/api/test'));
    }

    public function test_disabled_origin_preserves_existing_signed_urls(): void
    {
        config(['media.download_origin' => '']);
        $url = app(MediaDownloadUrl::class)->signed('shoots/1/todo/a.raf', 600);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        $this->assertSame(parse_url(URL::to('/'), PHP_URL_HOST), parse_url($url, PHP_URL_HOST));
    }

    public function test_unsafe_origin_is_rejected(): void
    {
        foreach (['http://downloads.example.com', 'https://user@example.com', 'https://example.com/path', 'https://example.com?x=1', 'https://example.com#x'] as $origin) {
            config(['media.download_origin' => $origin]);
            try {
                app(MediaDownloadUrl::class)->signed('shoots/1/a.zip', 600);
                $this->fail('Unsafe origin accepted: '.$origin);
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('HTTPS origin', $exception->getMessage());
            }
        }
    }
}
