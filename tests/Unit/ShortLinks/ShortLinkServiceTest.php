<?php

namespace Tests\Unit\ShortLinks;

use App\Models\Shoot;
use App\Models\ShootShareLink;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\Payments\PublicPaymentAccessTokenService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootShareLinkService;
use App\Services\ShortLinks\ShortLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShortLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reuses_one_code_per_iguide_shoot(): void
    {
        $shoot = Shoot::factory()->create();
        $service = app(ShortLinkService::class);

        $first = $service->remember(ShortLink::TYPE_IGUIDE_OFFLINE_VIEWER, ShortLink::TARGET_SHOOT, (int) $shoot->id);
        $second = $service->remember(ShortLink::TYPE_IGUIDE_OFFLINE_VIEWER, ShortLink::TARGET_SHOOT, (int) $shoot->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShortLink::query()->count());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{10}$/', $first->code);
    }

    #[Test]
    public function it_builds_a_path_preserving_viewer_url(): void
    {
        $shoot = Shoot::factory()->create();
        $service = app(ShortLinkService::class);
        $link = $service->remember(ShortLink::TYPE_IGUIDE_OFFLINE_VIEWER, ShortLink::TARGET_SHOOT, (int) $shoot->id);

        $url = $service->url($link, 'tour/index.html');

        $this->assertSame('/api/g/'.$link->code.'/tour/index.html', parse_url($url, PHP_URL_PATH));
    }

    #[Test]
    public function it_does_not_shorten_disabled_types(): void
    {
        Config::set('short_links.types.share_download', false);
        $service = app(ShortLinkService::class);

        $this->assertFalse($service->enabled(ShortLink::TYPE_SHARE_DOWNLOAD));
        $this->assertSame(
            'https://example.test/share/original',
            $service->maybeShorten(
                ShortLink::TYPE_SHARE_DOWNLOAD,
                ShortLink::TARGET_SHARE_LINK,
                99,
                'https://example.test/share/original'
            )
        );
        $this->assertSame(0, ShortLink::query()->count());
    }

    #[Test]
    public function a_saved_settings_row_overrides_config_defaults(): void
    {
        Config::set('short_links.types.iguide_offline_viewer', true);
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            'key' => 'short_links',
            'type' => 'json',
            'value' => json_encode([
                'enabled' => true,
                'types' => ['iguide_offline_viewer' => false],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(ShortLinkService::class)->enabled(ShortLink::TYPE_IGUIDE_OFFLINE_VIEWER));
    }

    #[Test]
    public function share_links_use_the_short_url_only_when_enabled(): void
    {
        Config::set('app.frontend_url', 'https://repro.test');
        $user = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $link = ShootShareLink::create([
            'shoot_id' => $shoot->id,
            'created_by' => $user->id,
            'share_url' => 'https://repro.test/share/pending',
            'media_stage' => 'raw',
            'public_token' => 'share-token-abcdefghijklmnop',
        ]);
        $shareService = app(ShootShareLinkService::class);

        Config::set('short_links.types.share_download', false);
        $this->assertSame(
            'https://repro.test/share/share-token-abcdefghijklmnop',
            $shareService->buildPublicShareUrl($link)
        );

        Config::set('short_links.types.share_download', true);
        $shortUrl = $shareService->buildPublicShareUrl($link->fresh());
        $this->assertMatchesRegularExpression('#/api/g/[A-Za-z0-9]{8,12}$#', (string) parse_url($shortUrl, PHP_URL_PATH));
    }

    #[Test]
    public function media_zip_and_payment_links_use_the_short_url_only_when_enabled(): void
    {
        $shoot = Shoot::factory()->create();
        Config::set('short_links.types.media_zip', false);
        Config::set('short_links.types.payment', false);

        $zipUrl = app(ShootMediaArchiveService::class)->buildPublicDownloadUrl($shoot, 'edited', 'small');
        $this->assertStringContainsString('/download/media?url=', $zipUrl);

        $paymentUrl = app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot);
        $this->assertStringContainsString('/payment/', $paymentUrl);

        Config::set('short_links.types.media_zip', true);
        Config::set('short_links.types.payment', true);

        $shortZip = app(ShootMediaArchiveService::class)->buildPublicDownloadUrl($shoot, 'edited', 'small');
        $shortPayment = app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot);
        $this->assertMatchesRegularExpression('#/api/g/[A-Za-z0-9]{8,12}$#', (string) parse_url($shortZip, PHP_URL_PATH));
        $this->assertMatchesRegularExpression('#/api/g/[A-Za-z0-9]{8,12}$#', (string) parse_url($shortPayment, PHP_URL_PATH));
    }
}
